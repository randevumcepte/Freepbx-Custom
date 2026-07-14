'use strict';
const ariClient = require('ari-client');
const config = require('./config');
const { RtpServer } = require('./rtp');
const { CallSession } = require('./ari');

async function main() {
  const rtp = new RtpServer();
  await rtp.listen();
  console.log(`[randevu-ai] RTP dinliyor ${config.externalMedia.host}:${config.externalMedia.port}`);

  const ari = await ariClient.connect(config.ari.url, config.ari.user, config.ari.pass);

  const sessions = new Map();

  ari.on('StasisStart', async (event, channel) => {
    // External media kanali da bu app'e StasisStart uretir; onu atla.
    if (channel.name && channel.name.startsWith('UnicastRTP')) return;
    console.log(`[randevu-ai] Yeni cagri: ${channel.id} (${channel.caller && channel.caller.number})`);
    const session = new CallSession(ari, channel, rtp);
    sessions.set(channel.id, session);
    try {
      await session.start();
    } catch (e) {
      console.error('[randevu-ai] cagri hatasi:', e.message);
      try { await channel.continueInDialplan({ context: 'operator-bagla', extension: 's', priority: 1 }); } catch (_) {}
    }
  });

  ari.on('StasisEnd', (event, channel) => {
    const s = sessions.get(channel.id);
    if (s) { s._cleanup().catch(() => {}); sessions.delete(channel.id); }
  });

  await ari.start(config.ari.app);
  console.log(`[randevu-ai] ARI app '${config.ari.app}' hazir.`);
}

main().catch(e => { console.error('[randevu-ai] baslatma hatasi:', e); process.exit(1); });
