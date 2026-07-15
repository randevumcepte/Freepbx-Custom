'use strict';
const dgram = require('dgram');
const { EventEmitter } = require('events');
const config = require('./config');

// Asterisk External Media (format=slin16) sesi bu UDP porta RTP paketleri olarak yollar.
// Her paket: 12 bayt RTP basligi + PCM payload (16-bit LE, 16kHz mono).
// 'pcm' event'i ile ham PCM parcalarini yayinlar (STT'ye beslenir).
//
// NOT: Tek external-media kanalindan tek cagri dinlemek icin en basit sema. Cok es zamanli
// cagri icin her cagriya ayri port / SSRC ayrimi gerekir (TODO: port havuzu).
class RtpServer extends EventEmitter {
  constructor() {
    super();
    this.sock = dgram.createSocket('udp4');
    this._pktCount = 0;
    this.sock.on('message', (msg) => {
      if (msg.length <= 12) return;
      this._pktCount++;
      if (this._pktCount === 1) console.error(`[rtp] ILK PAKET geldi len=${msg.length}`);
      if (this._pktCount % 250 === 0) console.error(`[rtp] ${this._pktCount} paket alindi`);
      // Asterisk external-media (slin16/L16) RTP'yi BIG-ENDIAN gonderiyor; Node LE okur.
      // Swap16 ile LE'ye cevir (yoksa ses cope donuyor, Whisper bos donuyor).
      const payload = Buffer.from(msg.subarray(12)); // RTP header at + kopya (swap in-place)
      if (payload.length % 2 === 0) payload.swap16();
      this.emit('pcm', payload);
    });
  }
  listen() {
    return new Promise((res) => this.sock.bind(config.externalMedia.port, config.externalMedia.host, res));
  }
  close() { try { this.sock.close(); } catch (_) {} }
}

module.exports = { RtpServer };
