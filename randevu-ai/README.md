# randevu-ai — LLM tabanlı sesli randevu asistanı (PROTOTİP)

Eski AGI monoliti `sesliYanitOptimize.php`'nin (kaydet→shell_exec node→STT→regex `tarihParser.js`→sonraki tur) yerini almak için tasarlanan **akış-tabanlı, LLM beyinli** sesli asistan.

**Temel fark:** Tarih ve hizmet anlama işini **Claude** yapar (tool-calling ile). `tarihParser.js` ve fuzzy string eşleştirme **tamamen kalkar.** Model, mevcut Laravel booking API'lerini fonksiyon olarak çağırır.

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
| `src/dialog.js` | **Beyin:** Claude tool-calling döngüsü (manuel loop) |
| `src/tools.js` | Tool tanımları + **gerçek** API çağrıları (uygunluk/oluştur) |
| `src/prompts.js` | Türkçe sistem promptu (bağlam gömülü) |
| `src/tts.js` | Metin → ses dosyası (ARI Playback için) |
| `src/callContext.js` | Çağrı başı bağlam (`santralkarsilamametni` API) |
| `test/dialog-cli.js` | **Telefon olmadan** beyni test et (stub API) |
| `extensions_snippet.conf` | Dialplan entegrasyonu + IVR fallback |

---

## Hızlı test (telefon donanımı GEREKMEZ)

Beynin NLU + tarih + hizmet + tool akışını klavyeden dene. Gerçek API çağrılmaz, **gerçek randevu oluşmaz** (stub):

```bash
cd randevu-ai
npm install
export ANTHROPIC_API_KEY=sk-ant-...       # veya: ant auth login
npm run dialog
```

Örnek: "yarın öğleden sonra saç kesimi", "önümüzdeki salıya boya", "Elif'ten olsun" — asistanın tarihi çözüşünü, onay isteyişini ve `randevu_olustur`'a gidişini izle.

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
- **Paket akışı:** çok-hizmetli paket randevusu (`tools.js` — array_fill + `paketBilgi`).
- **TTS:** Google TTS motoru (`tts.js` — şu an Polly).
- **Latency:** varsayilan model `claude-sonnet-5` (denge). Yanit stream + cumle-cumle TTS + barge-in UYGULANDI. Daha hizli istenirse `.env` CLAUDE_MODEL=claude-haiku-4-5; en zeki icin claude-opus-4-8.

---

## Yaklaşık maliyet (kaba)

- **STT:** Google ~0,016–0,024 $/dk.
- **LLM:** Claude Sonnet 5 (varsayılan) tur başına birkaç bin token; kısa randevu görüşmesi (~6–10 tur) ≈ 0,03–0,12 $ mertebesi. Daha ucuz/hızlı: `haiku-4-5`. En zeki: `opus-4-8`.
- **TTS:** Polly ~0,004 $/1k karakter.

Bir randevu görüşmesi tipik olarak birkaç dakika ve ~0,10–0,40 $ toplam mertebesindedir; salon çağrı hacmi için yönetilebilir.
