# WebRTC / WSS Santral Runbook

Santral: **santral.randevumcepte.com.tr** (89.252.140.61) — FreePBX 16 / Asterisk 16.28, Debian.
Web softphone buraya `wss://santral.randevumcepte.com.tr:8089/ws` ile register olur.

> ⚠️ **santral2** (34.45.69.65) AYRI bir makinedir. Web softphone santral'a (2'siz) bağlanır.

---

## 🔴 1. FreePBX'te "Apply Config" bastıktan SONRA (en sık)

FreePBX "Apply Config" her basışta `http_additional.conf`'u otomatik üretip HTTPS dinlemesini
`127.0.0.1`'e geri çevirir → web softphone dışarıdan bağlanamaz. **Her Apply Config'den sonra:**

```bash
systemctl start webrtc-wss-fix.service
```

veya doğrudan script:

```bash
/usr/local/sbin/fix-webrtc-http.sh
```

Bu, http dinlemesini tekrar `0.0.0.0:8089`'a alır + doğru cert yolunu yazar + `module reload http` yapar.

**Doğrula:**
```bash
asterisk -rx "http show status" | grep -i https
# -> HTTPS Server Enabled and Bound to 0.0.0.0:8089   (127.0.0.1 OLMAMALI)
```

---

## 🟠 2. Asterisk / Sunucu yeniden başlatıldıktan SONRA

Normalde **otomatik** düzelir — boot'ta `webrtc-wss-fix.service` (systemd) çalışır:
hem http binding'i düzeltir hem de gerekiyorsa pjsip websocket modülünü açar.

Manuel kontrol:
```bash
systemctl status webrtc-wss-fix.service        # active (exited) olmali
journalctl -u webrtc-wss-fix.service | tail -20
```

Eğer modül yine de "Not Running" kalırsa (chan_sip "sip" alt-protokolünü kapıyorsa) **modül dansı**:
```bash
asterisk -rx "module unload chan_sip.so"
asterisk -rx "module unload res_pjsip_transport_websocket.so"
asterisk -rx "module load res_pjsip_transport_websocket.so"
asterisk -rx "module load chan_sip.so"
asterisk -rx "module show like res_pjsip_transport_websocket"   # -> Running
```
(Bu adımlar zaten `webrtc-wss-fix.service` içindeki `webrtc-postboot.sh`'ta var; elle gerekmemeli.)

---

## 🟡 3. SSL sertifikası yenilendiğinde (son tarih: 12 Kasım 2026)

Let's Encrypt yenilenince yeni cert asterisk-okunabilir yola kopyalanmalı:
```bash
cp /etc/letsencrypt/live/santral.randevumcepte.com.tr/fullchain.pem \
   /etc/asterisk/keys/santral.randevumcepte.com.tr-fullchain.crt
cp /etc/letsencrypt/live/santral.randevumcepte.com.tr/privkey.pem \
   /etc/asterisk/keys/santral.randevumcepte.com.tr.key
chown asterisk:asterisk /etc/asterisk/keys/santral.randevumcepte.com.tr-*
chmod 640 /etc/asterisk/keys/santral.randevumcepte.com.tr-*
asterisk -rx "module reload http"
```
Son tarihi kontrol:
```bash
openssl x509 -in /etc/asterisk/keys/santral.randevumcepte.com.tr-fullchain.crt -noout -enddate
```

---

## ✅ Tam doğrulama (her şey çalışıyor mu?)

```bash
# 1) http dis dunyaya acik mi
asterisk -rx "http show status" | grep -i https          # -> 0.0.0.0:8089

# 2) pjsip websocket modulu calisiyor mu
asterisk -rx "module show like res_pjsip_transport_websocket"   # -> Running

# 3) wss transport var mi
asterisk -rx "pjsip show transports"                     # -> wss-custom

# 4) DISARIDAN (kendi bilgisayarindan) WSS + cert
curl -v https://santral.randevumcepte.com.tr:8089/ws 2>&1 | grep -iE "426|verify ok|subject|issuer"
# -> 426 Upgrade Required + "SSL certificate verify ok"

# 5) bir dahili register oldu mu
asterisk -rx "pjsip show contacts"                       # -> ilgili dahili Avail
```

---

## 🧩 Dahili WebRTC uyumlu mu? (register 401/403 alırsa)

Bir yetkili register olamıyorsa, o dahili FreePBX'te WebRTC işaretli olmayabilir:
```bash
asterisk -rx "pjsip show endpoint <dahili_no>" | grep -iE "transport|webrtc|dtls|ice|media_encryption"
# transport=transport-wss (veya wss-custom), media_encryption=dtls, ice_support=true gormeli
```
Değilse: FreePBX paneli → Extensions → ilgili dahili → **Advanced / PJSIP Settings → WebRTC = Yes** → Apply Config
→ (sonra **Bölüm 1**'i çalıştır!).

> Not: Web softphone `salon_personelleri.dahili_no_webrtc` / `dahili_sifre_webrtc` kolonlarıyla register olur.
> Bu kolonlar dolu değilse panelde web telefon butonu hiç görünmez.

---

## 📁 İlgili dosyalar (sunucuda)

| Dosya | Görev |
|---|---|
| `/usr/local/sbin/fix-webrtc-http.sh` | http binding'i 0.0.0.0'a + cert yolunu düzeltir |
| `/usr/local/sbin/webrtc-postboot.sh` | boot sonrası: http fix + gerekiyorsa modül dansı |
| `/etc/systemd/system/webrtc-wss-fix.service` | boot'ta otomatik çalışan systemd servisi |
| `/etc/asterisk/http_additional.conf` | HTTPS bind + cert (FreePBX auto-gen — Apply Config sıfırlar) |
| `/etc/asterisk/pjsip.transports_custom.conf` | `[wss-custom]` transport (cert_file/method OLMAZ!) |

---
*Son güncelleme: 2026-10-01 — WSS kurulumu + restart-proof systemd + web softphone santral'a bağlandı.*
