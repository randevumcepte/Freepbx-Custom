'use strict';

// Metni CUMLE CUMLE akitan yardimci: cumle sinirina (. ! ? …) gelince o cumleyi hemen
// onSentence'a verir. Boylece ilk cumle bitince TTS+calma baslar -> akicilik.
function makeChunker(onSentence) {
  let buf = '';
  const flushOne = () => {
    const m = buf.match(/^[\s\S]*?[.!?…]+/);
    if (!m) return false;
    const s = buf.slice(0, m[0].length).trim();
    buf = buf.slice(m[0].length);
    if (s) onSentence(s);
    return true;
  };
  return {
    push(delta) { buf += delta; while (flushOne()) { /* birden fazla cumle */ } },
    flush() { const s = buf.trim(); buf = ''; if (s) onSentence(s); },
  };
}

// Streaming olmayan (Ollama non-stream) yanitlarda tam metni cumlelere bolup sirayla ver.
function emitSentences(text, onSentence) {
  if (!onSentence) return;
  const c = makeChunker(onSentence);
  c.push(text || '');
  c.flush();
}

module.exports = { makeChunker, emitSentences };
