'use strict';
const axios = require('axios');
const config = require('./../config');
const { emitSentences } = require('./../chunker');

// UCRETSIZ + COK HIZLI beyin: Groq (bulut LPU, OpenAI-uyumlu + tool-calling).
// Ollama ile fark: yanit data.choices[0].message; tool_calls.function.arguments STRING (JSON);
// tool sonucu mesaji tool_call_id ister. STT/TTS santralde kalir; sadece metin buluta gider.
class GroqEngine {
  constructor({ system, ctx, toolsOpenAI, executeTool }) {
    this.ctx = ctx;
    this.toolsOpenAI = toolsOpenAI;
    this.executeTool = executeTool;
    this.messages = [{ role: 'system', content: system }];
    if (!config.groq.apiKey) console.error('[groq] UYARI: GROQ_API_KEY bos! .env e ekleyin.');
  }

  async run(userContent, onSentence) {
    this.messages.push({ role: 'user', content: userContent });

    for (let guard = 0; guard < 8; guard++) {
      const t0 = Date.now();
      const { data } = await axios.post(
        `${config.groq.url}/chat/completions`,
        {
          model: config.groq.model,
          messages: this.messages,
          tools: this.toolsOpenAI,
          tool_choice: 'auto',
          temperature: 0.2,
        },
        {
          headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${config.groq.apiKey}` },
          timeout: 30000,
        }
      );

      const msg = (data.choices && data.choices[0] && data.choices[0].message) || {};
      const calls = msg.tool_calls || [];
      console.error(`[groq] tur ${guard}: ${Date.now() - t0}ms, tool=${calls.length}, text="${(msg.content || '').slice(0, 60)}"`);

      // Asistan mesajini oldugu gibi ekle (tool_calls dahil, OpenAI formati)
      this.messages.push({ role: 'assistant', content: msg.content || '', ...(calls.length ? { tool_calls: calls } : {}) });

      if (calls.length) {
        for (const call of calls) {
          const fn = call.function || {};
          let args = fn.arguments;
          if (typeof args === 'string') { try { args = JSON.parse(args); } catch (_) { args = {}; } }
          console.error(`[groq] tool: ${fn.name}(${JSON.stringify(args).slice(0, 80)})`);
          const out = await this.executeTool(fn.name, args || {}, this.ctx);
          // OpenAI format tool sonucu: tool_call_id ZORUNLU
          this.messages.push({ role: 'tool', tool_call_id: call.id, content: String(out.toModel) });
        }
        continue; // model tool sonuclariyla devam etsin
      }

      const text = (msg.content || '').trim();
      emitSentences(text, onSentence);
      return { text, control: this.ctx.control };
    }

    emitSentences('Sizi operatöre aktarıyorum.', onSentence);
    return { text: 'Sizi operatöre aktarıyorum.', control: 'transfer' };
  }
}

module.exports = { GroqEngine };
