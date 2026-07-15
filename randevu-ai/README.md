# randevu-ai — LLM tabanlı sesli randevu asistanı (PROTOTİP)

Eski AGI monoliti `sesliYanitOptimize.php`'nin (kaydet→shell_exec node→STT→regex `tarihParser.js`→sonraki tur) yerini almak için tasarlanan **akış-tabanlı, LLM beyinli** sesli asistan.

**Temel fark:** Tarih ve hizmet anlama işini **LLM** yapar (tool-calling ile). `tarihParser.js` ve fuzzy string eşleştirme **tamamen kalkar.** Model, mevcut Laravel booking API'lerini fonksiyon olarak çağırır.

**Kapsam:** randevu **oluştur / güncelle / iptal** + **paketten** randevu. Slotlar: hizmet, personel, tarih, saat.

**Beyin çift-modlu (`.env` → `BRAIN`):**
- `ollama` (varsayılan) — **ÜCRETSİZ**, kendi sunucunuzda Qwen; çağrı-başı sıfır ücret.
- `claude` — Anthropic API (ücretli, en kaliteli/akıcı).

Aynı prompt + aynı tool'lar; sadece motor değişir (`src/engines/`).

---

## Neden bu mimari

Eski akıştaki her hata tek bir mimari seçimin semptomuydu:

| Eski (turn-based AGI) | Yeni (randevu-ai) |
|---|---|
| Sabit pencere kaydet, VAD kapalı → konuşma kesilir/gürültü girer | Google **streaming** STT → konuşma bitişi gerçek zamanlı |
| Her tur node soğuk başlatma + gidiş-dönüş gecikmesi | Kalıcı bağlantı, akış |
| `tarihParser.js` regex → "iki gün sonra" işlenmez, gece TZ kayması | Model doğal dilden tarihi çözer (TZ = Europe/Istanbul, tek yerde) |
| Fuzzy string hizmet eşleştirme, yanlış eşleşme | Model niyet + hizmet eşleştirmeyi bağlamla yapar |
| Sonsuz döngü, operatöre düşmez | Model akışı yönetir + `operatore_aktar` tool'u |
| Barge-in yok | STT interim → çalmayı kes (barge-in) |
| Tam yanıt bekle sonra çal (uzun sessizlik) | **Yanıt stream + cümle-cümle TTS**: ilk cümle biter bitmez çalar; dolgu ("bir bakıyorum") tool beklerken çalar |

---

## Akış

```
Asterisk (Stasis: randevu_ai)
   │  ├─ caller channel ─┐
   │                     ├─ mixing bridge
   │  └─ externalMedia ──┘ ──(RTP slin16 16kHz)──▶ Node RtpServer
   │                                                    │ PCM
   │                                                    ▼
   │                                            Google streaming STT
   │                                                    │ transcript (final)
   │                                                    ▼
   │                                        Dialog (Claude + tool-calling)
   │                                          ├─ uygun_randevu_bul  ─▶ /api/v1/randevuUygunlukKontrolEt
   │                                          ├─ randevu_olustur    ─▶ /api/v1/santralRandevuEkle
   │                                          ├─ operatore_aktar
   │                                          └─ arama_kapat
   │                                                    │ cevap metni
   │                                                    ▼
   └─◀── ARI Playback ◀── TTS (Polly/Google) ◀─────────┘
```

---

## Dosyalar

