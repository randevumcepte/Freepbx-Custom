#!/usr/bin/env node
// Mobil softphone iOS VoIP push gonderici (bagimliliksiz: node http2 + crypto).
//
// Cagiran: softphoneVoipPush.php (AGI). Girdi stdin JSON:
//   { "hedefler": [{"token": "...", "bundle": "com.x.y"}],
//     "veri": {"uuid": "...", "numara": "0555...", "arayan": "Ad"} }
// Cikti stdout JSON: [{"token","durum":"ok|gecersiz|hata","ortam","status","neden"}]
//
// APNs anahtari: /etc/asterisk/softphone_apns.json varsa oradan
//   {"varsayilan": {"keyPath": "...p8", "keyId": "...", "teamId": "..."},
//    "<bundle id>": {...}}   // farkli Apple hesabindaki marka icin
// yoksa asagidaki varsayilan (Team 24DZ882DDK APNs anahtari 7BXAX2SN7V).
//
// Once production, BadDeviceToken ise sandbox denenir (Xcode/debug build).
// JWT 40 dk cache'lenir (Apple sik JWT uretimini 429 ile reddeder).
'use strict';
const http2 = require('http2');
const crypto = require('crypto');
const fs = require('fs');

const VARSAYILAN = {
  keyPath: '/opt/apns-push/AuthKey_7BXAX2SN7V.p8',
  keyId: '7BXAX2SN7V',
  teamId: '24DZ882DDK',
};

function hesap(bundle) {
  try {
    const c = JSON.parse(fs.readFileSync('/etc/asterisk/softphone_apns.json', 'utf8'));
    return c[bundle] || c.varsayilan || VARSAYILAN;
  } catch (e) {
    return VARSAYILAN;
  }
}

const b64url = (buf) =>
  Buffer.from(buf).toString('base64').replace(/=+$/, '').replace(/\+/g, '-').replace(/\//g, '_');

function jwt(h) {
  const cache = '/tmp/rc_sp_apns_jwt_' + h.keyId + '.json';
  try {
    const c = JSON.parse(fs.readFileSync(cache, 'utf8'));
    if (c.teamId === h.teamId && Date.now() / 1000 - c.iat < 2400) return c.jwt;
  } catch (e) {}
  const iat = Math.floor(Date.now() / 1000);
  const bas = b64url(JSON.stringify({ alg: 'ES256', kid: h.keyId }));
  const yuk = b64url(JSON.stringify({ iss: h.teamId, iat }));
  const imza = crypto.sign('sha256', Buffer.from(bas + '.' + yuk), {
    key: fs.readFileSync(h.keyPath),
    dsaEncoding: 'ieee-p1363',
  });
  const t = bas + '.' + yuk + '.' + b64url(imza);
  try {
    fs.writeFileSync(cache, JSON.stringify({ jwt: t, iat, teamId: h.teamId }), { mode: 0o600 });
  } catch (e) {}
  return t;
}

function gonder(host, token, bundle, govde, auth) {
  return new Promise((bitti) => {
    let tamam = false;
    const son = (r) => { if (!tamam) { tamam = true; try { c.close(); } catch (e) {} bitti(r); } };
    const c = http2.connect('https://' + host);
    c.on('error', (e) => son({ status: 0, neden: String(e.message) }));
    const req = c.request({
      ':method': 'POST',
      ':path': '/3/device/' + token,
      authorization: 'bearer ' + auth,
      'apns-topic': bundle + '.voip',
      'apns-push-type': 'voip',
      'apns-priority': '10',
      'apns-expiration': '0',
      'content-type': 'application/json',
    });
    let status = 0;
    let veri = '';
    req.setEncoding('utf8');
    req.on('response', (h) => { status = h[':status']; });
    req.on('data', (x) => { veri += x; });
    req.on('end', () => {
      let neden = '';
      try { neden = JSON.parse(veri).reason || ''; } catch (e) {}
      son({ status, neden });
    });
    req.on('error', (e) => son({ status: 0, neden: String(e.message) }));
    req.setTimeout(5000, () => { try { req.close(); } catch (e) {} son({ status: 0, neden: 'timeout' }); });
    req.end(JSON.stringify(govde));
  });
}

async function birHedef(h, veri) {
  const ayar = hesap(h.bundle);
  const govde = { aps: {}, uuid: veri.uuid, numara: veri.numara || '', arayan: veri.arayan || '' };
  let auth;
  try { auth = jwt(ayar); } catch (e) {
    return { token: h.token, durum: 'hata', ortam: '', status: 0, neden: 'anahtar: ' + e.message };
  }
  let r = await gonder('api.push.apple.com', h.token, h.bundle, govde, auth);
  let ortam = 'production';
  if (r.status === 400 && (r.neden === 'BadDeviceToken' || r.neden === 'DeviceTokenNotForTopic')) {
    const s = await gonder('api.sandbox.push.apple.com', h.token, h.bundle, govde, auth);
    if (s.status === 200 || s.status === 410) { r = s; ortam = 'sandbox'; }
  }
  let durum = 'hata';
  if (r.status === 200) durum = 'ok';
  else if (r.status === 410 || r.neden === 'BadDeviceToken' || r.neden === 'Unregistered') durum = 'gecersiz';
  return { token: h.token, durum, ortam, status: r.status, neden: r.neden };
}

let girdi = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', (x) => { girdi += x; });
process.stdin.on('end', async () => {
  let j;
  try { j = JSON.parse(girdi); } catch (e) { console.log('[]'); return; }
  const sonuc = await Promise.all((j.hedefler || []).map((h) => birHedef(h, j.veri || {})));
  console.log(JSON.stringify(sonuc));
  process.exit(0);
});
setTimeout(() => { console.log('[]'); process.exit(0); }, 9000).unref();
