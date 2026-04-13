const fs = require("fs");
const { SpeechClient } = require("@google-cloud/speech");

// GCP Speech client
const client = new SpeechClient({
  keyFilename: "/var/lib/asterisk/agi-bin/sesli-yanit/gcp-key.json"
});

async function main(filePath) {
  const encoding = "LINEAR16";
  const sampleRateHertz = 8000;
  const languageCode = "tr-TR";

  const request = {
    config: {
      encoding: encoding,
      sampleRateHertz: sampleRateHertz,
      languageCode: languageCode,
      enableAutomaticPunctuation: true,
      speechContexts: [
        {
          phrases: ["Saç Kesimi", "Fön", "Manikür", "Pedikür"], // senin özel kelimelerin
          boost: 15.0
        }
      ]
    },
    interimResults: true // Anlık sonuçlar gelsin
  };

  const recognizeStream = client
    .streamingRecognize(request)
    .on("error", (err) => console.error("Error:", err))
    .on("data", (data) => {
      if (data.results[0] && data.results[0].alternatives[0]) {
        console.log(
          JSON.stringify({
            transcription: data.results[0].alternatives[0].transcript,
            isFinal: data.results[0].isFinal,
            success:true,
          })
        );
      }
    });

  // Ses dosyasını okuyup stream’e gönder
  fs.createReadStream(filePath).pipe(recognizeStream);
}

// CLI’den çağırma
const filePath = process.argv[2];
main(filePath).catch(console.error);

