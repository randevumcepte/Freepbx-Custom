'use strict';
const axios = require('axios');
const config = require('./../config');
// Groq yaniti TEK seferde doner (stream degil) -> tum metni TEK TTS'te oku (cumle cumle bolme;
// bolersek aralara sessizlik/bekleme giriyor). onSentence'a butun yaniti bir kez veriyoruz.
function speakAll(text, onSentence) { const t = String(text || '').trim(); if (t && onSentence) onSentence(t); }

// Reasoning modelleri (qwen3, gpt-oss) düşünmeyi content icine <think>...</think> olarak
// koyabiliyor. Sesli okumadan ONCE temizle (yoksa asistan dusunme metnini okur).
function cleanText(t) {
  return String(t || '')
    .replace(/<think>[\s\S]*?<\/think>/gi, '')
    .replace(/<\/?think>/gi, '')
    .replace(/\s+/g, ' ')
    .trim();
}

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
      let data;
      try {
        ({ data } = await axios.post(
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
        ));
      } catch (err) {
        // Groq API hatasi (400 bozuk tool-call / 429 rate-limit / ag). ESKIDEN throw edip cagriyi
        // "direk kapatiyordu"; simdi GORUNUR log + zarif kapanis (operatore).
        const st = err.response && err.response.status;
        const body = err.response && err.response.data;
        console.error(`[groq] API HATASI status=${st} body=${JSON.stringify(body).slice(0, 500)} (${err.message})`);
        const kapanis = 'Şu an sizi operatöre aktarıyorum, lütfen hatta kalın.';
        speakAll(kapanis, onSentence);
        return { text: kapanis, control: 'transfer' };
      }

      const msg = (data.choices && data.choices[0] && data.choices[0].message) || {};
      const calls = msg.tool_calls || [];
      console.error(`[groq] tur ${guard}: ${Date.now() - t0}ms, tool=${calls.length}, text="${(msg.content || '').slice(0, 60)}"`);

      // Asistan mesajini ekle (tool_calls dahil, OpenAI formati). content'ten <think> temizle
      // (reasoning modellerinde gecmiste birikmesin/kafa karistirmasin).
      this.messages.push({ role: 'assistant', content: cleanText(msg.content), ...(calls.length ? { tool_calls: calls } : {}) });

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
        if (this.ctx.control) { // operatore_aktar / arama_kapat -> HEMEN bitir (dongu devam etmesin, meta-anlati/cifte TTS/kanal yarisi olmasin)
          const kapanis = this.ctx.control === 'transfer' ? 'Sizi operatöre aktarıyorum, lütfen hatta kalın.' : 'İyi günler dilerim, sağlıklı günler.';
          speakAll(kapanis, onSentence);
          return { text: kapanis, control: this.ctx.control };
        }
        continue; // model tool sonuclariyla devam etsin
      }

      const text = cleanText(msg.content);
      speakAll(text, onSentence);
      return { text, control: this.ctx.control };
    }

    speakAll('Sizi operatöre aktarıyorum.', onSentence);
    return { text: 'Sizi operatöre aktarıyorum.', control: 'transfer' };
  }
}

module.exports = { GroqEngine };
