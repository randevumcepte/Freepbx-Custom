const ari = require('ari-client');
const WebSocket = require('ws');
const speech = require('@google-cloud/speech');
const dialogflow = require('@google-cloud/dialogflow');
const { TextToSpeechClient } = require('@google-cloud/text-to-speech');
const fs = require('fs');
const util = require('util');
const keyFilename = "/var/lib/asterisk/agi-bin/sesli-yanit/gcp-key-nlp.json"; 

// Google API ayarları
const speechClient = new speech.SpeechClient({ keyFilename });
const dialogflowClient = new dialogflow.SessionsClient({ keyFilename });
const ttsClient = new TextToSpeechClient({ keyFilename });

// WebSocket sunucusu başlat
const wss = new WebSocket.Server({ port: 8081 });

// ARI’ye bağlan
ari.connect('http://localhost:8088', 'dialogflow_user', 'Rndvmcpt#$35', async (err, client) => {
    if (err) throw err;

    console.log('ARI bağlantısı başarılı!');

    client.on('StasisStart', async (event, channel) => {
        console.log(`Çağrı alındı: ${channel.id}`);

        // Gelen sesi stream'e bağla
        const bridge = client.Bridge();
        await bridge.create({ type: 'mixing' });
        await channel.answer();
        await bridge.addChannel({ channel: channel.id });

        // RTP Stream başlat
        const ws = new WebSocket('ws://localhost:8081');
        channel.on('ChannelTalkingStarted', () => {
            console.log('Konuşma başladı!');
            ws.send(JSON.stringify({ action: 'start', channelId: channel.id }));
        });

        // Ses sinyallerini WebSocket'e aktar
        channel.on('ChannelTalkingFinished', async () => {
            console.log('Konuşma bitti!');
            ws.send(JSON.stringify({ action: 'stop', channelId: channel.id }));

            // Google STT ile sesi yazıya çevir
            const [response] = await speechClient.recognize({
                config: { encoding: 'LINEAR16', languageCode: 'tr-TR' },
                audio: { content: fs.readFileSync('audio.raw').toString('base64') }
            });

            const text = response.results.map(result => result.alternatives[0].transcript).join(' ');
            console.log('Google STT Sonuç:', text);

            // Dialogflow'a mesaj gönder
            const sessionPath = dialogflowClient.projectAgentSessionPath('your-project-id', channel.id);
            const dialogflowResponse = await dialogflowClient.detectIntent({
                session: sessionPath,
                queryInput: { text: { text }, languageCode: 'tr'  }
            });

            const reply = dialogflowResponse[0].queryResult.fulfillmentText;
            console.log('Dialogflow Yanıt:', reply);

            // Yanıtı Polly TTS ile sese çevir
            const [ttsResponse] = await ttsClient.synthesizeSpeech({
                input: { text: reply },
                voice: { languageCode: 'tr-TR', ssmlGender: 'FEMALE' },
                audioConfig: { audioEncoding: 'MP3' }
            });

            fs.writeFileSync('response.mp3', ttsResponse.audioContent);

            // Yanıtı arayana çal
            channel.play({ media: 'sound:response' });
        });

        // Çağrı bitince temizle
        channel.on('StasisEnd', () => {
            console.log('Çağrı sonlandı.');
            bridge.destroy();
        });
    });

    client.start('transcribe-and-dialog-rt');
});

