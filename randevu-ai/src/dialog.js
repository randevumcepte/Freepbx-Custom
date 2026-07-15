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

    if (config.brain === 'claude') {
      const { ClaudeEngine } = require('./engines/claude');
      this.engine = new ClaudeEngine({ system, ctx: this.ctx, tools: toolDefinitions(), executeTool });
    } else {
      const { OllamaEngine } = require('./engines/ollama');
      this.engine = new OllamaEngine({ system, ctx: this.ctx, toolsOpenAI: toolDefinitionsOpenAI(), executeTool });
    }
  }

  opening(onSentence) {
    // Karsilama SABIT sablon: LLM beklemeden hemen calar (buyuk katalog + CPU model ile ilk
    // inference yavas; karsilama icin LLM gerekmez). Boylece medya yolu da hemen dogrulanir.
    let t = `Merhaba, ${this.ctx.salonAdi || 'salonumuz'} randevu asistanina hos geldiniz. `;
    if (this.ctx.paket && this.ctx.paket.bekleyenSeans) {
      t += `${this.ctx.paket.paketAdi} paketinizden randevu olusturmami ister misiniz, yoksa baska bir islem mi yapalim?`;
    } else {
      t += `Randevu almak, ertelemek veya iptal etmek icin nasil yardimci olabilirim?`;
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
