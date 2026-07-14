'use strict';
const axios = require('axios');
const config = require('./config');

// LLM'e verilen tool tanimlari. Model YALNIZCA salonHizmetId + tarih/saat + (opsiyonel)
// personelId secer. salonId/userId/odaid gibi hassas alanlari model URETMEZ — tool bunlari
// cagri baglamindan (ctx) ve backend uygunluk yanitindan alir.
function toolDefinitions() {
  return [
    {
      name: 'uygun_randevu_bul',
      description:
        'Verilen hizmet, tarih-saat (ve opsiyonel personel) icin uygun randevu slotunu backend\'den sorar. ' +
        'randevu_olustur\'dan ONCE mutlaka bu cagrilir. Backend uygun degilse en yakin alternatifi doner.',
      input_schema: {
        type: 'object',
        properties: {
          salonHizmetId: { type: 'integer', description: 'Sistem promptundaki hizmet listesinden secilen id' },
          tarihSaat: { type: 'string', description: 'Musterinin istedigi tarih-saat, "YYYY-MM-DD HH:mm" formatinda (Turkiye saati)' },
          personelId: { type: ['integer', 'null'], description: 'Musteri personel belirttiyse id; farketmez ise null' },
        },
        required: ['salonHizmetId', 'tarihSaat'],
        additionalProperties: false,
      },
    },
    {
      name: 'randevu_olustur',
      description:
        'Musteri ONAYLADIKTAN sonra randevuyu olusturur. Yalnizca daha once uygun_randevu_bul ile ' +
        'dogrulanmis bir hizmet+tarih icin cagrilabilir. oda/personel backend yanitindan otomatik alinir.',
      input_schema: {
        type: 'object',
        properties: {
          salonHizmetId: { type: 'integer' },
          tarihSaat: { type: 'string', description: 'Onaylanan uygun slot, "YYYY-MM-DD HH:mm"' },
        },
        required: ['salonHizmetId', 'tarihSaat'],
        additionalProperties: false,
      },
    },
    {
      name: 'operatore_aktar',
      description: 'Gorusmeyi canli operatore/isletmeye aktarir. Anlasilamama, musteri talebi, hata veya sinirli musteri durumunda.',
      input_schema: { type: 'object', properties: { sebep: { type: 'string' } }, required: [], additionalProperties: false },
    },
    {
      name: 'arama_kapat',
      description: 'Islem tamamlandiginda (randevu olusturuldu / musteri vazgecti) gorusmeyi kibarca sonlandirir.',
      input_schema: { type: 'object', properties: { sebep: { type: 'string' } }, required: [], additionalProperties: false },
    },
  ];
}

function splitTarihSaat(ts) {
  // "YYYY-MM-DD HH:mm" veya "YYYY-MM-DD HH:mm:ss"
  const [d, t = '00:00'] = String(ts).trim().split(/[ T]/);
  const saat = t.length === 5 ? `${t}:00` : t;
  return { tarih: d, saat };
}

async function uygunRandevuBul(input, ctx) {
  const hizmet = (ctx.hizmetler || []).find(h => Number(h.salonHizmetId) === Number(input.salonHizmetId));
  if (!hizmet) return { ok: false, forModel: 'Bu salonHizmetId listede yok. Gecerli bir hizmet sec.' };

  // Cevrimdisi test (npm run dialog): ag yok, gercek randevu yok.
  if (ctx.stub) {
    const slot = {
      salonHizmetId: input.salonHizmetId, tarihSaat: input.tarihSaat,
      personelId: input.personelId ?? 101, odaId: 7, sureDk: hizmet.sureDk,
      fiyat: hizmet.fiyat, alternatif: false,
    };
    ctx.lastAvailability.set(Number(input.salonHizmetId), slot);
    return { ok: true, forModel: JSON.stringify({ uygunTarihSaat: slot.tarihSaat, alternatifOneri: false, not: '(STUB) uygun. Kisa onay iste.' }) };
  }

  const payload = {
    salonHizmetId: input.salonHizmetId,
    salonId: ctx.salonId,
    tarihSaat: input.tarihSaat,
    personelId: input.personelId ?? null,
    paketBilgi: null, // TODO: paket akisi
  };

  const { data } = await axios.post(`${config.api.base}/api/v1/randevuUygunlukKontrolEt`, payload, {
    headers: { 'Content-Type': 'application/json' }, timeout: 15000,
  });

  if (!data || !data.success) {
    return { ok: false, forModel: 'Backend uygun randevu bulamadi. Musteriden baska bir tarih iste.' };
  }

  // Backend'in atadigi oda/personel/uygun slotu cagri baglaminda sakla —
  // randevu_olustur bunlari model'e sordurmadan kullanacak.
  const slot = {
    salonHizmetId: input.salonHizmetId,
    tarihSaat: data.tarihsaat || input.tarihSaat,
    personelId: data.personelid ?? input.personelId ?? null,
    odaId: (data.odaid === '' || data.odaid == null) ? null : data.odaid,
    sureDk: hizmet.sureDk,
    fiyat: hizmet.fiyat,
    alternatif: !!data.alternatifOneri,
  };
  ctx.lastAvailability.set(Number(input.salonHizmetId), slot);

  return {
    ok: true,
    forModel: JSON.stringify({
      uygunTarihSaat: slot.tarihSaat,
      alternatifOneri: slot.alternatif,
      not: slot.alternatif
        ? 'Istenen saat dolu; bu en yakin uygun slot. Musteriye SOYLE ve onay iste.'
        : 'Istenen saat uygun. Kisa onay iste.',
    }),
  };
}

