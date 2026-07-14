'use strict';
const Anthropic = require('@anthropic-ai/sdk');
const config = require('./config');
const { buildSystemPrompt } = require('./prompts');
const { toolDefinitions, executeTool } = require('./tools');

// Bir cagriya karsilik gelen konusma durumu. Her transcript (STT ciktisi) handleUtterance'a
// verilir; iceride Claude tool-calling dongusu doner ve SESLI okunacak metni + kontrol
// sinyalini (transfer/hangup) dondurur. tarihParser.js YOK — tarih/hizmet cikarimi modelde.
class Dialog {
  constructor(callContext) {
    // callContext: { salonAdi, salonId, userId, musteriAdi, hizmetler }
    this.client = new Anthropic(); // ANTHROPIC_API_KEY veya `ant auth login` profili
    this.ctx = {
      ...callContext,
      lastAvailability: new Map(), // salonHizmetId -> backend slot (oda/personel/uygun saat)
      control: null,               // 'transfer' | 'hangup'
    };
    this.system = buildSystemPrompt({
      ...callContext,
      nowText: config.istanbulNow().toLocaleString('tr-TR', {
        timeZone: config.timezone, dateStyle: 'full', timeStyle: 'short',
      }),
    });
    this.tools = toolDefinitions();
    this.messages = [];
  }

  // Karsilama cumlesi (ilk anons). Model'e "kullanici hatta" diyerek acilis urettiriyoruz.
  async opening() {
    return this._run({ role: 'user', content: '(Musteri hatta baglandi. Kisa bir karsilama yap ve nasil yardimci olabilecegini sor.)' });
  }

  // STT'den gelen musteri cumlesi.
  async handleUtterance(transcript) {
    const text = (transcript || '').trim();
    return this._run({ role: 'user', content: text.length ? text : '(...)' });
  }

  async _run(userTurn) {
    this.messages.push(userTurn);

    // Manuel tool-use dongusu — tool zinciri bitene kadar don.
    for (let guard = 0; guard < 8; guard++) {
      const resp = await this.client.messages.create({
        model: config.claude.model,       // claude-opus-4-8
        max_tokens: 1024,
        system: this.system,
        tools: this.tools,
        // Sesli cagri: dusuk gecikme onemli, tur basit (NLU + tool karari) -> effort low,
        // thinking kapali (opus-4-8'de thinking varsayilan kapali). Gecikme kritikse
        // model 'claude-haiku-4-5' veya 'claude-sonnet-5' ile de denenebilir.
        output_config: { effort: 'low' },
        messages: this.messages,
      });

      this.messages.push({ role: 'assistant', content: resp.content });

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
        continue; // model tool sonuclariyla devam etsin
      }

      // Bitti: sesli okunacak metni topla.
      const spoken = resp.content.filter(b => b.type === 'text').map(b => b.text).join(' ').trim();
      return { text: spoken, control: this.ctx.control };
    }

    return { text: 'Sizi operatore aktariyorum.', control: 'transfer' };
  }
}

module.exports = { Dialog };
