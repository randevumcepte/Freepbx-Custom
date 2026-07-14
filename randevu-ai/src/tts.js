'use strict';
const path = require('path');
const { execFile } = require('child_process');
const config = require('./config');

// Metni sese cevirir ve Asterisk'in ARI Playback ile calabilecegi bir ses dosyasi uretir.
// Donen deger: ARI media URI (ornek "sound:/var/spool/asterisk/monitor/rai-<id>").
//
// Mevcut sistemle uyum icin varsayilan olarak ayni Polly script'ini (/opt/aws-nodejs/polly.js)
// kullaniyoruz — boylece TTS sesi/kalitesi degismiyor. Google TTS'e gecmek icin engine=google.
function speak(text, callId) {
  const base = path.join(config.tts.outDir, `rai-${callId}-${Date.now()}`);
  return new Promise((resolve, reject) => {
    if (config.tts.engine === 'polly') {
      // polly.js: --mp3=<f>.mp3 --text=<...> --wav=<f>  (mevcut kullanimla ayni)
      execFile('node', ['/opt/aws-nodejs/polly.js', `--mp3=${base}.mp3`, `--text=${text}`, `--wav=${base}`],
        { timeout: 15000 }, (err) => err ? reject(err) : resolve(`sound:${base}`));
    } else {
      // TODO: Google Cloud TTS ile <base>.wav uret (LINEAR16 8kHz), sonra resolve(`sound:${base}`).
      reject(new Error('google TTS henuz uygulanmadi'));
    }
  });
}

module.exports = { speak };
