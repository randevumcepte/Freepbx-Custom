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
| `src/stt.js` | Google streaming STT sarmalayıcı |
| `src/dialog.js` | **Beyin dispatcher:** `BRAIN`'e göre motoru seçer |
| `src/engines/ollama.js` | **ÜCRETSİZ** motor: yerel Qwen (Ollama, OpenAI-uyumlu tool-calling) |
| `src/engines/claude.js` | API motoru: Claude (streaming) |
| `src/chunker.js` | Cümle-cümle akıtma (TTS kuyruğu için) |
| `src/tools.js` | Tool tanımları + **gerçek** API çağrıları (oluştur/güncelle/iptal/paket) |
| `src/prompts.js` | Türkçe sistem promptu (bağlam + mevcut randevular + paket gömülü) |
| `src/tts.js` | Metin → ses dosyası (ARI Playback için) |
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

## Prod kurulum

```bash
cd /var/lib/asterisk/agi-bin/randevu-ai
npm install
cp .env.example .env && nano .env          # ARI, ANTHROPIC_API_KEY, GOOGLE creds, API_BASE
```

Asterisk `ari.conf` içinde app kullanıcısı tanımlı ve HTTP açık olmalı (`http.conf`).

```bash
pm2 start src/server.js --name randevu-ai
asterisk -rx "dialplan reload"
```

---

## Canlıya kademeli geçiş

1. **Tek test DID** seç. `did_contexts` tablosunda o DID'in context'ini `sesli-asistan` yap (diğerleri `from-trunk-custom` — eski IVR — kalır).
2. `pm2 start` ile Node servisini ayağa kaldır.
3. Test et. Node **ayakta değilse** dialplan `failed → from-trunk-custom` ile eski IVR'a düşer; yani müşteri her hâlükârda hizmet alır.
4. Güven geldikçe DID'leri tek tek taşı.

---

## ⚠️ Neyi çözer, neyi ÇÖZMEZ

- **Çözer:** yanlış tarih (regex kalktı), söyleneni algılamama (streaming STT + LLM), sonsuz döngü, doğal konuşma.
- **ÇÖZMEZ (backend fix hâlâ şart):** oda/personel ataması eksikliği. Bu asistan da **aynı** `santralRandevuEkle` API'sini çağırır; `oda_id=NULL` bug'ı (tek elemanlı dizi + `OdaHizmetler` fallback yok — Batch 4 #12/#13) backend'de duruyor. `tools.js` client tarafında dizileri doğru kurar ama backend'in de düzeltilmesi gerekir.

---

## Açık işler (donanımda iterasyon gerektirenler)

- **Medya topolojisi:** external media + eşzamanlı Playback bazı Asterisk sürümlerinde snoop/ayrı bridge ister — kutu üzerinde doğrula (`ari.js:_setupMedia`).
- **Çok eşzamanlı çağrı:** external media portu çağrı başına ayrılmalı (`rtp.js` port havuzu).
- **STT speechContext:** salonun gerçek hizmet adlarını boost olarak ilet (`stt.js`).
- **`santralkarsilamametni` alan eşlemesi:** canlı yanıtla birebir doğrula (`callContext.js`).
- **Paket alan adları:** `paket` / `enYakinRandevu` şeklini canlı `santralkarsilamametni` yanıtıyla doğrula (`callContext.js`, `tools.js`).
- **TTS:** Google/Piper motoru (`tts.js` — şu an Polly); tam ücretsiz için **Piper** (Türkçe, CPU).
- **Ollama streaming:** şu an non-streaming (cümlelere bölünüp çalınıyor); istenirse Ollama stream + delta ile ilk ses daha da öne çekilir.
- **Latency:** yanıt akıtma + cümle-cümle TTS + barge-in UYGULANDI.

---

## Tamamen ücretsiz yığın (self-host, çağrı-başı 0 ücret)

| Katman | Ücretsiz araç | Not |
|---|---|---|
| STT | **Whisper** (faster-whisper) | `whisperTranscribe.py` zaten var |
| Beyin | **Ollama + Qwen** (`BRAIN=ollama`) | GPU: 7B/14B hızlı; CPU-only: 3B (gecikme artar) |
| TTS | **Piper** (Türkçe) | CPU'da çalışır |

Tek gerçek maliyet **donanım** (para değil): akıcılık için modest bir GPU idealdir; CPU-only'da 3B ile idare edilir. AI'yı santral kutusunda çalıştırmak zorunda değilsiniz — ağdaki herhangi bir makinede olabilir.

## Maliyet — API modu (`BRAIN=claude`, opsiyonel)
- LLM Sonnet 5: görüşme başı ≈ 0,03–0,12 $; STT/TTS eklenince ~0,10–0,30 $. Ollama'ya geçince bu sıfırlanır (yalnız donanım).
