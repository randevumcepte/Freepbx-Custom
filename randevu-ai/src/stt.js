'use strict';
const speech = require('@google-cloud/speech');
const config = require('./config');

// Google Cloud streaming STT sarmalayici. Asterisk external media'dan gelen 16kHz slin16
// PCM parcalari write() ile beslenir; nihai (final) transcript geldiginde onFinal cagrilir.
// Ara (interim) sonuclar barge-in / erken kesme icin kullanilabilir.
//
// NEDEN streaming: eski sistem "sabit pencere kaydet -> node soguk baslat -> Google'a gonder"
// yapiyordu; kullanici susunca beklemek/konusma ortasindan kesmek buradan geliyordu.
// Streaming'de sessizlik/bitis Google tarafinda gercek zamanli algilanir.
class SttStream {
  constructor({ onInterim, onFinal }) {
    this.client = new speech.SpeechClient();
    this.onInterim = onInterim || (() => {});
    this.onFinal = onFinal || (() => {});
    this._start();
  }

  _start() {
    this.stream = this.client
      .streamingRecognize({
        config: {
          encoding: 'LINEAR16',
          sampleRateHertz: config.stt.sampleRateHertz,
          languageCode: config.stt.language,
          model: 'telephony_short',
          useEnhanced: true,
          enableAutomaticPunctuation: true,
          // TODO: speechContexts.phrases = salonun gercek hizmet adlari (boost).
          // Eski transcribe2.js'te bu liste STATIK'ti; salona ozel hizmetler taninmiyordu.
        },
        interimResults: true,
        singleUtterance: true, // konusma bitince stream biter -> onFinal
      })
      .on('error', (e) => this.onFinal('', e))
      .on('data', (data) => {
        const r = data.results && data.results[0];
        if (!r) return;
        const text = r.alternatives && r.alternatives[0] ? r.alternatives[0].transcript : '';
        if (r.isFinal) this.onFinal(text.trim(), null);
        else this.onInterim(text.trim());
      });
  }

  write(pcmChunk) { if (this.stream && !this.stream.destroyed) this.stream.write(pcmChunk); }
  end() { try { this.stream && this.stream.end(); } catch (_) {} }
}

module.exports = { SttStream };
