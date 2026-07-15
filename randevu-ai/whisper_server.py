#!/usr/bin/env python3
# UCRETSIZ yerel STT sunucusu (faster-whisper). Modeli BIR KEZ yukler (soguk baslatma yok),
# WAV yolunu alip Turkce transcript doner. randevu-ai stt.js buraya POST atar.
#
# Kurulum:  pip install faster-whisper
# Calistir: python3 whisper_server.py [model]   (model: tiny/base/small/medium/large-v3)
# GPU varsa otomatik kullanir (device=auto).
import sys, json, os
from http.server import HTTPServer, BaseHTTPRequestHandler
from urllib.parse import urlparse, parse_qs

try:
    from faster_whisper import WhisperModel
except ImportError:
    print("HATA: pip install faster-whisper gerekli", flush=True)
    sys.exit(1)

MODEL_NAME = sys.argv[1] if len(sys.argv) > 1 else os.environ.get("WHISPER_MODEL", "small")
PORT = int(os.environ.get("WHISPER_PORT", "5003"))

print(f"[whisper] model '{MODEL_NAME}' yukleniyor...", flush=True)
model = WhisperModel(MODEL_NAME, device="auto", compute_type="int8")
print(f"[whisper] hazir, 127.0.0.1:{PORT} dinleniyor", flush=True)


class Handler(BaseHTTPRequestHandler):
    def do_POST(self):
        q = parse_qs(urlparse(self.path).query)
        wav = q.get("file", [""])[0]
        text = ""
        try:
            if wav and os.path.exists(wav):
                segments, _ = model.transcribe(
                    wav, language="tr", vad_filter=True, beam_size=5,
                    initial_prompt=(
                        "Randevu almak, güncellemek, ertelemek, iptal etmek istiyorum. "
                        "Hizmet, personel, saat, tarih, paket. Saç kesimi, cuma, "
                        "öğleden sonra, pazartesi, evet, hayır."
                    ),
                )
                text = " ".join(s.text.strip() for s in segments).strip()
        except Exception as e:
            print(f"[whisper] hata: {e}", flush=True)
        body = json.dumps({"text": text}).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, *a):
        pass


HTTPServer(("127.0.0.1", PORT), Handler).serve_forever()
