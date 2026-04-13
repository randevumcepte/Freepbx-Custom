const WebSocket = require("ws");
const fs = require("fs");

const wss = new WebSocket.Server({ port: 9091 });

wss.on("connection", function connection(ws) {
    console.log("WebSocket bağlantısı alındı!");

    // **WebSocket'i BINARY veri almak için yapılandır**
    ws.binaryType = "nodebuffer"; 

    let fileStream = fs.createWriteStream("call_audio.raw");

    ws.on("message", function incoming(data, isBinary) {
        if (isBinary) {
            console.log("Binary ses verisi alındı, boyut:", data.length);
            fileStream.write(data);
        } else {
            console.warn("Beklenmeyen text verisi alındı, işlenmedi!");
        }
	 fileStream.write(data);
    });

    ws.on("close", function close() {
        console.log("Bağlantı kapandı, WAV dosyası oluşturuluyor...");
        fileStream.end(() => {
            addWavHeader("call_audio.raw", "call_audio.wav");
        });
    });

    ws.on("error", function error(err) {
        console.error("WebSocket Hatası:", err);
    });
});

// WAV header ekleyerek kaydetme fonksiyonu
function addWavHeader(inputFile, outputFile) {
    try {
        const audioData = fs.readFileSync(inputFile);
        const sampleRate = 8000;  // Asterisk için genellikle 8000 Hz
        const numChannels = 1;
        const bitDepth = 16;  // PCM formatında 16-bit

        const header = Buffer.alloc(44);
        header.write("RIFF", 0);
        header.writeUInt32LE(audioData.length + 36, 4);
        header.write("WAVE", 8);
        header.write("fmt ", 12);
        header.writeUInt32LE(16, 16);
        header.writeUInt16LE(1, 20);
        header.writeUInt16LE(numChannels, 22);
        header.writeUInt32LE(sampleRate, 24);
        header.writeUInt32LE(sampleRate * numChannels * (bitDepth / 8), 28);
        header.writeUInt16LE(numChannels * (bitDepth / 8), 32);
        header.writeUInt16LE(bitDepth, 34);
        header.write("data", 36);
        header.writeUInt32LE(audioData.length, 40);

        const wavFile = Buffer.concat([header, audioData]);
        fs.writeFileSync(outputFile, wavFile);

        console.log(`WAV dosyası oluşturuldu: ${outputFile}`);
    } catch (error) {
        console.error("WAV dosyası oluşturulurken hata oluştu:", error);
    }
}

