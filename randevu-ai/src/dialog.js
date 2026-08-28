'use strict';
const config = require('./config');
const { buildSystemPrompt } = require('./prompts');
const { toolDefinitions, toolDefinitionsOpenAI, executeTool } = require('./tools');
// Motorlar lazy-require: ollama modunda @anthropic-ai/sdk hic yuklenmez (bagimlilik gerekmez).

// Bir cagriya karsilik gelen konusma. Beyni BRAIN ayarina gore secer:
//   ollama (UCRETSIZ, yerel Qwen) | claude (API). tarih/hizmet cikarimi modelde.
class Dialog {
  constructor(callContext) {
    // callContext: { salonAdi, salonId, userId, musteriAdi, hizmetler, enYakinRandevu, paket, stub? }
    this.ctx = { ...callContext, lastAvailability: new Map(), control: null };

    const system = buildSystemPrompt({
      ...callContext,
      nowText: config.istanbulNow().toLocaleString('tr-TR', {
        timeZone: config.timezone, dateStyle: 'full', timeStyle: 'short',
      }),
    });

    if (config.brain === 'rules') {
      // LLM YOK — kural-tabanli beyin (PHP AGI akisinin portu). Sifir LLM maliyeti.
      const { RulesEngine } = require('./engines/rules');
      this.engine = new RulesEngine({ system, ctx: this.ctx, executeTool });
    } else if (config.brain === 'claude') {
      const { ClaudeEngine } = require('./engines/claude');
      this.engine = new ClaudeEngine({ system, ctx: this.ctx, tools: toolDefinitions(), executeTool });
    } else if (config.brain === 'groq') {
      const { GroqEngine } = require('./engines/groq');
      this.engine = new GroqEngine({ system, ctx: this.ctx, toolsOpenAI: toolDefinitionsOpenAI(), executeTool });
    } else {
      const { OllamaEngine } = require('./engines/ollama');
      this.engine = new OllamaEngine({ system, ctx: this.ctx, toolsOpenAI: toolDefinitionsOpenAI(), executeTool });
    }
  }

  opening(onSentence) {
    // Karsilama: manuel from-trunk-custom ile AYNI -> API'nin dondurdugu kisisel metni kullan
    // ("Sayın X, <salon karşılama telaffuzu> hoşgeldiniz"). Yoksa jenerik. LLM beklemez.
    let t = this.ctx.karsilamaMetni
      ? (String(this.ctx.karsilamaMetni).trim().replace(/\s+/g, ' ') + ' ')
      : `Merhaba, ${this.ctx.salonAdi || 'salonumuz'} randevu asistanına hoş geldiniz. `;
    if (this.ctx.paket && this.ctx.paket.bekleyenSeans) {
      t += `${this.ctx.paket.paketAdi} paketinizden randevu oluşturmamı ister misiniz, yoksa başka bir işlem mi yapalım?`;
    } else {
      t += `Randevu almak, ertelemek veya iptal etmek için nasıl yardımcı olabilirim?`;
    }
    if (onSentence) onSentence(t);
    return Promise.resolve({ text: t, control: null });
  }

  handleUtterance(transcript, onSentence) {
    const text = (transcript || '').trim();
    return this.engine.run(text.length ? text : '(...)', onSentence);
  }
}

module.exports = { Dialog };
