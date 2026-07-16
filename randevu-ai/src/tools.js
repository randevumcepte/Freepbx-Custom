'use strict';
const axios = require('axios');
const config = require('./config');

// ── Tek kaynak tool spec'leri. Buradan hem Anthropic (input_schema) hem Ollama/OpenAI
//    (function.parameters) formati uretilir. Model YALNIZCA slot secer; salonId/userId/oda
//    gibi hassas alanlari uretmez (tool bunlari cagri baglamindan alir).
const SPECS = [
  {
    name: 'hizmet_ara',
    description: 'Musterinin soyledigi hizmet adini salonun hizmet katalogunda arar, en yakin adaylari (salonHizmetId + ad) doner. Randevu OLUSTURMAK icin hizmeti bulmak uzere ONCE bunu cagir.',
    schema: { type: 'object', properties: { metin: { type: 'string', description: 'Musterinin soyledigi hizmet ifadesi' } }, required: ['metin'] },
  },
  {
    name: 'uygun_randevu_bul',
    description: 'Verilen tarih-saat icin uygun slotu backend\'den sorar. YENI randevuda randevu_olustur\'dan, GUNCELLEMEde randevu_guncelle\'den ONCE cagrilir. Guncelleme ise randevuId ver (hizmet/personel gerekmez).',
    schema: {
      type: 'object',
      properties: {
        salonHizmetId: { type: 'integer', description: 'YENI randevu: hizmet listesinden secilen id (paketten/guncelleme ise bos birak)' },
        tarihSaat: { type: 'string', description: 'Istenen tarih-saat, "YYYY-MM-DD HH:mm" (Turkiye saati)' },
        personelId: { type: 'integer', description: 'Musteri personel belirttiyse id; farketmez ise verme' },
        paketten: { type: 'boolean', description: 'Musterinin paketinden yeni randevu ise true' },
        randevuId: { type: 'integer', description: 'GUNCELLEME (erteleme) ise mevcut randevunun id\'si; yeni olusturmada verme' },
      },
      required: ['tarihSaat'],
    },
  },
  {
    name: 'randevu_olustur',
    description: 'Musteri ONAYLADIKTAN sonra randevuyu olusturur. Yalnizca uygun_randevu_bul ile dogrulanmis tarih icin. oda/personel backend yanitindan otomatik alinir.',
    schema: {
      type: 'object',
      properties: {
        salonHizmetId: { type: 'integer' },
        tarihSaat: { type: 'string', description: 'Onaylanan uygun slot, "YYYY-MM-DD HH:mm"' },
        paketten: { type: 'boolean', description: 'Paketten randevu ise true' },
      },
      required: ['tarihSaat'],
    },
  },
  {
    name: 'randevu_guncelle',
    description: 'Randevuyu, uygun_randevu_bul(randevuId=...) ile dogrulanip ONAYLANAN yeni slota gunceller. Once uygun_randevu_bul(randevuId=...) cagirilmis ve onay alinmis olmalidir.',
    schema: {
      type: 'object',
      properties: {
        randevuId: { type: 'integer', description: 'Guncellenecek randevunun id\'si (uygun_randevu_bul\'daki ile ayni)' },
      },
      required: ['randevuId'],
    },
  },
  {
    name: 'randevu_iptal',
    description: 'Mevcut bir randevuyu iptal eder. randevuId mevcut randevular listesinden secilir. Once musteriden ONAY al.',
    schema: {
      type: 'object',
      properties: { randevuId: { type: 'integer', description: 'Iptal edilecek randevunun id\'si' } },
      required: ['randevuId'],
    },
  },
  { name: 'operatore_aktar', description: 'Gorusmeyi canli operatore/isletmeye aktarir (anlasilamama, musteri talebi, hata, sinirli musteri).',
    schema: { type: 'object', properties: { sebep: { type: 'string' } } } },
  { name: 'arama_kapat', description: 'Islem bitince (randevu olustu/guncellendi/iptal edildi ya da musteri vazgecti) gorusmeyi kibarca sonlandirir.',
    schema: { type: 'object', properties: { sebep: { type: 'string' } } } },
];

function toolDefinitions() {
  return SPECS.map(s => ({ name: s.name, description: s.description, input_schema: s.schema }));
}
function toolDefinitionsOpenAI() {
  return SPECS.map(s => ({ type: 'function', function: { name: s.name, description: s.description, parameters: s.schema } }));
}

