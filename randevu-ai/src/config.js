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
    // SIDECAR: AI Laravel sunucuda, Asterisk ayri sunucuda -> ARI_URL = Asterisk IP.
    url: process.env.ARI_URL || 'http://127.0.0.1:8088',
    user: process.env.ARI_USER || 'sidecar', // FreePBX ari_additional.conf [sidecar]
    pass: process.env.ARI_PASS || '',
    app: process.env.ARI_APP || 'randevu_ai',
  },
  externalMedia: {
    host: process.env.EXTERNAL_MEDIA_HOST || '127.0.0.1',
    port: parseInt(process.env.EXTERNAL_MEDIA_PORT || '5001', 10),
    format: 'slin16', // 16-bit signed linear PCM, 16 kHz
  },
  // Beyin motoru: 'ollama' = UCRETSIZ, kendi sunucunuzda (Qwen) — cagri basi sifir ucret.
  //               'claude' = Anthropic API (ucretli ama en kaliteli/akici).
  brain: process.env.BRAIN || 'ollama',
  ollama: {
    url: process.env.OLLAMA_URL || 'http://127.0.0.1:11434',
    // Turkce + tool-calling icin acik modeller arasinda en iyi denge: Qwen.
    // GPU varsa: qwen2.5:14b | orta: qwen2.5:7b | CPU-only/dusuk: qwen2.5:3b
    model: process.env.OLLAMA_MODEL || 'qwen2.5:7b',
  },
  // Groq: UCRETSIZ tier + COK HIZLI (bulut LPU). OpenAI-uyumlu + tool-calling.
  // STT/TTS santralde kalir; sadece beyin Groq'ta. console.groq.com'dan ucretsiz key.
  groq: {
    url: process.env.GROQ_URL || 'https://api.groq.com/openai/v1',
    apiKey: process.env.GROQ_API_KEY || '',
    // Denge: llama-3.3-70b-versatile | daha hizli: llama-3.1-8b-instant
    model: process.env.GROQ_MODEL || 'llama-3.3-70b-versatile',
  },
  claude: {
    // API kullanilirsa. Denge: claude-sonnet-5 | hizli: haiku-4-5 | en zeki: opus-4-8
    model: process.env.CLAUDE_MODEL || 'claude-sonnet-5',
  },
  stt: {
    engine: process.env.STT_ENGINE || 'whisper', // groq (bulut, hizli+dogru) | whisper (yerel) | google
    language: process.env.STT_LANGUAGE || 'tr-TR',
    sampleRateHertz: 16000,
    groqModel: process.env.GROQ_STT_MODEL || 'whisper-large-v3-turbo', // veya whisper-large-v3 (biraz daha dogru)
    whisper: {
      serverUrl: process.env.WHISPER_URL || 'http://127.0.0.1:5003', // whisper_server.py
      model: process.env.WHISPER_MODEL || 'small', // tiny/base/small/medium; GPU'da large-v3
    },
    // VAD (konusma bitisi): telefon sesine gore RMS esigi kutu uzerinde ayarlanmali.
    vad: {
      silenceMs: parseInt(process.env.VAD_SILENCE_MS || '800', 10),
      rmsThreshold: parseInt(process.env.VAD_RMS || '500', 10),
      maxUtteranceMs: parseInt(process.env.VAD_MAX_MS || '12000', 10),
    },
  },
  api: {
    base: process.env.API_BASE || 'https://app.randevumcepte.com.tr',
  },
  tts: {
    engine: process.env.TTS_ENGINE || 'edge', // edge (UCRETSIZ) | piper (ucretsiz offline) | polly (ucretli)
    outDir: process.env.TTS_OUT_DIR || '/var/spool/asterisk/monitor',
    edgeVoice: process.env.EDGE_TTS_VOICE || 'tr-TR-EmelNeural', // veya tr-TR-AhmetNeural
    // Duz/robot hissini azaltmak icin: hafif hizlandir + ton ver. Ornek: rate=+8%, pitch=+3Hz.
    edgeRate: process.env.EDGE_TTS_RATE || '+8%',
    edgePitch: process.env.EDGE_TTS_PITCH || '+2Hz',
    piperModel: process.env.PIPER_MODEL || '/opt/piper/tr_TR-model.onnx',
  },
  timezone: 'Europe/Istanbul',
  istanbulNow,
};
