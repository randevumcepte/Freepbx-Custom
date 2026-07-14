'use strict';
const axios = require('axios');
const config = require('./config');

// Cagri basinda salon/musteri/hizmet baglamini yukler. Mevcut karsilama_yeni_2.php ile AYNI
// API'yi kullanir: POST /api/v1/santralkarsilamametni { callerid, channel }.
// Donen yaniti Dialog'un bekledigi sekle map ederiz.
//
// TODO: Alan adlarini canli yanitla birebir dogrula (salon_id, user_id, hizmetler[].* ...).
// Karsilama yaniti HIZMETLER'i /tmp json dosyasi uzerinden veriyordu; API dogrudan da
// dondurebiliyor. Asagidaki map esnek tutuldu.
async function loadCallContext(callerId, did) {
  const { data } = await axios.post(`${config.api.base}/api/v1/santralkarsilamametni`,
    { callerid: callerId, channel: did },
    { headers: { 'Content-Type': 'application/json' }, timeout: 15000 });

  const hizmetler = (data.hizmetler || []).map(h => ({
    salonHizmetId: h.salonHizmetId ?? h.salon_hizmet_id ?? h.id,
    ad: h.hizmetAdi ?? h.ad ?? h.hizmet_adi,
    sureDk: h.sureDk ?? h.sure_dk ?? h.sure,
    fiyat: h.fiyat,
    personeller: (h.personeller || []).map(p => ({ id: p.id ?? p.personel_id, ad: p.personel_adi ?? p.ad })),
  }));

  return {
    salonAdi: data.salonAdi ?? data.salon_adi ?? 'Salonumuz',
    salonId: data.salon_id ?? data.salonId,
    userId: data.user_id ?? data.userId ?? null,
    musteriAdi: data.musteriAdi ?? data.musteri_adi ?? null,
    operatorKanali: data.operator_kanali ?? data.operatorKanali ?? null,
    hizmetler,
  };
}

module.exports = { loadCallContext };