async function randevuOlustur(input, ctx) {
  const slot = ctx.lastAvailability.get(Number(input.salonHizmetId));
  if (!slot) {
    return { ok: false, forModel: 'Once uygun_randevu_bul cagirmalisin (bu hizmet icin dogrulanmis slot yok).' };
  }
  const { tarih, saat } = splitTarihSaat(slot.tarihSaat);

  if (ctx.stub) {
    return { ok: true, forModel: `(STUB) Randevu olusturuldu: ${tarih} ${saat}, personel #${slot.personelId}, oda #${slot.odaId}. Teyit et ve arama_kapat cagir.` };
  }

  // DOGRU boyutlu diziler: santral tarafinda tek-elemanli dizi gonderilip cok-hizmette
  // 2.+ kalem NULL kaliyordu (bkz. Batch 4 #12). Tek hizmet icin dizi zaten 1 eleman;
  // paket/coklu icin array_fill mantigi ile N elemana esitlenmeli (TODO: paket akisi).
  const payload = {
    easistan: 1,
    olusturan_user_id: ctx.userId,
    salon_id: ctx.salonId,
    user_id: ctx.userId,
    durum: 0,
    hizmetler: [input.salonHizmetId],
    randevuPersonelleri: [slot.personelId],
    tarih,
    saat,
    hizmetSuresi: [slot.sureDk],
    hizmetFiyati: [slot.fiyat],
    randevuOdalari: [slot.odaId],
    paketBilgi: null,
  };

  const { data } = await axios.post(`${config.api.base}/api/v1/santralRandevuEkle`, payload, {
    headers: { 'Content-Type': 'application/json' }, timeout: 15000,
  });

  if (!data || data.success === false) {
    return { ok: false, forModel: 'Randevu olusturulamadi. Operatore aktarmayi one­r ya da tekrar dene.' };
  }
  return { ok: true, forModel: 'Randevu basariyla olusturuldu. Musteriye kisaca teyit et ve arama_kapat cagir.' };
}

// name -> impl. Donen: { toModel: string(is_error?), control?: 'transfer'|'hangup' }
async function executeTool(name, input, ctx) {
  try {
    if (name === 'uygun_randevu_bul') {
      const r = await uygunRandevuBul(input, ctx);
      return { toModel: r.forModel, isError: !r.ok };
    }
    if (name === 'randevu_olustur') {
      const r = await randevuOlustur(input, ctx);
      return { toModel: r.forModel, isError: !r.ok };
    }
    if (name === 'operatore_aktar') {
      ctx.control = 'transfer';
      return { toModel: 'Operatore aktariliyor. Kisa bir kapanis cumlesi soyle.' };
    }
    if (name === 'arama_kapat') {
      ctx.control = 'hangup';
      return { toModel: 'Gorusme kapatiliyor.' };
    }
    return { toModel: `Bilinmeyen tool: ${name}`, isError: true };
  } catch (err) {
    // API/ag hatasi: model'e bildir, dongude tikanma; guvenli taraf operatore aktarma.
    return { toModel: `Tool hatasi (${name}): ${err.message}. Musteriyi operatore aktar.`, isError: true };
  }
}

module.exports = { toolDefinitions, executeTool };
