#!/usr/bin/env node
// /var/lib/asterisk/agi-bin/transcribe-server.js - ExternalMedia/ARI VERSİYONU

const dgram = require('dgram');
const fs = require('fs');
const speech = require('@google-cloud/speech');

// Google Cloud Auth
const client = new speech.SpeechClient({
    keyFilename: '/var/lib/asterisk/agi-bin/sesli-yanit/gcp-key.json'
});

const UDP_PORT = 5001;
const HTTP_PORT = 5002;
const udpServer = dgram.createSocket('udp4');
const streams = new Map();
const channelMap = new Map();

console.log('🎤 ExternalMedia/ARI STT Sunucu başlatılıyor...');
console.log(`📡 UDP port ${UDP_PORT} dinleniyor...`);
console.log(`📡 HTTP port ${HTTP_PORT} dinleniyor...`);

// UDP - RTP ses akışı
udpServer.on('message', (rtpPacket, remote) => {
    try {
        const audioData = rtpPacket.slice(12);
        const channelId = `${remote.address}:${remote.port}`;
        
        if (!streams.has(channelId)) {
            console.log(`\n🔌 Yeni bağlantı: ${channelId}`);
            
            const recognizeStream = client.streamingRecognize({
                config: {
                    encoding: 'LINEAR16',
                    sampleRateHertz: 8000,
                    languageCode: 'tr-TR',
                    model: 'phone_call',
                    useEnhanced: true,
                    singleUtterance: true,
                    enableAutomaticPunctuation: true,
                },
                interimResults: false,
            });
            
            recognizeStream.on('data', (response) => {
                const result = response.results[0];
                if (result?.isFinal) {
                    const transcript = result.alternatives[0].transcript;
                    const uniqueId = channelMap.get(channelId);
                    
                    console.log(`\n✅ Transkript: "${transcript}"`);
                    
                    if (uniqueId) {
                        const transcriptFile = `/tmp/transcript_${uniqueId}.txt`;
                        fs.writeFileSync(transcriptFile, transcript);
                        console.log(`💾 Kaydedildi: ${transcriptFile}`);
                    }
                    
                    setTimeout(() => {
                        streams.delete(channelId);
                        channelMap.delete(channelId);
                        recognizeStream.end();
                    }, 1000);
                }
            });
            
            recognizeStream.on('error', (err) => {
                console.error('STT Hatası:', err);
                streams.delete(channelId);
                channelMap.delete(channelId);
            });
            
            streams.set(channelId, recognizeStream);
        }
        
        const stream = streams.get(channelId);
        if (stream) stream.write(audioData);
        
    } catch (err) {
        console.error('İşlem hatası:', err);
    }
});

// HTTP - AGI'den UNIQUEID al
const http = require('http');
const httpServer = http.createServer((req, res) => {
    if (req.method === 'POST' && req.url === '/register') {
        let body = '';
        req.on('data', chunk => body += chunk);
        req.on('end', () => {
            try {
                const data = JSON.parse(body);
                const { channelId, uniqueId } = data;
                channelMap.set(channelId, uniqueId);
                console.log(`📝 Kayıt: ${channelId} -> ${uniqueId}`);
                res.writeHead(200);
                res.end('OK');
            } catch (e) {
                res.writeHead(400);
                res.end('Invalid JSON');
            }
        });
    } else {
        res.writeHead(404);
        res.end();
    }
});

udpServer.bind(UDP_PORT, '127.0.0.1');
httpServer.listen(HTTP_PORT, '127.0.0.1', () => {
    console.log('✅ HTTP sunucu hazır');
});

process.on('SIGINT', () => {
    console.log('\n👋 Kapatılıyor...');
    streams.forEach(s => s.end());
    udpServer.close();
    httpServer.close();
    process.exit(0);
});