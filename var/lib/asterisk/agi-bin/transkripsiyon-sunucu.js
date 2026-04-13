const WebSocket = require('ws');
const speech = require('@google-cloud/speech');
const axios = require('axios');
const auth = require('google-auth-library'); // Google Auth için gerekli

const wss = new WebSocket.Server({ port: 9090 });
const keyFilename = "/var/lib/asterisk/agi-bin/sesli-yanit/gcp-key-nlp.json";
const client = new speech.SpeechClient({ keyFilename });

async function getAccessToken() {
    const client = new auth.GoogleAuth({
        keyFilename,
        scopes: ["https://www.googleapis.com/auth/dialogflow"]
    });
    const token = await client.getAccessToken();
    return token.token;
}

async function sendToDialogflow(transcription, sessionId) {
    const url = `https://europe-west3-dialogflow.googleapis.com/v3/projects/neon-emitter-410111/locations/europe-west3/agents/7ec85ff9-07c9-4fe9-ae85-c907d72cd763/sessions/${sessionId}:detectIntent`;
    
    const token = await getAccessToken();

    const requestBody = {
        queryInput: {
            text: { text: transcription },
            languageCode: "tr",
        }
    };

    try {
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

wss.on('connection', (ws) => {
    console.log("AGI betiği bağlandı.");
    const sessionId = Math.random().toString(36).substring(7); // Rastgele oturum kimliği

    const recognizeStream = client
        .streamingRecognize({
            config: {
                encoding: 'LINEAR16',
                sampleRateHertz: 8000,
                languageCode: 'tr-TR',
            },
            interimResults: false,
        })
        .on('data', async (data) => {
            const transcript = data.results[0]?.alternatives[0]?.transcript || "";
            console.log("STT Çıktısı:", transcript);

            if (transcript) {
                const dialogflowResponse = await sendToDialogflow(transcript, sessionId);
                console.log("Dialogflow Yanıtı:", dialogflowResponse);
                ws.send(JSON.stringify({ response: dialogflowResponse }));
            }
        })
        .on('error', (err) => console.error("STT Hatası:", err));

    ws.on('message', (message) => {
        const data = JSON.parse(message);

        if (data.event === "audio_chunk") {
            recognizeStream.write(Buffer.from(data.audio, 'base64'));
        }

        if (data.event === "end_audio") {
            recognizeStream.end();
        }
    });

    ws.on('close', () => {
        console.log("Bağlantı kapandı.");
        recognizeStream.end();
    });
});
