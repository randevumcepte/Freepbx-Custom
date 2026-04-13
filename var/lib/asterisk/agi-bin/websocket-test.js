const WebSocket = require('ws');
const wss = new WebSocket.Server({ port: 5000 });

console.log("WebSocket server listening on port 5000...");

wss.on('connection', (ws) => {
    console.log('Client connected');

    ws.on('message', (message) => {
        console.log(`Received: ${message}`);
        ws.send(`Echo: ${message}`);  // Test için geri gönderme
    });

    ws.on('close', () => {
        console.log('Client disconnected');
    });
});
