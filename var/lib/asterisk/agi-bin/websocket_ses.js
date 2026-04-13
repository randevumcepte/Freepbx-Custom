const WebSocket = require("ws");
const fs = require("fs");

const server = new WebSocket.Server({ port: 9092 });

server.on("connection", (ws) => {
  console.log("Bağlantı alındı");

  const fileStream = fs.createWriteStream("/var/spool/asterisk/monitor/audiows.raw"); // Geçici ham ses dosyası
  ws.on("message", (data) => {
    console.log(`Alınan veri boyutu: ${data.length} byte`);
    fileStream.write(data);
  });

  ws.on("close", () => {
    console.log("Bağlantı kapandı, dosya kaydedildi.");
    fileStream.end();
  });
});

