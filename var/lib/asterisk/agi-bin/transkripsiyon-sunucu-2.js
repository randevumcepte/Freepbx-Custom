const WebSocket = require("ws");
const speech = require("@google-cloud/speech");
const textToSpeech = require("@google-cloud/text-to-speech");
const fs = require("fs");
const axios = require("axios");
const keyFilename = "/var/lib/asterisk/agi-bin/sesli-yanit/gcp-key-nlp.json"; 
// Google Cloud istemcilerini başlat
const sttClient = new speech.SpeechClient({ keyFilename });
const ttsClient = new textToSpeech.TextToSpeechClient({ keyFilename });

// WebSocket sunucusunu başlat
const wss = new WebSocket.Server({ port: 9090 });

wss.on("connection", function connection(ws) {
    console.log("🔹 Yeni WebSocket bağlantısı alındı.");

    let audioBuffer = [];
    let silenceTimer = null;

    ws.on("message", async function incoming(message) {
        const data = JSON.parse(message);

        if (data.event === "audio_chunk") {
            // Gelen sesi buffer'a ekle
            audioBuffer.push(Buffer.from(data.audio, "base64"));

            // Sessizlik zamanlayıcısını sıfırla
            if (silenceTimer) {
                clearTimeout(silenceTimer);
            }

            // 1 saniye boyunca yeni ses gelmezse konuşma bitmiş say
            silenceTimer = setTimeout(async () => {
                if (audioBuffer.length > 0) {
                    console.log("Sessizlik algılandı, Google STT'ye gönderiliyor...");

                    const audioData = Buffer.concat(audioBuffer);
                    const transcription = await speechToText(audioData);
                    console.log("STT Çıktısı:", transcription);

                    if (transcription) {
                        const dialogflowResponse = await sendToDialogflow(transcription);
                        console.log("Dialogflow Yanıtı:", dialogflowResponse);

                        return dialogflowResponse;
                    }

                    // Buffer'ı temizle
                    audioBuffer = [];
                }
            }, 1000); // 1 saniye sessizlik süresi
        }

        if (data.event === "end_audio") {
            console.log("Ses yayını tamamlandı.");
            ws.close();
        }
    });

    ws.on("close", () => {
        console.log("WebSocket bağlantısı kapandı.");
    });
});

/**
 * Google Speech-to-Text (STT) - Ses kaydını metne çevirir.
 */
async function speechToText(audioBuffer) {
    const request = {
        audio: { content: audioBuffer.toString("base64") },
        config: {
            encoding: "LINEAR16",
            sampleRateHertz: 8000, // Telefon çağrıları için
            languageCode: "tr-TR",
        },
    };

    try {
        const [response] = await sttClient.recognize(request);
        return response.results.map(r => r.alternatives[0].transcript).join(" ");
    } catch (error) {
        console.error("Google STT Hatası:", error);
        return null;
    }
}

/**
 * Google Dialogflow - Metni işleyip yanıt döndürür.
 */
async function sendToDialogflow(text) {
    const url = `https://europe-west3-dialogflow.googleapis.com/v3/projects/neon-emitter-410111/locations/europe-west3/agents/7ec85ff9-07c9-4fe9-ae85-c907d72cd763/sessions/123456:detectIntent`;

    try {
        const token = await getGoogleAccessToken();
        const response = await axios.post(url, {
            queryInput: { text: { text }, languageCode: "tr" },
        }, {
            headers: { Authorization: `Bearer ${token}`, "Content-Type": "application/json" }
        });

        if (response.data.queryResult && response.data.queryResult.responseMessages) {
            return response.data.queryResult.responseMessages.map(m => m.text.text[0]).join(" ");
        }
        return "Anlayamadım.";
    } catch (error) {
        console.error("Dialogflow Hatası:", error);
        return "Bir hata oluştu.";
    }
}


/**
 * Google API için Access Token alır (Dialogflow için gereklidir)
 */
async function getGoogleAccessToken() {
    const { GoogleAuth } = require("google-auth-library");
    const auth = new GoogleAuth({ keyFilename , scopes: "https://www.googleapis.com/auth/cloud-platform" });
    const client = await auth.getClient();
    const token = await client.getAccessToken();
    return token.token;
}

