'use strict';
/**
 * Google Cloud Speech STREAMING STT — sesli-randevu-akis.php'nin batch Google STT'sinin
 * aksine GERCEK ZAMANLI: PCM surekli akitilir, konusma bitisini Google'in endpointing'i
 * (single_utterance) ANINDA yakalar -> sabit sessizlik beklemesi + batch turu YOK.
 *
 * stt.js SttStream ile AYNI sozlesme: new GoogleSttStream({onInterim,onFinal}) + write(pcm)
 * + end(). ari.js:_listen bunu fabrika ile secer (config.stt.engine==='google'). rtp.js
 * zaten 16kHz LINEAR16 (little-endian) PCM chunk'lari uretir -> dogrudan write() ile beslenir.
 *
 * Kimlik: GOOGLE_APPLICATION_CREDENTIALS env'i gcp-key.json'a isaret etmeli (mevcut
 * sesli-yanit/gcp-key.json yeniden kullanilabilir).
 */
const speech = require('@google-cloud/speech');
const config = require('./config');

const client = new speech.SpeechClient();

class GoogleSttStream {
  constructor({ onInterim, onFinal, phrases } = {}) {
    this.onInterim = onInterim || (() => {});
    this.onFinal = onFinal || (() => {});
    this.done = false;
    this.finalText = '';

    const cfg = {
      encoding: 'LINEAR16',
      sampleRateHertz: config.stt.sampleRateHertz || 16000,
      languageCode: config.stt.language || 'tr-TR',
      enableAutomaticPunctuation: false,
      useEnhanced: true,
    };
    // Salon-ozel isim/hizmet ipuclari (varsa) -> isim tanima artar.
    if (phrases && phrases.length) cfg.speechContexts = [{ phrases, boost: 15 }];

    try {
      this.stream = client
        .streamingRecognize({ config: cfg, interimResults: true, singleUtterance: true })
        .on('error', (e) => this._finish('', e))
        .on('data', (data) => {
          // Konusma bitisi (endpointing) — hemen sonlandir.
          if (data.speechEventType === 'END_OF_SINGLE_UTTERANCE') {
            this._finish(this.finalText, null);
            return;
          }
          const r = data.results && data.results[0];
          if (!r) return;
          const t = (r.alternatives && r.alternatives[0] && r.alternatives[0].transcript) || '';
          if (r.isFinal) { this.finalText = String(t).trim(); this._finish(this.finalText, null); }
          else if (t) { this.onInterim(String(t)); } // barge-in tetigi (gercek interim metin)
        });
    } catch (e) {
      // Kimlik/kutuphane sorunu -> onFinal err ile (ari.js operatore aktarir)
      setImmediate(() => this._finish('', e));
    }
  }

  write(pcm) {
    if (this.done || !this.stream) return;
    try { if (this.stream.writable) this.stream.write(pcm); } catch (_) {}
  }

  end() { this._finish(this.finalText, null); }

  _finish(text, err) {
    if (this.done) return;
    this.done = true;
    try { if (this.stream) this.stream.end(); } catch (_) {}
    this.onFinal(String(text || '').trim(), err || null);
  }
}

module.exports = { GoogleSttStream };
