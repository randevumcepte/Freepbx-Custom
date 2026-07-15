'use strict';
const fs = require('fs');
const axios = require('axios');
const config = require('./config');

// UCRETSIZ STT: external media'dan gelen PCM'i basit enerji-VAD ile biriktirir; konusma
// bitince (sessizlik esigi) WAV yazar ve whisper_server.py'ye gonderir -> Turkce metin.
// Interface eski Google surumuyle AYNI: new SttStream({onInterim,onFinal}); write(pcm); end().
// (Google surumu istenirse STT_ENGINE=google ile ayri tutulabilir; varsayilan whisper.)

function wavHeader(dataLen, sampleRate = 16000, ch = 1, bits = 16) {
  const b = Buffer.alloc(44);
  b.write('RIFF', 0); b.writeUInt32LE(36 + dataLen, 4); b.write('WAVE', 8);
  b.write('fmt ', 12); b.writeUInt32LE(16, 16); b.writeUInt16LE(1, 20);
  b.writeUInt16LE(ch, 22); b.writeUInt32LE(sampleRate, 24);
  b.writeUInt32LE(sampleRate * ch * bits / 8, 28); b.writeUInt16LE(ch * bits / 8, 32);
  b.writeUInt16LE(bits, 34); b.write('data', 36); b.writeUInt32LE(dataLen, 40);
  return b;
}

function rms(pcm) { // 16-bit LE
  let sum = 0; const n = pcm.length >> 1;
  for (let i = 0; i + 1 < pcm.length; i += 2) { const s = pcm.readInt16LE(i); sum += s * s; }
  return n ? Math.sqrt(sum / n) : 0;
}

class SttStream {
  constructor({ onInterim, onFinal }) {
    this.onFinal = onFinal || (() => {});
    this.onInterim = onInterim || (() => {});
    this.chunks = []; this.speech = false; this.silenceMs = 0; this.totalMs = 0; this.done = false;
    this.peakRms = 0;
    this.vad = config.stt.vad;
  }

  write(pcm) {
    if (this.done || !pcm || !pcm.length) return;
    const ms = pcm.length / 32; // 16kHz 16-bit mono = 32 bayt/ms
    this.totalMs += ms;
    const level = rms(pcm);
    if (level > this.peakRms) this.peakRms = level;
    if (level >= this.vad.rmsThreshold) {
      this.speech = true; this.silenceMs = 0; this.chunks.push(pcm);
      this.onInterim('...'); // barge-in tetiklemesi icin (icerik onemli degil)
    } else if (this.speech) {
      this.silenceMs += ms; this.chunks.push(pcm);
    }
    if (this.speech && this.silenceMs >= this.vad.silenceMs) return this._finalize();
    if (this.speech && this.totalMs >= this.vad.maxUtteranceMs) return this._finalize();
  }

  end() {
    if (this.done) return;
    if (this.speech) this._finalize();
    else { this.done = true; this.onFinal('', null); }
  }

  async _finalize() {
    if (this.done) return;
    this.done = true;
    const wav = `/tmp/rai-stt-${process.pid}-${this.totalMs | 0}.wav`;
    try {
      const pcm = Buffer.concat(this.chunks);
      fs.writeFileSync(wav, Buffer.concat([wavHeader(pcm.length), pcm]));
      // TESTTE: WAV silinmiyor, incelemek icin diskte kaliyor. Teshis logu:
      console.error(`[stt] finalize wav=${wav} bytes=${pcm.length} totalMs=${this.totalMs | 0} peakRms=${this.peakRms | 0} chunks=${this.chunks.length}`);
      const { data } = await axios.post(
        `${config.stt.whisper.serverUrl}/transcribe?file=${encodeURIComponent(wav)}`,
        null, { timeout: 30000 }
      );
      console.error(`[stt] transcript="${(data && data.text) || ''}"`);
      fs.unlink(wav, () => {}); // /tmp sismesin (teshis bitti)
      this.onFinal(data && data.text ? String(data.text).trim() : '', null);
    } catch (e) {
      console.error(`[stt] HATA: ${e.message} (wav=${wav})`);
      fs.unlink(wav, () => {});
      this.onFinal('', e);
    }
  }
}

module.exports = { SttStream };
