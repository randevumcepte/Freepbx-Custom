'use strict';
const config = require('./config');
const { Dialog } = require('./dialog');
const { SttStream } = require('./stt');
const { speak } = require('./tts');
const { loadCallContext } = require('./callContext');

// Tek bir cagriyi bastan sona yoneten oturum. Akis:
//   Answer -> baglam yukle -> medya kopru (external media) -> karsilama TTS ->
//   [dinle(STT) -> Dialog(Claude+tool) -> TTS -> cal]* -> transfer/hangup
//
// ⚠️ MEDYA TOPOLOJISI (external media + ayni anda Playback) Asterisk surumune gore
// ince ayar ister; asagisi calisir bir iskelet, kutu uzerinde dogrulanmali.
class CallSession {
  constructor(ari, channel, rtp) {
    this.ari = ari;
    this.channel = channel;
    this.rtp = rtp;         // global RtpServer
    this.callId = channel.id;
    this.stt = null;
    this.playing = false;
    this.playQueue = [];   // TTS+calma icin cumle kuyrugu (streaming'den doluyor)
    this.finished = false;
  }

  async start() {
    await this.channel.answer();

    const callerId = this.channel.caller && this.channel.caller.number;
    const did = this.channel.dialplan && this.channel.dialplan.exten;
    this.ctx = await loadCallContext(callerId, did);
    this.dialog = new Dialog(this.ctx);

    await this._setupMedia();

    // RTP -> aktif STT'ye ses akisi
    this._pcmSink = (pcm) => { if (this.stt) this.stt.write(pcm); };
    this.rtp.on('pcm', this._pcmSink);

    // Karsilama (cumleler geldikce kuyruga -> calinir)
    const opening = await this.dialog.opening((s) => this._enqueueSpeak(s));
    await this._drain();
    if (opening.control) return this._applyControl(opening.control);

    this._listen();
  }

  async _setupMedia() {
    // Sesi bizim RTP sunucumuza aynalamak icin external media kanali + mixing bridge.
    this.bridge = await this.ari.bridges.create({ type: 'mixing' });
    await this.bridge.addChannel({ channel: this.channel.id });

    this.extChannel = await this.ari.channels.externalMedia({
      app: config.ari.app,
      external_host: `${config.externalMedia.host}:${config.externalMedia.port}`,
      format: config.externalMedia.format, // slin16
    });
    await this.bridge.addChannel({ channel: this.extChannel.id });
    // TODO: cok es zamanli cagri icin external media portunu cagri basina ayir.
  }

  // Bir tur dinle: STT stream ac, final transcript gelince Dialog'a ver.
  _listen() {
    if (this.finished) return;
    this.stt = new SttStream({
      onInterim: (t) => {
        // Barge-in: asistan konusurken musteri konusmaya baslarsa calmayi kes + kuyrugu bosalt.
        if ((this.playing || this.playQueue.length) && t) this._bargeIn();
      },
      onFinal: async (transcript, err) => {
        this.stt = null;
        if (err) return this._applyControl('transfer');
        // Yanit stream olur; cumleler geldikce kuyruga girer ve calar (uretimle es zamanli).
        const res = await this.dialog.handleUtterance(transcript, (s) => this._enqueueSpeak(s));
        await this._drain();
        if (res.control) return this._applyControl(res.control);
        this._listen(); // sonraki tur
      },
    });
  }

  // Cumleyi kuyruga al ve pompayi tetikle (streaming'den cagrilir).
  _enqueueSpeak(text) {
    const t = (text || '').trim();
    if (!t || this.finished) return;
    this.playQueue.push(t);
    this._pump();
  }

  // Kuyruktaki cumleleri sirayla (ust uste binmeden) calar.
  async _pump() {
    if (this.playing || this.finished) return;
    this.playing = true;
    while (this.playQueue.length && !this.finished) {
      await this._playOne(this.playQueue.shift());
    }
    this.playing = false;
  }

  async _playOne(text) {
    try {
      const media = await speak(text, this.callId);
      const playback = await this.channel.play({ media });
      this._activePlayback = playback;
      await new Promise((resolve) => playback.once('PlaybackFinished', resolve));
    } catch (_) {
      // TTS/cal hatasi: sessizce gec.
    } finally {
      this._activePlayback = null;
    }
  }

  // Barge-in: caldaki cumleyi kes, bekleyen cumleleri at.
  _bargeIn() {
    this.playQueue.length = 0;
    if (this._activePlayback) { try { this._activePlayback.stop(); } catch (_) {} }
  }

  // Kuyruk bosalana kadar bekle (bir sonraki tur dinlemeye gecmeden once).
  _drain() {
    return new Promise((resolve) => {
      const t = setInterval(() => {
        if (this.finished || (!this.playing && this.playQueue.length === 0)) {
          clearInterval(t); resolve();
        }
      }, 40);
    });
  }

  async _applyControl(control) {
    if (this.finished) return;
    if (control === 'transfer') {
      // Operatore aktar: mevcut IVR ile ayni hedef ([operator-bagla] veya from-queue).
      const target = this.ctx.operatorKanali;
      try {
        await this.channel.continueInDialplan({ context: 'operator-bagla', extension: 's', priority: 1 });
      } catch (_) {
        if (target) { try { await this.channel.continueInDialplan({ context: 'from-queue', extension: String(target), priority: 1 }); } catch (__) {} }
      }
    }
    await this._cleanup();
  }

  async _cleanup() {
    if (this.finished) return;
    this.finished = true;
    if (this._pcmSink) this.rtp.removeListener('pcm', this._pcmSink);
    if (this.stt) this.stt.end();
    try { if (this.extChannel) await this.extChannel.hangup(); } catch (_) {}
    try { if (this.bridge) await this.bridge.destroy(); } catch (_) {}
    try { await this.channel.hangup(); } catch (_) {}
  }
}

module.exports = { CallSession };
