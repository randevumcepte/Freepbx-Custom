'use strict';
const axios = require('axios');
const config = require('./../config');
const { emitSentences } = require('./../chunker');

// UCRETSIZ / YEREL beyin: Ollama (Qwen). Cagri basi sifir ucret.
// Ollama /api/chat OpenAI-uyumlu "tools" destekler; tool_calls doner.
// Basitlik icin non-streaming: tur tur cagir, tool varsa calistir; nihai metni
// cumlelere bolup onSentence'a ver (TTS kuyrugu yine cumle cumle calar).
class OllamaEngine {
  constructor({ system, ctx, toolsOpenAI, executeTool }) {
    this.ctx = ctx;
    this.toolsOpenAI = toolsOpenAI;
    this.executeTool = executeTool;
    this.messages = [{ role: 'system', content: system }];
  }

  async run(userContent, onSentence) {
    this.messages.push({ role: 'user', content: userContent });

    for (let guard = 0; guard < 8; guard++) {
      const { data } = await axios.post(`${config.ollama.url}/api/chat`, {
        model: config.ollama.model,
        messages: this.messages,
        tools: this.toolsOpenAI,
        stream: false,
        options: { temperature: 0.2 }, // slot doldurma: kararli olsun
      }, { timeout: 60000 });

      const msg = data.message || {};
      this.messages.push(msg);

      const calls = msg.tool_calls || [];
      if (calls.length) {
        for (const call of calls) {
          const fn = call.function || {};
          let args = fn.arguments;
          if (typeof args === 'string') { try { args = JSON.parse(args); } catch (_) { args = {}; } }
          const out = await this.executeTool(fn.name, args || {}, this.ctx);
          // Ollama tool yaniti: role 'tool'
          this.messages.push({ role: 'tool', name: fn.name, content: String(out.toModel) });
        }
        continue; // model tool sonuclariyla devam etsin
      }

      const text = (msg.content || '').trim();
      emitSentences(text, onSentence);
      return { text, control: this.ctx.control };
    }

    emitSentences('Sizi operatore aktariyorum.', onSentence);
    return { text: 'Sizi operatore aktariyorum.', control: 'transfer' };
  }
}

module.exports = { OllamaEngine };
