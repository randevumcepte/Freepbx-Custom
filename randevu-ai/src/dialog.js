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
    return this.engine.run('(Musteri hatta baglandi. Kisa karsilama yap; paketi varsa paketten randevu isteyip istemedigini, yoksa nasil yardimci olabilecegini sor.)', onSentence);
  }

  handleUtterance(transcript, onSentence) {
    const text = (transcript || '').trim();
    return this.engine.run(text.length ? text : '(...)', onSentence);
  }
}

module.exports = { Dialog };
