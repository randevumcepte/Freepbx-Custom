const WebSocket = require('ws');
const speech = require('@google-cloud/speech');
const axios = require('axios');
const auth = require("google-auth-library");

const wss = new WebSocket.Server({ port: 9090 });
const keyFilename = "/var/lib/asterisk/agi-bin/sesli-yanit/gcp-key-nlp.json";

const client = new speech.SpeechClient({ keyFilename });

console.log("WebSocket Sunucusu 9090'da başlatıldı...");

wss.on('connection', function connection(ws) {
    console.log("Yeni bağlantı alındı.");
     ws.send("[SERVER]: Yeni bağlantı alındı.");

    let audioBuffer = [];
    let silenceTimer = null;
    const SILENCE_TIMEOUT = 3000;

    ws.on('message', function incoming(message) {
        if (message === 'END') {
            processAudio();
            return;
        }
        const bufferData = Buffer.isBuffer(message) ? message : Buffer.from(message);
        audioBuffer.push(bufferData);

        if (silenceTimer) clearTimeout(silenceTimer);
        silenceTimer = setTimeout(() => {
            console.log("Sessizlik algılandı, işleme başlıyor...");
	     ws.send("[SERVER]: Sessizlik algılandı, işleme başlıyor...");
            processAudio();
        }, SILENCE_TIMEOUT);
    });

    ws.on('close', () => {
        console.log("WebSocket bağlantısı kapandı.");
	 ws.send("[SERVER]: WebSocket bağlantısı kapandı.");
    });

    ws.on('error', (err) => {
        console.error("WebSocket Hatası:", err);
	     ws.send("[SERVER]: WebSocket Hatası : ${err}");
	  
    });

    async function processAudio() {
        if (audioBuffer.length === 0) return;

        const audioBytes = Buffer.concat(audioBuffer);
        audioBuffer = [];

        const request = {
            config: {
                encoding: 'LINEAR16',
                sampleRateHertz: 8000,
                languageCode: 'tr-TR',
            },
            audio: {
                content: audioBytes.toString('base64'),
            },
        };

        try {
            const [response] = await client.recognize(request);
            const transcription = response.results.map(r => r.alternatives[0].transcript).join(' ');
            console.log("Transkripsiyon:", transcription);
            ws.send(`[SERVER]: Transkripsiyon: ${transcription}`);
	    const sessionId = `session-${Date.now()}`;
            if (transcription) {
                const dialogResponse = await sendToDialogflow(transcription, sessionId);
                ws.send(dialogResponse);
            }
        } catch (error) {
            console.error("Google STT Hatası:", error);
            ws.send(`[SERVER]: Google STT Hatası: ${error.message}`);
        }
    }
});

async function sendToDialogflow(transcription, sessionId) {
    const url = `https://europe-west3-dialogflow.googleapis.com/v3/projects/neon-emitter-410111/locations/europe-west3/agents/7ec85ff9-07c9-4fe9-ae85-c907d72cd763/sessions/${sessionId}:detectIntent`;

    try {
        const token = await auth.getAccessToken();

        const requestBody = {
            queryInput: {
                text: {
                    text: transcription
                },
                languageCode: "tr"
            }
        };

        const response = await axios.post(url, requestBody, {
            headers: {
                Authorization: `Bearer ${token}`,
                "Content-Type": "application/json"
            }
        });

        let agentResponse = "";

        if (response.data.queryResult) {
            const queryResult = response.data.queryResult;
            if (queryResult.responseMessages && queryResult.responseMessages.length > 0) {
                for (const msg of queryResult.responseMessages) {
                    if (msg.text && msg.text.text && msg.text.text.length > 0) {
                        agentResponse += msg.text.text[0];
                    }
                }
            }
        }

        return agentResponse;
    } catch (error) {
        console.error("Dialogflow Hatası:", error.response ? error.response.data : error.message);
        return "Üzgünüm, anlayamadım.";
    }
}

