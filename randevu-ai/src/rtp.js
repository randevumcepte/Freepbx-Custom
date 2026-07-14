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
    this.sock.on('message', (msg) => {
      if (msg.length <= 12) return;
      const payload = msg.subarray(12); // RTP header'i atla
      this.emit('pcm', payload);
    });
  }
  listen() {
    return new Promise((res) => this.sock.bind(config.externalMedia.port, config.externalMedia.host, res));
  }
  close() { try { this.sock.close(); } catch (_) {} }
}

module.exports = { RtpServer };