function splitTarihSaat(ts) {
  const [d, t = '00:00'] = String(ts).trim().split(/[ T]/);
  return { tarih: d, saat: t.length === 5 ? `${t}:00` : t };
}

function turkceNorm(s) {
  return String(s || '').toLowerCase()
    .replace(/ç/g, 'c').replace(/ğ/g, 'g').replace(/ı/g, 'i').replace(/İ/g, 'i')
    .replace(/ö/g, 'o').replace(/ş/g, 's').replace(/ü/g, 'u')
    .replace(/[^a-z0-9 ]/g, ' ').replace(/\s+/g, ' ').trim();
}

// Musterinin dedigi hizmeti katalogda fuzzy arar (hizmetler promptta DEGIL, burada eslesir).
function hizmetAra(input, ctx) {
  const q = turkceNorm(input.metin);
  if (!q) return { toModel: 'Bos ifade. Musteriden hizmeti tekrar iste.' };
  const qTokens = q.split(' ').filter(Boolean);
  const scored = (ctx.hizmetler || []).map(h => {
    const n = turkceNorm(h.ad);
    let score = 0;
    if (n === q) score = 100;
    else if (n && (n.includes(q) || q.includes(n))) score = 90;
    else {
      const nTokens = n.split(' ');
      const hit = qTokens.filter(t => t.length > 2 && nTokens.some(x => x.includes(t) || t.includes(x))).length;
      score = qTokens.length ? Math.round((hit / qTokens.length) * 80) : 0;
    }
    return { h, score };
  }).filter(x => x.score >= 45).sort((a, b) => b.score - a.score).slice(0, 5);
  if (!scored.length) return { toModel: `ESLESME YOK. Musteriye "maalesef bu hizmeti veremiyoruz" de ve baska bir hizmet isteyip istemedigini sor.` };
  const top = scored[0];
  const others = scored.slice(1).map(x => `${x.h.salonHizmetId}:"${x.h.ad}"`).join(', ');
  return { toModel: `SECILEN hizmet: salonHizmetId=${top.h.salonHizmetId} "${top.h.ad}". Bunu KULLAN; benzer isimler icin musteriye "hangisi" diye SORMA. Simdi tarih-saat ile uygun_randevu_bul cagir.${others ? ' (bilgi, digerleri: ' + others + ')' : ''}` };
}

