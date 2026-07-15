'use strict';
const path = require('path');
const { execFile } = require('child_process');
const config = require('./config');

// Metni sese cevirir; Asterisk'in ARI Playback ile calabilecegi WAV uretir.
// Donen: ARI media URI ("sound:/var/spool/asterisk/monitor/rai-...").
// Motorlar: edge (UCRETSIZ), piper (UCRETSIZ offline), polly (ucretli, eski).

function run(cmd, args, cb) { execFile(cmd, args, { timeout: 20000, maxBuffer: 1024 * 1024 * 8 }, cb); }

function speak(text, callId) {
  const base = path.join(config.tts.outDir, `rai-${callId}-${process.pid}-${Math.round(process.hrtime()[1] / 1000)}`);
  return new Promise((resolve, reject) => {
    if (config.tts.engine === 'edge') {
      // Edge-TTS (ucretsiz) -> mp3, sonra Asterisk icin 8kHz mono wav (ffmpeg gerekli).
      run('edge-tts', ['--voice', config.tts.edgeVoice, '--text', text, '--write-media', `${base}.mp3`], (err) => {
        if (err) return reject(err);
        run('ffmpeg', ['-y', '-loglevel', 'error', '-i', `${base}.mp3`, '-ar', '8000', '-ac', '1', `${base}.wav`],
          (e2) => e2 ? reject(e2) : resolve(`sound:${base}`));
      });
    } else if (config.tts.engine === 'piper') {
      // Piper (ucretsiz, offline) -> dogrudan wav (genelde 22.05kHz; Asterisk resample eder).
      run('sh', ['-c', `printf '%s' ${shq(text)} | piper --model ${shq(config.tts.piperModel)} --output_file ${shq(base + '.wav')}`],
        (err) => err ? reject(err) : resolve(`sound:${base}`));
    } else {
      // polly (ucretli, mevcut script ile ayni)
      run('node', ['/opt/aws-nodejs/polly.js', `--mp3=${base}.mp3`, `--text=${text}`, `--wav=${base}`],
        (err) => err ? reject(err) : resolve(`sound:${base}`));
    }
  });
}

// sh icin guvenli tirnak
function shq(s) { return "'" + String(s).replace(/'/g, "'\\''") + "'"; }

module.exports = { speak };
