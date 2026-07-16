'use strict';
const path = require('path');
const { execFile } = require('child_process');
const config = require('./config');

// Metni sese cevirir; Asterisk'in ARI Playback ile calabilecegi WAV uretir.
// Donen: ARI media URI ("sound:/var/spool/asterisk/monitor/rai-...").
// Motorlar: edge (UCRETSIZ), piper (UCRETSIZ offline), polly (ucretli, eski).

function run(cmd, args, cb) { execFile(cmd, args, { timeout: 20000, maxBuffer: 1024 * 1024 * 8 }, cb); }

// Acik Llama modelleri Turkce diakritigi bazen dusuruyor ("baska"). TTS'ten ONCE alan
// sozlugu ile duzelt (sesin dogru okunmasi icin). Sadece ASCII hali sozlukte olan kelimeler.
const TR_FIX = {
  baska: 'başka', icin: 'için', gunu: 'günü', gunler: 'günler', gun: 'gün', degil: 'değil',
  saglikli: 'sağlıklı', saglik: 'sağlık', ogleden: 'öğleden', sali: 'salı', carsamba: 'çarşamba',
  persembe: 'perşembe', sac: 'saç', manikur: 'manikür', tesekkur: 'teşekkür', tesekkurler: 'teşekkürler',
  lutfen: 'lütfen', operatore: 'operatöre', operator: 'operatör', guncelle: 'güncelle',
  guncelleme: 'güncelleme', guncelleyelim: 'güncelleyelim', olustur: 'oluştur',
  olusturuyorum: 'oluşturuyorum', olusturuldu: 'oluşturuldu', olusturmak: 'oluşturmak',
  olusturayim: 'oluşturayım', musteri: 'müşteri', yapalim: 'yapalım', gorusme: 'görüşme',
  gorusuruz: 'görüşürüz', hosgeldiniz: 'hoş geldiniz', hos: 'hoş', gunaydin: 'günaydın',
  onayliyor: 'onaylıyor', onayliyormusunuz: 'onaylıyor musunuz', hangi: 'hangi',
  istiyorsunuz: 'istiyorsunuz', aktariyorum: 'aktarıyorum', bekleyin: 'bekleyin',
};
function fixTurkce(s) {
  return String(s || '').replace(/[A-Za-zçğıöşüÇĞİÖŞÜ]+/g, (w) => {
    const t = TR_FIX[w.toLowerCase()];
    if (!t) return w;
    return w[0] === w[0].toUpperCase() ? t.charAt(0).toLocaleUpperCase('tr') + t.slice(1) : t;
  });
}

function speak(text, callId) {
  text = fixTurkce(text);
  const base = path.join(config.tts.outDir, `rai-${callId}-${process.pid}-${Math.round(process.hrtime()[1] / 1000)}`);
  return new Promise((resolve, reject) => {
    if (config.tts.engine === 'edge') {
      // Edge-TTS (ucretsiz) -> mp3, sonra Asterisk icin 8kHz mono wav (ffmpeg gerekli).
      // rate/pitch ile duz sesi biraz canlandir (Edge Turkce'de emotion "style" yok, sadece bunlar).
      const edgeArgs = ['--voice', config.tts.edgeVoice, '--text', text, '--write-media', `${base}.mp3`];
      if (config.tts.edgeRate) edgeArgs.push('--rate', config.tts.edgeRate);
      if (config.tts.edgePitch) edgeArgs.push('--pitch', config.tts.edgePitch);
      run('edge-tts', edgeArgs, (err) => {
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