// ── uygun_randevu_bul ────────────────────────────────────────────────────────
async function uygunRandevuBul(input, ctx) {
  // GUNCELLEME (reschedule): randevuId ile; backend hizmeti randevudan turetir ve mevcut
  // randevuyu cakismadan haric tutar. hizmet/personel gerekmez (menu akisiyla birebir).
  const reschedule = input.randevuId != null && input.randevuId !== '';
  if (reschedule) {
    if (ctx.stub) {
      ctx.lastAvailability.set('slot', { tarihSaat: input.tarihSaat, alternatif: false, randevuId: input.randevuId });
      return { toModel: JSON.stringify({ uygunTarihSaat: input.tarihSaat, alternatifOneri: false, not: '(STUB) uygun. Onay iste, sonra randevu_guncelle.' }) };
    }
    const { data } = await axios.post(`${config.api.base}/api/v1/randevuUygunlukKontrolEt`, {
      salonHizmetId: null, salonId: ctx.salonId, tarihSaat: input.tarihSaat,
      personelId: null, paketBilgi: null, randevuId: input.randevuId,
    }, { headers: { 'Content-Type': 'application/json' }, timeout: 15000 });
    if (!data || !data.success) return { toModel: 'Bu tarih icin uygun degil. Musteriden baska bir tarih iste.', isError: true };
    const slot = { tarihSaat: data.tarihsaat || input.tarihSaat, alternatif: !!data.alternatifOneri, randevuId: input.randevuId };
    ctx.lastAvailability.set('slot', slot);
    return { toModel: JSON.stringify({
      uygunTarihSaat: slot.tarihSaat, alternatifOneri: slot.alternatif,
      not: slot.alternatif ? 'Istenen saat dolu; en yakin uygun bu. Musteriye SOYLE ve onay iste, sonra randevu_guncelle.' : 'Uygun. Onay iste, sonra randevu_guncelle.',
    }) };
  }

  const paketten = !!input.paketten && ctx.paket;
  let salonHizmetId = input.salonHizmetId;
  let hizmet = null;
  if (paketten) {
    salonHizmetId = ctx.paket.salonHizmetId ?? salonHizmetId ?? (ctx.paket.hizmetler && ctx.paket.hizmetler[0] && (ctx.paket.hizmetler[0].salon_hizmet_id ?? ctx.paket.hizmetler[0].hizmet_id));
  } else {
    hizmet = (ctx.hizmetler || []).find(h => Number(h.salonHizmetId) === Number(salonHizmetId));
    if (!hizmet) return { toModel: 'Bu salonHizmetId listede yok. Gecerli bir hizmet sec.', isError: true };
  }

  if (ctx.stub) {
    const slot = { salonHizmetId, tarihSaat: input.tarihSaat, personelId: input.personelId ?? 101, odaId: 7,
      sureDk: hizmet ? hizmet.sureDk : 60, fiyat: hizmet ? hizmet.fiyat : 0, alternatif: false, paketten };
    ctx.lastAvailability.set('slot', slot);
    return { toModel: JSON.stringify({ uygunTarihSaat: slot.tarihSaat, alternatifOneri: false, not: '(STUB) uygun. Kisa onay iste.' }) };
  }

  const { data } = await axios.post(`${config.api.base}/api/v1/randevuUygunlukKontrolEt`, {
    salonHizmetId, salonId: ctx.salonId, tarihSaat: input.tarihSaat,
    personelId: input.personelId ?? null, paketBilgi: paketten ? ctx.paket : null,
  }, { headers: { 'Content-Type': 'application/json' }, timeout: 15000 });

  if (!data || !data.success) return { toModel: 'Backend uygun randevu bulamadi. Musteriden baska bir tarih iste.', isError: true };

  const slot = {
    salonHizmetId, tarihSaat: data.tarihsaat || input.tarihSaat,
    personelId: data.personelid ?? input.personelId ?? null,
    odaId: (data.odaid === '' || data.odaid == null) ? null : data.odaid,
    sureDk: hizmet ? hizmet.sureDk : null, fiyat: hizmet ? hizmet.fiyat : null,
    alternatif: !!data.alternatifOneri, paketten,
  };
  ctx.lastAvailability.set('slot', slot);
  // Ozet icin personel adini coz (hizmetin personeller listesinden)
  let personelAd = null;
  if (slot.personelId && hizmet) {
    const p = (hizmet.personeller || []).find(pp => Number(pp.id) === Number(slot.personelId));
    if (p) personelAd = p.ad;
  }
  const ozetNot = slot.alternatif
    ? 'Istenen saat dolu; en yakin uygun bu. Musteriye tarih-saat-hizmet(-personel) OZETINI ver ve onay iste, sonra randevu_olustur.'
    : 'Uygun. Musteriye tarih-saat-hizmet(-personel) OZETINI ver ve onay iste, sonra randevu_olustur.';
  return { toModel: JSON.stringify({
    uygunTarihSaat: slot.tarihSaat, alternatifOneri: slot.alternatif,
    hizmetAdi: hizmet ? hizmet.ad : null, personel: personelAd, not: ozetNot,
  }) };
}

