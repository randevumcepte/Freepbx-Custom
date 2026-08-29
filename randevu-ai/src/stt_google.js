'use strict';
/**
 * Google Cloud Speech STREAMING STT — gercek zamanli. PCM surekli akitilir; Google interim
 * sonuclar dondurur, dogal duraklamada isFinal yollar.
 *
 * stt.js SttStream ile AYNI sozlesme: new GoogleSttStream({onInterim,onFinal}) + write(pcm)
 * + end(). ari.js:_listen bunu fabrika ile secer. rtp.js 16kHz LINEAR16 (LE) PCM uretir.
 *
 * TASARIM: single_utterance KULLANMIYORUZ (baslangic sessizliginde erken kapatabiliyor).
 * Bunun yerine interimResults ile dinler; ILK DOLU isFinal sonucunda bitiririz (dogal
 * konusma-sonu). Hic konusma gelmezse guvenlik zamanlayicisi ile bos biter -> "duyamadim".
 */
const speech = require('@google-cloud/speech');
const config = require('./config');

const client = new speech.SpeechClient();

const MAX_MS = parseInt(process.env.VAD_MAX_MS || '12000', 10); // konusma gelmezse kapat
const SESSIZLIK_MS = 3000; // YEDEK: interim'den sonra Google isFinal HIC gelmezse kapat.
                           // Kisa tutunca Google'in gercek endpoint'inden ONCE kapatip sozu
                           // boluyordu (ikinci stream ayni sesi tekrar yaziyordu). isFinal asildir.

class GoogleSttStream {
  constructor({ onInterim, onFinal, phrases } = {}) {
    this.onInterim = onInterim || (() => {});
    this.onFinal = onFinal || (() => {});
    this.done = false;
    this.lastInterim = '';
    this._wroteAudio = false;
    this._sessizT = null;

    const cfg = {
      encoding: 'LINEAR16',
      sampleRateHertz: config.stt.sampleRateHertz || 16000,
      languageCode: config.stt.language || 'tr-TR',
      enableAutomaticPunctuation: true,
    };
    if (phrases && phrases.length) cfg.speechContexts = [{ phrases, boost: 15 }];

    // Guvenlik: hic konusma gelmezse (sadece sessizlik) MAX_MS sonra bos bitir.
    this._maxT = setTimeout(() => { console.error('[gstt] MAX_MS doldu, bos bitiriyorum'); this._finish(this.lastInterim, null); }, MAX_MS);

    try {
      this.stream = client
        .streamingRecognize({ config: cfg, interimResults: true, singleUtterance: false })
        .on('error', (e) => { console.error('[gstt] error:', e && e.message); this._finish('', e); })
        .on('end', () => { console.error('[gstt] stream end, yedek="' + this.lastInterim + '"'); this._finish(this.lastInterim, null); })
        .on('data', (data) => {
          const r = data.results && data.results[0];
          if (!r) return;
          const t = ((r.alternatives && r.alternatives[0] && r.alternatives[0].transcript) || '').trim();
          if (r.isFinal) {
            if (t) { console.error('[gstt] FINAL="' + t + '"'); this._finish(t, null); }
            // bos isFinal (sessizlik) -> yok say, dinlemeye devam
            return;
          }
          if (t) {
            this.lastInterim = t;
            this.onInterim(t); // barge-in
            // Konusma basladi. Google'in isFinal'ini (gercek endpoint) BEKLE — kendi
            // zamanlayicimla yarisip stream'i erken kapatmak sozu boluyor + ikinci stream
            // ayni sesi tekrar yaziyordu. Yedek: interim'den sonra UZUN sessizlikte kapat.
            if (this._sessizT) clearTimeout(this._sessizT);
            this._sessizT = setTimeout(() => { console.error('[gstt] uzun sessizlik, interim ile kapatiyorum="' + this.lastInterim + '"'); this._finish(this.lastInterim, null); }, SESSIZLIK_MS);
          }
        });
    } catch (e) {
      console.error('[gstt] kurulum hatasi:', e && e.message);
      setImmediate(() => this._finish('', e));
    }
  }

  write(pcm) {
    if (this.done || !this.stream) return;
    try {
      if (this.stream.writable) {
        this.stream.write(pcm);
        if (!this._wroteAudio) { this._wroteAudio = true; console.error('[gstt] ilk pcm yazildi (' + pcm.length + ' byte)'); }
      }
    } catch (_) {}
  }

  end() { this._finish(this.lastInterim, null); }

  _finish(text, err) {
    if (this.done) return;
    this.done = true;
    if (this._maxT) { clearTimeout(this._maxT); this._maxT = null; }
    if (this._sessizT) { clearTimeout(this._sessizT); this._sessizT = null; }
    try { if (this.stream) this.stream.end(); } catch (_) {}
    this.onFinal(String(text || '').trim(), err || null);
  }
}

module.exports = { GoogleSttStream };
