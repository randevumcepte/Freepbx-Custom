'use strict';
const axios = require('axios');
const config = require('./config');

// ── Tek kaynak tool spec'leri. Buradan hem Anthropic (input_schema) hem Ollama/OpenAI
//    (function.parameters) formati uretilir. Model YALNIZCA slot secer; salonId/userId/oda
//    gibi hassas alanlari uretmez (tool bunlari cagri baglamindan alir).
const SPECS = [
  {
    name: 'uygun_randevu_bul',
    description: 'Verilen hizmet + tarih-saat (opsiyonel personel) icin uygun slotu backend\'den sorar. randevu_olustur\'dan ONCE cagrilir. Paketten randevu ise paketten=true.',
    schema: {
      type: 'object',
      properties: {
        salonHizmetId: { type: 'integer', description: 'Sistem promptundaki hizmet listesinden secilen id (paketten ise bos birak)' },
        tarihSaat: { type: 'string', description: 'Istenen tarih-saat, "YYYY-MM-DD HH:mm" (Turkiye saati)' },
        personelId: { type: 'integer', description: 'Musteri personel belirttiyse id; farketmez ise verme' },
        paketten: { type: 'boolean', description: 'Musterinin paketinden randevu ise true' },
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
    description: 'Mevcut bir randevuyu yeni tarih-saate gunceller. randevuId sistem promptundaki mevcut randevular listesinden secilir.',
    schema: {
      type: 'object',
      properties: {
        randevuId: { type: 'integer', description: 'Guncellenecek randevunun id\'si' },
        yeniTarihSaat: { type: 'string', description: 'Yeni tarih-saat, "YYYY-MM-DD HH:mm"' },
      },
      required: ['randevuId', 'yeniTarihSaat'],
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

// ── uygun_randevu_bul ────────────────────────────────────────────────────────
async function uygunRandevuBul(input, ctx) {
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
  return { toModel: JSON.stringify({
    uygunTarihSaat: slot.tarihSaat, alternatifOneri: slot.alternatif,
    not: slot.alternatif ? 'Istenen saat dolu; en yakin uygun bu. Musteriye SOYLE ve onay iste.' : 'Uygun. Kisa onay iste.',
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
  const { tarih, saat } = splitTarihSaat(input.yeniTarihSaat);
  if (ctx.stub) return { toModel: `(STUB) Randevu #${input.randevuId} guncellendi -> ${tarih} ${saat}. Teyit et ve arama_kapat cagir.` };
  const { data } = await axios.post(`${config.api.base}/api/v1/randevuyuenyakintariheguncelle`, {
    randevuid: input.randevuId, randevutarihi: tarih, randevusaati: saat,
  }, { headers: { 'Content-Type': 'application/json' }, timeout: 15000 });
  if (!data) return { toModel: 'Guncelleme basarisiz. Operatore aktar.', isError: true };
  return { toModel: 'Randevu guncellendi. Yeni tarihi teyit et ve arama_kapat cagir.' };
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
