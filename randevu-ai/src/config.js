'use strict';
require('dotenv').config({ path: require('path').join(__dirname, '..', '.env') });

// Turkiye saati — LLM'e "bugun" referansini dogru vermek icin tek kaynak.
// (Eski sistemde node cocuk surecine TZ gecilmedigi icin gece gun kaymasi oluyordu;
//  burada tarih hesabi tek yerde ve acikca Europe/Istanbul.)
function istanbulNow() {
  return new Date(new Date().toLocaleString('en-US', { timeZone: 'Europe/Istanbul' }));
}

module.exports = {
  ari: {
    url: process.env.ARI_URL || 'http://127.0.0.1:8088',
    user: process.env.ARI_USER || 'admin',
    pass: process.env.ARI_PASS || '',
    app: process.env.ARI_APP || 'randevu_ai',
  },
  externalMedia: {
    host: process.env.EXTERNAL_MEDIA_HOST || '127.0.0.1',
    port: parseInt(process.env.EXTERNAL_MEDIA_PORT || '5001', 10),
    format: 'slin16', // 16-bit signed linear PCM, 16 kHz
  },
  claude: {
    model: process.env.CLAUDE_MODEL || 'claude-opus-4-8',
  },
  stt: {
    language: process.env.STT_LANGUAGE || 'tr-TR',
    sampleRateHertz: 16000,
  },
  api: {
    base: process.env.API_BASE || 'https://app.randevumcepte.com.tr',
  },
  tts: {
    engine: process.env.TTS_ENGINE || 'polly',
    outDir: process.env.TTS_OUT_DIR || '/var/spool/asterisk/monitor',
  },
  timezone: 'Europe/Istanbul',
  istanbulNow,
};
