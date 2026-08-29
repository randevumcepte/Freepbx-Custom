'use strict';
/**
 * Google Cloud Speech STREAMING STT — VAD-KAPILI (maliyet optimizasyonu).
 *
 * MALIYET: Google akan sesin TUM suresini faturalar. Dinleme penceresindeki SESSIZLIK
 * (arayan konusmadan once/dusunurken) de faturaya girerdi. Cozum: yerel enerji-VAD ile
 * Google stream'ini TEMBEL ac — ses esigi asilana kadar Google'a HIC ses gitmez (stream
 * bile acilmaz -> o sure ucretsiz). Konusma baslayinca ~320ms on-tampon + sonrasi akitilir;
 * bitisi Google'in isFinal endpoint'i yakalar. Boylece faturalanan ses ~KONUSMA suresine iner.
 *
 * stt.js SttStream ile AYNI sozlesme: new GoogleSttStream({onInterim,onFinal[,phrases]}) +
 * write(pcm) + end(). rtp.js 16kHz LINEAR16 (LE) PCM uretir.
 * Kimlik: GOOGLE_APPLICATION_CREDENTIALS env'i gcp-key.json'a isaret etmeli.
 */
const speech = require('@google-cloud/speech');
const config = require('./config');

const client = new speech.SpeechClient();

const MAX_MS = parseInt(process.env.VAD_MAX_MS || '12000', 10);   // hic konusma gelmezse kapat (bekleme ucretsiz)
const SESSIZLIK_MS = 3000;                                        // konusma sonrasi isFinal gelmezse yedekle kapat
const RMS_ESIK = parseInt(process.env.VAD_RMS || '500', 10);      // "konusma" enerji esigi (hat sesine gore ayarla)
const PREROLL_MS = 320;                                           // konusma esigi asilinca yollanacak on-tampon

function rms(buf) {
  let s = 0; const n = buf.length >> 1;
  for (let i = 0; i + 1 < buf.length; i += 2) { const v = buf.readInt16LE(i); s += v * v; }
  return n ? Math.sqrt(s / n) : 0;
}

class GoogleSttStream {
  constructor({ onInterim, onFinal, phrases } = {}) {
    this.onInterim = onInterim || (() => {});
    this.onFinal = onFinal || (() => {});
    this.done = false;
    this.lastInterim = '';
    this._speech = false;     // konusma basladi mi (VAD gate acildi mi)
    this._stream = null;      // Google stream — YALNIZ konusma baslayinca acilir
    this._pre = [];           // on-tampon (byte buffer'lar)
    this._preBytes = 0;
    this._sessizT = null;
    this._phrases = phrases;

    // 16kHz/16-bit/mono: ~32 byte/ms -> PREROLL_MS icin max byte
    this._preMax = Math.round((config.stt.sampleRateHertz || 16000) * 2 / 1000 * PREROLL_MS);

    // Hic konusma gelmezse (sadece sessizlik) -> stream ACILMAZ, bos bitir (Google cagrisi = 0).
    this._maxT = setTimeout(() => { if (!this._speech) console.error('[gstt] konusma yok, bos bitiriyorum (Google cagrilmadi)'); this._finish(this.lastInterim, null); }, MAX_MS);
  }

  _openStream() {
    const cfg = {
      encoding: 'LINEAR16',
      sampleRateHertz: config.stt.sampleRateHertz || 16000,
      languageCode: config.stt.language || 'tr-TR',
      enableAutomaticPunctuation: true,
    };
    if (this._phrases && this._phrases.length) cfg.speechContexts = [{ phrases: this._phrases, boost: 15 }];
    try {
      this._stream = client
        .streamingRecognize({ config: cfg, interimResults: true, singleUtterance: false })
        .on('error', (e) => { console.error('[gstt] error:', e && e.message); this._finish('', e); })
        .on('end', () => { console.error('[gstt] stream end, yedek="' + this.lastInterim + '"'); this._finish(this.lastInterim, null); })
        .on('data', (data) => {
          const r = data.results && data.results[0];
          if (!r) return;
          const t = ((r.alternatives && r.alternatives[0] && r.alternatives[0].transcript) || '').trim();
          if (r.isFinal) { if (t) { console.error('[gstt] FINAL="' + t + '"'); this._finish(t, null); } return; }
          if (t) {
            this.lastInterim = t;
            this.onInterim(t);
            if (this._sessizT) clearTimeout(this._sessizT);
            this._sessizT = setTimeout(() => { console.error('[gstt] uzun sessizlik, interim ile kapatiyorum="' + this.lastInterim + '"'); this._finish(this.lastInterim, null); }, SESSIZLIK_MS);
          }
        });
    } catch (e) { console.error('[gstt] kurulum hatasi:', e && e.message); setImmediate(() => this._finish('', e)); }
  }

  _send(pcm) { if (this._stream && this._stream.writable) { try { this._stream.write(pcm); } catch (_) {} } }

  write(pcm) {
    if (this.done) return;
    if (this._speech) { this._send(pcm); return; }

    // VAD kapisi: konusma baslamadi -> Google'a GONDERME. On-tampon tut (kelime basi icin).
    this._pre.push(pcm); this._preBytes += pcm.length;
    while (this._preBytes > this._preMax && this._pre.length > 1) { this._preBytes -= this._pre.shift().length; }

    if (rms(pcm) >= RMS_ESIK) {
      // KONUSMA BASLADI -> stream'i simdi ac, on-tampon + bu chunk'i akit.
      this._speech = true;
      console.error('[gstt] konusma algilandi -> Google stream aciliyor (on-tampon ' + this._pre.length + ' chunk)');
      this._openStream();
      for (const b of this._pre) this._send(b);
      this._pre = []; this._preBytes = 0;
    }
  }

  end() { this._finish(this.lastInterim, null); }

  _finish(text, err) {
    if (this.done) return;
    this.done = true;
    if (this._maxT) { clearTimeout(this._maxT); this._maxT = null; }
    if (this._sessizT) { clearTimeout(this._sessizT); this._sessizT = null; }
    try { if (this._stream) this._stream.end(); } catch (_) {}
    this.onFinal(String(text || '').trim(), err || null);
  }
}

module.exports = { GoogleSttStream };