| Dosya | Ne yapar |
|---|---|
| `src/server.js` | Giriş: ARI'ye bağlan, `StasisStart` → `CallSession` |
| `src/ari.js` | Tek çağrının tüm yaşam döngüsü: medya köprüsü, tur döngüsü, transfer/hangup |
| `src/rtp.js` | External media'dan gelen RTP → PCM |
| `src/stt.js` | **ÜCRETSİZ STT:** enerji-VAD + Whisper (`whisper_server.py`'ye gönderir) |
| `whisper_server.py` | faster-whisper kalıcı sunucu (modeli bir kez yükler, düşük gecikme) |
| `src/dialog.js` | **Beyin dispatcher:** `BRAIN`'e göre motoru seçer |
| `src/engines/ollama.js` | **ÜCRETSİZ** motor: yerel Qwen (Ollama, OpenAI-uyumlu tool-calling) |
| `src/engines/claude.js` | API motoru: Claude (streaming) |
| `src/chunker.js` | Cümle-cümle akıtma (TTS kuyruğu için) |
| `src/tools.js` | Tool tanımları + **gerçek** API çağrıları (oluştur/güncelle/iptal/paket) |
| `src/prompts.js` | Türkçe sistem promptu (bağlam + mevcut randevular + paket gömülü) |
| `src/tts.js` | **ÜCRETSİZ TTS:** Edge-TTS (Türkçe) → wav; piper/polly de destekli |
| `src/callContext.js` | Çağrı başı bağlam (`santralkarsilamametni`: hizmet/randevu/paket) |
| `test/dialog-cli.js` | **Telefon olmadan** beyni test et (stub API) |
| `extensions_snippet.conf` | Dialplan entegrasyonu + IVR fallback |

---

## Hızlı test (telefon donanımı GEREKMEZ)

Beynin NLU + tarih + hizmet + tool akışını klavyeden dene. Gerçek API çağrılmaz, **gerçek randevu oluşmaz** (stub).

**Ücretsiz / yerel (Ollama) ile:**
```bash
# 1) Ollama kur (ollama.com) ve modeli indir:
ollama pull qwen2.5:7b        # CPU-only ise: qwen2.5:3b
# 2) test:
cd randevu-ai && npm install
npm run dialog                # BRAIN varsayılan ollama
```

**API (Claude) ile denemek isterseniz:**
```bash
cd randevu-ai && npm install
export BRAIN=claude ANTHROPIC_API_KEY=sk-ant-...
npm run dialog
```

Örnek cümleler: "yarın öğleden sonra saç kesimi, Elif'ten olsun", "salı 14:00 randevumu perşembe 16:00'ya al" (güncelle), "cumaki randevumu iptal et", "paketimden randevu istiyorum".

---

## Prod kurulum (sidecar sunucu — tamamen ÜCRETSİZ varsayılan)

Varsayılan yığın: **Whisper (STT) + Ollama/Qwen (beyin) + Edge-TTS (TTS)** → çağrı-başı 0 ücret.

```bash
# 0) Gereksinimler (sidecar/Laravel sunucusunda)
pip install faster-whisper edge-tts        # ücretsiz STT + TTS
# ffmpeg (edge mp3->wav) ve node kurulu olmalı; ollama: https://ollama.com
ollama pull qwen2.5:7b                      # CPU-only: qwen2.5:3b

cd randevu-ai && npm install
cp .env.example .env && nano .env           # ARI_URL=Asterisk IP, EXTERNAL_MEDIA_HOST=bu sunucu IP, ARI_PASS

# 1) Ücretsiz STT sunucusunu başlat (modeli bir kez yükler)
pm2 start whisper_server.py --name whisper --interpreter python3 -- small

# 2) Ollama zaten servis; sidecar'ı başlat
pm2 start src/server.js --name randevu-ai
```

FreePBX 16 tarafında: ARI kullanıcısı (`ari_additional_custom.conf`), HTTP `bindaddr=0.0.0.0`, firewall'da sidecar IP'si Trusted, `[sesli-asistan]` dialplan bloğu (`extensions_custom.conf`), sonra `asterisk -rx "dialplan reload"`. App adı `ari show apps` ile `ARI_APP` = dialplan `Stasis(...)` eşleşmeli.

---

## Canlıya kademeli geçiş

1. **Tek test DID** seç. `did_contexts` tablosunda o DID'in context'ini `sesli-asistan` yap (diğerleri `from-trunk-custom` — eski IVR — kalır).
2. `pm2 start` ile Node servisini ayağa kaldır.
3. Test et. Node **ayakta değilse** dialplan `failed → from-trunk-custom` ile eski IVR'a düşer; yani müşteri her hâlükârda hizmet alır.
4. Güven geldikçe DID'leri tek tek taşı.

---

## ⚠️ Neyi çözer, neyi ÇÖZMEZ

- **Çözer:** yanlış tarih (regex kalktı), söyleneni algılamama (VAD + Whisper + LLM), sonsuz döngü, doğal konuşma.
- **Oda/personel:** backend fix (Batch 4) **UYGULANDI** (çok-hizmet NULL→[0] fallback, oda geçerlilik, `OdaHizmetler` fallback — `Controller.php`). Bu asistan da aynı API'yi çağırdığı için artık oda ataması doğru gelir.

---

## Açık işler (donanımda iterasyon gerektirenler)

- **Medya topolojisi:** external media + eşzamanlı Playback bazı Asterisk sürümlerinde snoop/ayrı bridge ister — kutu üzerinde doğrula (`ari.js:_setupMedia`).
- **Çok eşzamanlı çağrı:** external media portu çağrı başına ayrılmalı (`rtp.js` port havuzu).
- **VAD eşiği:** `VAD_RMS`/`VAD_SILENCE_MS` telefon hattı sesine göre kutu üzerinde ayarlanmalı (`stt.js`).
- **`santralkarsilamametni` alan eşlemesi:** `hizmetler`/`enYakinRandevu`/`paket` şekillerini canlı yanıtla doğrula (`callContext.js`, `tools.js`).
- **Whisper latency:** CPU'da `small` model tur başına ~1-2sn; GPU ile çok daha hızlı. Model boyutu `WHISPER_MODEL`.
- **Ollama streaming:** şu an non-streaming (cümlelere bölünüp çalınıyor); istenirse delta ile ilk ses öne çekilir.
- **Latency:** yanıt akıtma + cümle-cümle TTS + barge-in UYGULANDI.

---

## Tamamen ücretsiz yığın (self-host, çağrı-başı 0 ücret)

| Katman | Ücretsiz araç (VARSAYILAN) | Not |
|---|---|---|
| STT | **Whisper** (`whisper_server.py`, faster-whisper) | `pip install faster-whisper` |
| Beyin | **Ollama + Qwen** (`BRAIN=ollama`) | GPU: 7B/14B hızlı; CPU-only: 3B |
| TTS | **Edge-TTS** (`tr-TR-EmelNeural`) | `pip install edge-tts` + ffmpeg; offline istenirse Piper |

Üçü de **artık varsayılan** — çağrı-başı sıfır ücret. Tek gerçek maliyet **donanım** (para değil): akıcılık için modest bir GPU idealdir; CPU-only'da `small` Whisper + 3B Qwen ile idare edilir. AI'yı santral kutusunda çalıştırmak zorunda değilsiniz — ağdaki (sidecar) makinede olabilir.

## Maliyet — API modu (`BRAIN=claude`, opsiyonel)
- LLM Sonnet 5: görüşme başı ≈ 0,03–0,12 $; STT/TTS eklenince ~0,10–0,30 $. Ollama'ya geçince bu sıfırlanır (yalnız donanım).
