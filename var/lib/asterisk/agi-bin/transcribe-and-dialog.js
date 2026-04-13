const fs = require("fs");
const { SpeechClient } = require("@google-cloud/speech");
const { GoogleAuth } = require("google-auth-library");
const axios = require("axios");
const record = require("node-record-lpcm16");
const mic = require('mic');
const { spawn } = require("child_process");
// **Google Cloud Kimlik Doğrulama**
const keyFilename = "/var/lib/asterisk/agi-bin/sesli-yanit/gcp-key-nlp.json"; 

const auth = new GoogleAuth({
    keyFilename,
    scopes: ["https://www.googleapis.com/auth/cloud-platform"]
});

// **Google Cloud Speech Client**
const client = new SpeechClient({ keyFilename });

// **1. Ses Dosyasını Transkribe Et**
/*async function transcribeAudio(filePath) {
    const audioBytes = fs.readFileSync(filePath).toString("base64");

    const request = {
        audio: { content: audioBytes },
        config: {
            encoding: "LINEAR16",
            sampleRateHertz: 8000,
            languageCode: "tr-TR",
        },
    };

    try {
        const [response] = await client.recognize(request);
        const transcription = response.results
            .map(result => result.alternatives[0].transcript)
            .join("\n");

       // console.log("Transkripsiyon:", transcription);
        return transcription;
    } catch (error) {
        console.error("Transkripsiyon Hatası:", error.message);
        return null;
    }
}*/

// **Sanal Mikrofon Kullanarak Gerçek Zamanlı Transkripsiyon Başlat**
async function transcribeStream() {
    console.log("Mikrofon başlatılıyor...");

    const request = {
        config: {
            encoding: "LINEAR16",
            sampleRateHertz: 8000,
            languageCode: "tr-TR"
        },
        interimResults: true
    };

    const recognizeStream = client.streamingRecognize(request)
        .on("data", async (data) => {
            const transcription = data.results[0]?.alternatives[0]?.transcript || "";
            const isFinal = data.results[0]?.isFinal || false;

            console.log(`Transkript: ${transcription} (Final: ${isFinal})`);

            if (isFinal && transcription.length > 3) {
                const sessionId = `session-${Date.now()}`;
                const dialogResponse = await sendToDialogflow(transcription);
                console.log(`SET VARIABLE SISTEM_YANITI "${dialogResponse}"`);
            }
        })
        .on("error", (err) => {
            console.error("Transkripsiyon Hatası:", err);
        });

    // **PulseAudio Kullanarak Sanal Mikrofonu Kullan**
    const micSource = "VirtualMic.monitor"; // Sanal mikrofonun adı

    const arecord = spawn("parecord", [
        "--device=" + micSource,
        "--rate=8000",
        "--format=s16le",
        "--channels=1",
        "--file-format=wav",
        "-"
    ]);

    arecord.stdout.pipe(recognizeStream);
    arecord.stderr.on("data", (data) => console.error(`arecord Hatası: ${data}`));

    console.log("Mikrofon başlatıldı, konuşmaya başlayabilirsiniz...");
}	

// **2. Dialogflow'a Gönder**

async function sendToDialogflow(transcription, sessionId) {
    const url = `https://europe-west3-dialogflow.googleapis.com/v3/projects/neon-emitter-410111/locations/europe-west3/agents/7ec85ff9-07c9-4fe9-ae85-c907d72cd763/sessions/${sessionId}:detectIntent`;

    const token = await auth.getAccessToken();

    const requestBody = {
        queryInput: {
            text: {
                text: transcription
            },
            languageCode: "tr"
        }
    };

    try {
        const response = await axios.post(url, requestBody, {
            headers: {
                Authorization: `Bearer ${token}`,
                "Content-Type": "application/json"
            }
        });

        //console.log("Dialogflow Yanıtı:", JSON.stringify(response.data, null, 2)); // **Yanıtı detaylı gör**

        // **Yanıtı Doğrula**
        let agentResponse = "";

        if (response.data.queryResult) {
            const queryResult = response.data.queryResult;

            // 1. **responseMessages içindeki text yanıtını al**
            if (queryResult.responseMessages && queryResult.responseMessages.length > 0) {
                for (const msg of queryResult.responseMessages) {
                    if (msg.text && msg.text.text && msg.text.text.length > 0) {
                        agentResponse +=" "+ msg.text.text[0]; // **İlk mesajı al**
                        
                    }
                }
            }
        }

        //console.log("Agent Yanıtı:", agentResponse);
        return agentResponse;
    } catch (error) {
        console.error("Dialogflow Hatası:", error.response ? error.response.data : error.message);
        return "Üzgünüm, anlayamadım.";
    }
}


// **3. Ana İşlem (Asterisk'ten Gelen)**
/*async function main() {
    const filePath = process.argv[2]; // Asterisk'ten gelen ses dosyası yolu
    if (!filePath) {
        console.error("Hata: Dosya yolu eksik.");
        return;
    }

    //console.log("Ses işleniyor:", filePath);
    const transcription = await transcribeAudio(filePath);
    if (!transcription) return;

    //  Dinamik session ID oluştur
    const sessionId = `session-${Date.now()}`;
    const responseText = await sendToDialogflow(transcription, sessionId);

    //  Yanıtı Asterisk'e kaydet
    //fs.writeFileSync("/var/lib/asterisk/sounds/response.txt", responseText);

    //  Asterisk'e değişken döndür
    console.log(`SET VARIABLE SISTEM_YANITI "${responseText}"`);

    process.exit(0);
	//console.log(JSON.stringify({ success: true, transcription }));
    //process.stdout.write(`SET VARIABLE SISTEM_YANITI tts\n`);
}

//Çalıştır
main();*/
transcribeStream()