// ── randevu_olustur ──────────────────────────────────────────────────────────
async function randevuOlustur(input, ctx) {
  const slot = ctx.lastAvailability.get('slot');
  if (!slot) return { toModel: 'Once uygun_randevu_bul cagirmalisin (dogrulanmis slot yok).', isError: true };
  const { tarih, saat } = splitTarihSaat(slot.tarihSaat);
  const paketten = !!input.paketten || slot.paketten;

  if (ctx.stub) {
    return { toModel: `(STUB) Randevu olusturuldu: ${tarih} ${saat}${paketten ? ' (paketten)' : ''}, personel #${slot.personelId}, oda #${slot.odaId}. Teyit et ve arama_kapat cagir.` };
  }

  // Cok-hizmet (paket) icin diziler hizmet sayisina esitlenir (Batch 4 #12'nin dogru hali).
  let hizmetler, n;
  if (paketten && ctx.paket && Array.isArray(ctx.paket.hizmetler)) {
    hizmetler = ctx.paket.hizmetler.map(h => h.hizmet_id ?? h.salon_hizmet_id ?? h);
    n = hizmetler.length || 1;
  } else {
    hizmetler = [slot.salonHizmetId]; n = 1;
  }
  const fill = (v) => Array.from({ length: n }, () => v);

  const payload = {
    easistan: 1, olusturan_user_id: ctx.userId, salon_id: ctx.salonId, user_id: ctx.userId, durum: 0,
    hizmetler,
    randevuPersonelleri: fill(slot.personelId),
    tarih, saat,
    hizmetSuresi: fill(slot.sureDk),
    hizmetFiyati: fill(slot.fiyat),
    randevuOdalari: fill(slot.odaId),
    paketBilgi: paketten ? ctx.paket : null,
  };

  const { data } = await axios.post(`${config.api.base}/api/v1/santralRandevuEkle`, payload, { headers: { 'Content-Type': 'application/json' }, timeout: 15000 });
  if (!data || data.success === false) return { toModel: 'Randevu olusturulamadi. Operatore aktar.', isError: true };
  return { toModel: 'Randevu basariyla olusturuldu. Musteriye kisaca teyit et ve arama_kapat cagir.' };
}

// ── randevu_guncelle ─────────────────────────────────────────────────────────
async function randevuGuncelle(input, ctx) {
  // Menu akisiyla ayni: yalnizca uygun_randevu_bul(randevuId) ile DOGRULANMIS slota gunceller.
  const slot = ctx.lastAvailability.get('slot');
  if (!slot || !slot.tarihSaat) {
    return { toModel: 'Once uygun_randevu_bul(randevuId=...) ile yeni tarihi dogrula ve onay al.', isError: true };
  }
  const { tarih, saat } = splitTarihSaat(slot.tarihSaat);
  if (ctx.stub) return { toModel: `(STUB) Randevu #${input.randevuId} guncellendi -> ${tarih} ${saat}. Teyit et ve arama_kapat cagir.` };
  const { data } = await axios.post(`${config.api.base}/api/v1/randevuyuenyakintariheguncelle`, {
    randevuid: input.randevuId, randevutarihi: tarih, randevusaati: saat,
  }, { headers: { 'Content-Type': 'application/json' }, timeout: 15000 });
  if (!data) return { toModel: 'Guncelleme basarisiz. Operatore aktar.', isError: true };
  return { toModel: `Randevu ${tarih} ${saat} olarak guncellendi. Musteriye teyit et ve arama_kapat cagir.` };
}

// ── randevu_iptal ────────────────────────────────────────────────────────────
async function randevuIptal(input, ctx) {
  if (ctx.stub) return { toModel: `(STUB) Randevu #${input.randevuId} iptal edildi. Teyit et ve arama_kapat cagir.` };
  const { data } = await axios.post(`${config.api.base}/api/v1/asistanRandevuIptalEt`, { randevuid: input.randevuId }, { headers: { 'Content-Type': 'application/json' }, timeout: 15000 });
  if (!data || data.success === false) return { toModel: 'Iptal basarisiz. Operatore aktar.', isError: true };
  return { toModel: 'Randevu iptal edildi. Musteriye teyit et ve arama_kapat cagir.' };
}

async function executeTool(name, input, ctx) {
  try {
    input = input || {};
    switch (name) {
      case 'hizmet_ara':        return hizmetAra(input, ctx);
      case 'uygun_randevu_bul': return await uygunRandevuBul(input, ctx);
      case 'randevu_olustur':   return await randevuOlustur(input, ctx);
      case 'randevu_guncelle':  return await randevuGuncelle(input, ctx);
      case 'randevu_iptal':     return await randevuIptal(input, ctx);
      case 'operatore_aktar':   ctx.control = 'transfer'; return { toModel: 'Operatore aktariliyor. Kisa bir kapanis cumlesi soyle.' };
      case 'arama_kapat':       ctx.control = 'hangup';   return { toModel: 'Gorusme kapatiliyor.' };
      default: return { toModel: `Bilinmeyen tool: ${name}`, isError: true };
    }
  } catch (err) {
    return { toModel: `Tool hatasi (${name}): ${err.message}. Musteriyi operatore aktar.`, isError: true };
  }
}

module.exports = { toolDefinitions, toolDefinitionsOpenAI, executeTool };
