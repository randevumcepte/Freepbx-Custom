'use strict';
const Anthropic = require('@anthropic-ai/sdk');
const config = require('./config');
const { buildSystemPrompt } = require('./prompts');
const { toolDefinitions, executeTool } = require('./tools');

// Metni CUMLE CUMLE akitan yardimci: streaming LLM ciktisindan cumle sinirina gelince
// (. ! ? … veya yeni satir) o cumleyi hemen onSentence'a verir. Boylece ilk cumle
// bitince TTS+calma baslar, model gerisini uretirken ses calar -> AKICILIK.
function makeChunker(onSentence) {
  let buf = '';
  const flushComplete = () => {
    // Cumle sonu noktalamasindan sonrasini kes. ("15:30" gibi icteki : tetiklemez.)
    const m = buf.match(/^[\s\S]*?[.!?…]+/);
    if (!m) return false;
    const s = buf.slice(0, m[0].length).trim();
    buf = buf.slice(m[0].length);
    if (s) onSentence(s);
    return true;
  };
  return {
    push(delta) { buf += delta; while (flushComplete()) { /* birden fazla cumle olabilir */ } },
    flush() { const s = buf.trim(); buf = ''; if (s) onSentence(s); },
  };
}

// Bir cagriya karsilik gelen konusma durumu. tarihParser.js YOK — tarih/hizmet cikarimi modelde.
class Dialog {
  constructor(callContext) {
    this.client = new Anthropic();
    this.ctx = { ...callContext, lastAvailability: new Map(), control: null };
    this.system = buildSystemPrompt({
      ...callContext,
      nowText: config.istanbulNow().toLocaleString('tr-TR', {
        timeZone: config.timezone, dateStyle: 'full', timeStyle: 'short',
      }),
    });
    this.tools = toolDefinitions();
    this.messages = [];
  }

  // onSentence(cumle): her cumle hazir oldugunda cagrilir (TTS'e akitmak icin). Opsiyonel;
  // verilmezse yalnizca tam metin dondurulur (CLI/test icin).
  async opening(onSentence) {
    return this._run({ role: 'user', content: '(Musteri hatta baglandi. Kisa bir karsilama yap ve nasil yardimci olabilecegini sor.)' }, onSentence);
  }

  async handleUtterance(transcript, onSentence) {
    const text = (transcript || '').trim();
    return this._run({ role: 'user', content: text.length ? text : '(...)' }, onSentence);
  }

  async _run(userTurn, onSentence) {
    this.messages.push(userTurn);
    const chunker = onSentence ? makeChunker(onSentence) : null;

    for (let guard = 0; guard < 8; guard++) {
      const stream = this.client.messages.stream({
        model: config.claude.model,       // claude-sonnet-5 (denge: akici + kaliteli)
        max_tokens: 1024,
        system: this.system,
        tools: this.tools,
        output_config: { effort: 'low' }, // sesli tur: dusuk gecikme
        messages: this.messages,
      });

      let spoken = '';
      stream.on('text', (delta) => { spoken += delta; if (chunker) chunker.push(delta); });

      const resp = await stream.finalMessage();
      this.messages.push({ role: 'assistant', content: resp.content });

      // Bu turda uretilen (varsa dolgu) cumleyi kapat -> tool beklerken calar.
      if (chunker) chunker.flush();

      if (resp.stop_reason === 'tool_use') {
        const toolResults = [];
        for (const block of resp.content) {
          if (block.type !== 'tool_use') continue;
          const out = await executeTool(block.name, block.input, this.ctx);
          toolResults.push({
            type: 'tool_result',
            tool_use_id: block.id,
            content: out.toModel,
            ...(out.isError ? { is_error: true } : {}),
          });
        }
        this.messages.push({ role: 'user', content: toolResults });
        continue;
      }

      return { text: spoken.trim(), control: this.ctx.control };
    }

    if (chunker) chunker.flush();
    return { text: 'Sizi operatore aktariyorum.', control: 'transfer' };
  }
}

module.exports = { Dialog };
