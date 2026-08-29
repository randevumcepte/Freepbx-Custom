'use strict';
/**
 * KURAL-TABANLI BEYIN (LLM yok). sesli-randevu-akis.php'deki (turn-based PHP AGI) akisin
 * OLAY-TABANLI portu: her cagri boyunca bir durum makinesi yasar; ari.js her kullanici
 * cumlesi icin run(utterance) cagirir; motor durumu ilerletir ve konusulacak metni doner.
 *
 * Uc secimi: HIBRIT — NLU/musaitlik/olustur/iptal/guncelle = "benim tuning'li uclarim"
 * (WhatsApp+push, easistan=1, durum=0); hizmet/personel + mevcut randevu bilgisi ctx'ten.
 *
 * Engine sozlesmesi: constructor({system, ctx, executeTool}) + async run(userContent,
 * onSentence) -> { text, control }.  control: null | 'transfer' | 'hangup'.
 */
const axios = require('axios');
const config = require('./../config');
const { fold, saatSozcukleriniRakama, niyetBul } = require('./../rulesNlu');

const API = (config.api && config.api.base ? config.api.base : 'https://app.randevumcepte.com.tr') + '/api/v1';

/* ------------------------------------------------------------------ */
/* Kucuk yardimcilar                                                   */
/* ------------------------------------------------------------------ */
const AYLAR = ['', 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
function tarihSozlu(ymd) {
  const p = String(ymd || '').slice(0, 10).split('-');
  if (p.length !== 3) return String(ymd || '');
  const ay = parseInt(p[1], 10);
  return (ay >= 1 && ay <= 12) ? `${parseInt(p[2], 10)} ${AYLAR[ay]}` : String(ymd);
}
function saatSozlu(saat) { return String(saat || '').slice(0, 5).replace(':', ' '); }
function zamanSozlu(tarih, saat) { return `${tarihSozlu(tarih)} saat ${saatSozlu(saat)}`; }

function olumluMu(c) { c = fold(c); return /(^| )(evet|onay|tamam|olur|olustur|kaydet|dogru|aynen|kesinlikle)/.test(c); }
function iptalKomutu(c) { c = fold(c); return /(iptal|vazgec|vaz gec|bosver|bos ver|istemiyorum|kapat|durdur)/.test(c) || c.trim() === 'dur'; }
function kufurMu(c) {
  const n = ' ' + fold(c) + ' ';
  const ks = ['amk', 'aq', 'amina', 'amcik', 'orospu', 'pic', 'siktir', 'sikeyim', 'sikerim', 'yarrak', 'yarak', 'gavat', 'kahpe', 'ibne', 'serefsiz', 'pezevenk', 'gerizekali', 'geri zekali', 'salak', 'aptal', 'yavsak', 'surtuk', 'defol', 'geber', 'namussuz', 'terbiyesiz'];
  return ks.some((k) => n.indexOf(' ' + k + ' ') !== -1);
}
function tesekkurMu(c) {
  c = fold(c);
  if (c.indexOf('randevu') !== -1 || /\d/.test(c)) return false;
  return /(tesekkur|tesekurler|sagol|sag ol|sagolun|eyvallah|gorusuruz|hosca kal|hoscakal|iyi gunler)/.test(c);
}
function isimTemizle(s) {
  const at = new Set(['musteri', 'adina', 'adi', 'isim', 'ismi', 'bey', 'hanim', 'icin', 'lutfen', 'randevu', 've', 'de', 'da', 'ile']);
  return String(s || '').trim().split(/\s+/)
    .filter((w) => { const f = fold(w); return f.length >= 2 && !at.has(f) && !/\d/.test(w); })
    .map((w) => w.charAt(0).toLocaleUpperCase('tr') + w.slice(1))
    .join(' ').trim();
}
function telefonAyikla(s) {
  const rakam = { sifir: '0', bir: '1', iki: '2', uc: '3', dort: '4', bes: '5', alti: '6', yedi: '7', sekiz: '8', dokuz: '9' };
  let d = fold(s).split(/\s+/).map((w) => (rakam[w] != null ? rakam[w] : w)).join('').replace(/[^0-9]/g, '');
  if (d.length === 12 && d.startsWith('90')) d = d.slice(2);
  if (d.length === 10 && d[0] === '5') d = '0' + d;
  return (d.length === 11 && d.startsWith('05')) ? d : null;
}

/* ------------------------------------------------------------------ */
/* HTTP (benim uclarim)                                                */
/* ------------------------------------------------------------------ */
async function cozApi(salonId, metin, saatCevabi = false) {
  try {
    const { data } = await axios.get(`${API}/sesli-randevu-coz`, {
      params: { salonid: salonId, metin: saatSozcukleriniRakama(metin, saatCevabi), tum_personel: '1' },
      timeout: 20000,
    });
    return data && data.basarili ? data : null;
  } catch (_) { return null; }
}
async function musaitlikApi(salonId, personelId, hizmetId, tarih, saat, vakit) {
  try {
    const { data } = await axios.get(`${API}/sesli-randevu-musaitlik`, {
      params: { salonid: salonId, personel_id: personelId, hizmet_id: hizmetId || '0', tarih: tarih || '', saat: saat || '', vakit: vakit || '' },
      timeout: 20000,
    });
    return data || { bulundu: false };
  } catch (_) { return { bulundu: false }; }
}
async function olusturApi(salonId, userId, s, cakismaVar) {
  const payload = {
    randevu_id: s.randevuId || '', user_id: userId,
    randevu_tarihi: s.tarih, randevu_saati: s.saat,
    hizmetler: [{ hizmet_id: s.hizmetId, personel_id: s.personelId, oda_id: '', cihaz_id: '', yardimci_personel: '', sure_dk: s.hizmetSure, fiyat: s.hizmetFiyat, birlestir: '' }],
    yardimcipersoneller: [], tekrarlayan: false, tekrar_sayisi: '', tekrar_sikligi: null,
    notlar: 'Sesli asistan (telefon)', salonid: salonId,
    cakisma_varmi: '', cakisanrandevuekle: cakismaVar ? '1' : '',
    olusturan: null, olusturanMusteri: null, randevuKaynak: 'salon',
    easistan: '1', durum: '0',
  };
  try {
    const { data } = await axios.post(`${API}/randevuekleguncelle`, payload, { headers: { 'Content-Type': 'application/json' }, timeout: 25000 });
    return data || { hata: 'baglanti' };
  } catch (_) { return { hata: 'baglanti' }; }
}
async function iptalApi(randevuId) {
  try {
    const params = new URLSearchParams({ randevuid: String(randevuId), durum: '3' });
    const { data } = await axios.post(`${API}/randevuiptalet`, params.toString(), { headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, timeout: 25000 });
    return data || {};
  } catch (_) { return { hata: 'baglanti' }; }
}
async function musteriRandevulariApi(salonId, userId) {
  try {
    const { data } = await axios.get(`${API}/musteri-randevulari/${encodeURIComponent(userId)}`, { params: { salon_id: salonId }, timeout: 20000 });
    return Array.isArray(data) ? data : [];
  } catch (_) { return []; }
}
async function yeniMusteriApi(salonId, ad, tel) {
  try {
    const params = new URLSearchParams({ salonidler: String(salonId), name: ad, cep_telefon: tel, isletmeadi: '', santraldenkayit: '1' });
    const { data } = await axios.post(`${API}/yenimusteridanisankaydi`, params.toString(), { headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, timeout: 25000 });
    return data || {};
  } catch (_) { return {}; }
}

/* Aktif + yaklasan randevular (durum 0/1, tarih>=bugun), en yakin ilk. */
function aktifRandevular(liste) {
  const bugun = config.istanbulNow();
  const bugunStr = `${bugun.getFullYear()}-${String(bugun.getMonth() + 1).padStart(2, '0')}-${String(bugun.getDate()).padStart(2, '0')}`;
  return (liste || [])
    .filter((r) => { const d = String(r.durum); return d !== '2' && d !== '3'; })
    .filter((r) => String(r.tarih || '').slice(0, 10) >= bugunStr)
    .sort((a, b) => (String(a.tarih) + String(a.saat)).localeCompare(String(b.tarih) + String(b.saat)));
}
function randevuHizmetAdi(r) {
  const h = (r && r.hizmetler && r.hizmetler[0]) || null;
  if (!h) return '';
  return (h.hizmetler && h.hizmetler.hizmet_adi) || h.hizmet_adi || '';
}

/* ------------------------------------------------------------------ */
/* MOTOR                                                               */
/* ------------------------------------------------------------------ */
class RulesEngine {
  constructor({ ctx, executeTool }) {
    this.ctx = ctx || {};
    this.salonId = this.ctx.salonId;
    this.userId = this.ctx.userId || '';
    this.callerId = this.ctx.callerId || this.ctx.callerid || '';
    // Paket akisi ekip arkadaşinin test edilmis tool'larini kullanir (dogru uclar + paketBilgi).
    this.executeTool = executeTool || (async () => ({ toModel: '', isError: true }));
    this.state = 'niyet';
    this.kufurSay = 0;
    this._ilkTur = true; // karsilamadaki paket teklifine "evet" -> paket akisi
    this.dryRun = !!(this.ctx && this.ctx.dryRun); // test: gercek olustur/iptal/kayit YAPMA
    this._resetSlots();
  }

  _resetSlots() {
    this.slots = { hizmetId: null, hizmetAdi: null, hizmetFiyat: '0', hizmetSure: '30', personelId: null, personelAdi: null, tarih: null, saat: null, vakit: null, randevuId: '', guncelleme: false };
    this.hedefRandevu = null;   // iptal/guncelle icin secilen randevu
    this.yeniAd = null;         // yeni musteri
    this._hizmetDeneme = 0;     // hizmet bulunamadi deneme sayaci
    this.paketPersonelId = null; // paket randevusu personeli
  }

  /** coz sonucunu slotlara doldur (dolu alani ezme) — PHP uygula(). */
  _uygula(r) {
    if (!r) return;
    if (r.tarih) this.slots.tarih = r.tarih;
    if (r.saat) this.slots.saat = r.saat;
    if (r.vakit) this.slots.vakit = String(r.vakit);
    const h = (r.hizmetler || [])[0];
    if (h) {
      this.slots.hizmetId = String(h.hizmet_id != null ? h.hizmet_id : '');
      this.slots.hizmetAdi = h.hizmet_adi || this.slots.hizmetAdi;
      this.slots.hizmetFiyat = String(h.fiyat != null ? h.fiyat : this.slots.hizmetFiyat);
      this.slots.hizmetSure = String(h.sure_dk != null ? h.sure_dk : this.slots.hizmetSure);
    }
    const p = r.personel || null;
    const sabit = p && p.sabit === true;
    if (p && !sabit && p.personel_id && String(p.personel_id) !== '0') {
      this.slots.personelId = String(p.personel_id);
      this.slots.personelAdi = p.personel_adi || this.slots.personelAdi;
    }
  }

  /** Ana giris — bir tur. */
  async run(userContent, onSentence) {
    const said = [];
    const say = (t) => { if (t) { said.push(t); if (onSentence) onSentence(t); } };
    let control = null;
    const c = (userContent === '(...)' ? '' : String(userContent || '')).trim();

    // Global: kufur / tesekkur / iptal-komut (durum makinesinden bagimsiz)
    if (c === '') {
      say('Sizi duyamadım, buyurun sizi dinliyorum.');
      return { text: said.join(' '), control };
    }
    if (kufurMu(c)) {
      if (++this.kufurSay >= 2) { say('Bu şekilde devam edemeyeceğim. Görüşmeyi kapatıyorum.'); return { text: said.join(' '), control: 'hangup' }; }
      say('Efendim, sizi saygıya davet ediyorum.');
      return { text: said.join(' '), control };
    }
    if (this.state === 'niyet' && tesekkurMu(c)) {
      say('Rica ederim, bizi tercih ettiğiniz için teşekkür eder, sağlıklı günler dileriz.');
      return { text: said.join(' '), control: 'hangup' };
    }
    if (iptalKomutu(c) && this.state !== 'niyet') {
      this._resetSlots(); this.state = 'niyet';
      say('Tamam, vazgeçtim. Başka bir işlem ister misiniz?');
      return { text: said.join(' '), control };
    }

    try {
      await this._dispatch(c, say);
    } catch (e) {
      say('Bir sorun oluştu, lütfen tekrar söyleyin.');
    }
    if (this.ctx.control) control = this.ctx.control;
    return { text: said.join(' '), control };
  }

  async _dispatch(c, say) {
    switch (this.state) {
      case 'niyet': return this._niyet(c, say);
      case 'b_hizmet': case 'b_personel': case 'b_tarih': case 'b_saat': return this._booking(c, say);
      case 'b_onay': return this._bookingOnay(c, say);
      case 'b_cakisma': return this._cakismaOnay(c, say);
      case 'b_yeni_ad': return this._yeniAd(c, say);
      case 'b_yeni_tel': return this._yeniTel(c, say);
      case 'c_tarih': return this._iptalTarih(c, say);
      case 'c_onay': return this._iptalOnay(c, say);
      case 'g_tarih': return this._guncelleTarih(c, say);
      case 'g_yeni': return this._guncelleYeni(c, say);
      case 'm_personel': return this._musaitlikPersonel(c, say);
      case 'm_teklif': return this._musaitlikTeklif(c, say);
      case 'p_tarih': return this._paketTarih(c, say);
      case 'p_saat': return this._paketSaat(c, say);
      case 'p_onay': return this._paketOnay(c, say);
      default: this.state = 'niyet'; return this._niyet(c, say);
    }
  }

  /* -------- NIYET -------- */
  async _niyet(c, say) {
    const ilk = this._ilkTur; this._ilkTur = false;
    const f = fold(c);
    const paketVar = this.ctx.paket && this.ctx.paket.bekleyenSeans;
    // PAKET: "paket/paketten/paketimden" ya da karsilamadaki paket teklifine "evet".
    if (paketVar && (/paket/.test(f) || (ilk && olumluMu(c) && !/hizmet|hayir|degil|baska/.test(f)))) {
      this._resetSlots(); return this._paketBaslat(c, say);
    }
    if (/paket/.test(f) && !paketVar) {
      this._resetSlots(); this.state = 'b_hizmet';
      say('Aktif bir paketiniz görünmüyor. Dilerseniz normal randevu oluşturabilirim; hangi hizmet için?');
      return;
    }
    const niyet = niyetBul(c);
    if (niyet === 'iptal') { this._resetSlots(); return this._iptalBaslat(c, say); }
    if (niyet === 'guncelle') { this._resetSlots(); return this._guncelleBaslat(c, say); }
    if (niyet === 'musaitlik') { this._resetSlots(); return this._musaitlikBaslat(c, say); }
    if (niyet === 'sorgula') return this._sorgula(say);
    // 'al' -> randevu
    this._resetSlots();
    this._uygula(await cozApi(this.salonId, c));
    return this._bookingIlerle(say);
  }

  /* -------- SORGULA -------- */
  async _sorgula(say) {
    if (!this.userId) { say('Sizi kayıtlarımızda bulamadığım için randevunuza ulaşamıyorum.'); this.state = 'niyet'; return; }
    let liste = this.ctx.enYakinRandevu && this.ctx.enYakinRandevu.length ? this.ctx.enYakinRandevu.map((r) => ({ id: r.randevuId, tarih: r.tarih, saat: r.saat, durum: '1', hizmetler: r.hizmetler })) : null;
    if (!liste) liste = await musteriRandevulariApi(this.salonId, this.userId);
    const aktif = aktifRandevular(liste);
    if (!aktif.length) { say('Yaklaşan bir randevunuz görünmüyor.'); this.state = 'niyet'; return; }
    const ilk = aktif[0];
    const ad = randevuHizmetAdi(ilk);
    const ek = aktif.length > 1 ? ` Ayrıca ${aktif.length - 1} randevunuz daha bulunuyor.` : '';
    say(`En yakın randevunuz ${zamanSozlu(ilk.tarih, ilk.saat)}${ad ? ', ' + ad : ''}.${ek} Başka bir işlem ister misiniz?`);
    this.state = 'niyet';
  }

  /* -------- BOOKING -------- */
  async _bookingIlerle(say) {
    const s = this.slots;
    if (s.hizmetId === null || s.hizmetId === '') { this.state = 'b_hizmet'; say(s.personelAdi ? `${s.personelAdi} isimli personel için hangi hizmet?` : 'Hangi hizmet için randevu oluşturalım?'); return; }
    if (s.personelId === null) { this.state = 'b_personel'; say(s.hizmetAdi ? `${s.hizmetAdi} için hangi personelden randevu istersiniz?` : 'Hangi personelden randevu istersiniz?'); return; }
    if (s.tarih === null && s.vakit === null) { this.state = 'b_tarih'; say('Randevu hangi gün olsun?'); return; }
    if (s.saat === null && s.vakit === null) { this.state = 'b_saat'; say('Saat kaçta olsun? İsterseniz en uygun saati ben ayarlayabilirim.'); return; }
    // Musteri = arayan; yoksa yeni musteri
    if (!this.userId) { this.state = 'b_yeni_ad'; say('Sizi kayıtlarımızda bulamadım. Lütfen adınızı ve soyadınızı söyleyin.'); return; }
    return this._musaitlikVeOnay(say);
  }

  async _booking(c, say) {
    const saatCevabi = this.state === 'b_saat';
    if (this.state === 'b_saat' && /farketmez|fark etmez|sen ayarla|en yakin|ne uygunsa|uygun olan|onemli degil/.test(fold(c))) {
      // saat bos kalsin -> musaitlik en yakini bulur
    } else {
      this._uygula(await cozApi(this.salonId, c, saatCevabi));
    }
    // HIZMET cevabinda eslesme yoksa: "Maalesef X hizmetini veremiyoruz" (jenerik tekrar degil).
    if (this.state === 'b_hizmet' && (this.slots.hizmetId === null || this.slots.hizmetId === '') && c.trim() !== '') {
      this._hizmetDeneme = (this._hizmetDeneme || 0) + 1;
      if (this._hizmetDeneme >= 3) { say('Maalesef istediğiniz hizmeti sistemimizde bulamadım, bu işlemi kapatıyorum. Başka bir işlem ister misiniz?'); this.state = 'niyet'; this._resetSlots(); return; }
      const ad = this._hizmetAdiSoyle(c);
      say(`Maalesef ${ad} hizmetini veremiyoruz. Başka bir hizmet söyleyebilir misiniz?`);
      return; // b_hizmet'te kal
    }
    this._hizmetDeneme = 0;
    return this._bookingIlerle(say);
  }

  /** "saç bakımı istiyorum" -> "saç bakımı" (dolgu kelimeleri kirp, mesajda soyle). */
  _hizmetAdiSoyle(c) {
    const at = new Set(['randevu', 'randevusu', 'istiyorum', 'isterim', 'olsun', 'hizmeti', 'hizmet', 'islem', 'icin', 'lutfen', 'almak', 'alabilir', 'yaptirmak', 'yaptiracagim', 'rica', 'ederim', 'ver', 'verin', 'bir', 'de', 'da']);
    const kelimeler = String(c || '').trim().split(/\s+/).filter((w) => !at.has(fold(w)));
    return kelimeler.join(' ').trim() || String(c || '').trim();
  }

  async _musaitlikVeOnay(say) {
    const s = this.slots;
    say('Uygunluk aranıyor, sizi biraz bekleteceğim efendim.');
    const m = await musaitlikApi(this.salonId, s.personelId, s.hizmetId, s.tarih, s.saat, s.vakit);
    if (!m || m.bulundu !== true) {
      say(m && m.calisma_yok ? 'Bu personelin randevu takvimi açık değil. Başka bir zaman için tekrar deneyin.' : 'Belirttiğiniz tarihlerde müsait bir saat bulamadım. Başka bir işlem ister misiniz?');
      this.state = 'niyet'; this._resetSlots(); return;
    }
    s.tarih = m.tarih; s.saat = m.saat;
    const psz = (s.personelAdi ? `${s.personelAdi} isimli personel` : 'seçtiğiniz personel');
    say(`${s.hizmetAdi}, ${psz}, ${zamanSozlu(s.tarih, s.saat)}. Onaylıyor musunuz?`);
    this.state = 'b_onay';
  }

  async _bookingOnay(c, say) {
    // Guncelleme baglaminda "güncelle/güncelleyin/onayla" da onaydir.
    const onay = olumluMu(c) || (this.slots.guncelleme && /guncelle|onayl|kabul|tamamdir/.test(fold(c)));
    if (onay) return this._randevuYaz(say, false);
    // Duzeltme dene (yeni saat/tarih/hizmet/personel)
    const r = await cozApi(this.salonId, c);
    let degisti = false;
    if (r) {
      if (r.tarih) { this.slots.tarih = r.tarih; degisti = true; }
      if (r.saat) { this.slots.saat = r.saat; this.slots.vakit = null; degisti = true; }
      else if (r.vakit) { this.slots.vakit = String(r.vakit); this.slots.saat = null; degisti = true; }
      const p = r.personel; const sabit = p && p.sabit === true;
      if (p && !sabit && p.personel_id && String(p.personel_id) !== '0' && String(p.personel_id) !== String(this.slots.personelId)) {
        this.slots.personelId = String(p.personel_id); this.slots.personelAdi = p.personel_adi || this.slots.personelAdi;
        this.slots.hizmetId = null; this.slots.hizmetAdi = null; degisti = true;
      }
      const h = (r.hizmetler || [])[0];
      if (h && String(h.hizmet_id) !== String(this.slots.hizmetId)) {
        this.slots.hizmetId = String(h.hizmet_id); this.slots.hizmetAdi = h.hizmet_adi || this.slots.hizmetAdi;
        this.slots.hizmetFiyat = String(h.fiyat != null ? h.fiyat : this.slots.hizmetFiyat); this.slots.hizmetSure = String(h.sure_dk != null ? h.sure_dk : this.slots.hizmetSure); degisti = true;
      }
    }
    if (!degisti) { say(this.slots.guncelleme ? 'Güncellemedim, randevunuz duruyor. Başka bir işlem ister misiniz?' : 'İptal ettim, randevu oluşturulmadı. Başka bir işlem ister misiniz?'); this.state = 'niyet'; this._resetSlots(); return; }
    if (this.slots.hizmetId === null) { this.state = 'b_hizmet'; say('Hangi hizmet için randevu oluşturalım?'); return; }
    return this._musaitlikVeOnay(say);
  }

  async _randevuYaz(say, cakismaVar) {
    say('Randevu oluşturuluyor, lütfen bekleyin.');
    const r = this.dryRun ? { cakismavar: '0', cakisanunsurlar: 'Başarılı' } : await olusturApi(this.salonId, this.userId, this.slots, cakismaVar);
    if (!cakismaVar && (String(r.cakismavar) === '1')) {
      const neden = String(r.cakisanunsurlar || '').replace(/<[^>]*>/g, ' ').trim();
      this._cakismaNeden = neden;
      say(neden && neden.slice(0, 2) !== 'Ba' ? `Bu saat başka randevularla çakışıyor. ${neden}. Yine de oluşturayım mı?` : 'Bu saat başka bir randevuyla çakışıyor. Yine de oluşturayım mı?');
      this.state = 'b_cakisma'; return;
    }
    if (r.hata) { say((this.slots.guncelleme ? 'Randevu güncellenirken' : 'Randevu oluşturulurken') + ' bir sorun oldu. Başka bir işlem ister misiniz?'); this.state = 'niyet'; this._resetSlots(); return; }
    say(this.slots.guncelleme
      ? 'Randevunuzu güncelledim ve size bilgilendirme mesajı ilettim. Başka bir işlem ister misiniz?'
      : 'Randevu talebinizi başarı ile oluşturup size bilgilendirme mesajı ilettim. Başka bir işlem ister misiniz?');
    this.state = 'niyet'; this._resetSlots();
  }

  async _cakismaOnay(c, say) {
    if (olumluMu(c)) return this._randevuYaz(say, true);
    say('İptal ettim, randevu oluşturulmadı. Başka bir işlem ister misiniz?');
    this.state = 'niyet'; this._resetSlots();
  }

  /* -------- YENI MUSTERI -------- */
  async _yeniAd(c, say) {
    const ad = isimTemizle(c);
    if (ad.length < 2) { say('Anlayamadım, adınızı tekrar söyler misiniz?'); return; }
    this.yeniAd = ad; this.state = 'b_yeni_tel';
    say(`${ad}, lütfen on bir haneli telefon numaranızı söyleyin.`);
  }
  async _yeniTel(c, say) {
    let tel = telefonAyikla(c);
    if (!tel && this.callerId) tel = telefonAyikla(this.callerId);
    if (!tel) { say('Numarayı anlayamadım. On bir haneli numaranızı tekrar söyleyin.'); return; }
    const r = this.dryRun ? { userId: '999999' } : await yeniMusteriApi(this.salonId, this.yeniAd, tel);
    if (r && r.userId) { this.userId = String(r.userId); say(`${this.yeniAd} olarak kaydettim.`); return this._musaitlikVeOnay(say); }
    say('Kaydınızı oluşturamadım. Başka bir işlem ister misiniz?'); this.state = 'niyet'; this._resetSlots();
  }

  /* -------- IPTAL -------- */
  async _iptalBaslat(c, say) {
    if (!this.userId) { say('Sizi kayıtlarımızda bulamadığım için randevunuza ulaşamıyorum.'); this.state = 'niyet'; return; }
    const r = await cozApi(this.salonId, c);
    const tarih = r && r.tarih ? r.tarih : null;
    if (!tarih) { this.state = 'c_tarih'; say('Hangi güne ait randevunuzu iptal edelim?'); return; }
    return this._iptalBul(tarih, say);
  }
  async _iptalTarih(c, say) {
    const r = await cozApi(this.salonId, c);
    const tarih = r && r.tarih ? r.tarih : null;
    if (!tarih) { say('Tarihi anlayamadım. Bugün, yarın ya da bir gün söyleyin.'); return; }
    return this._iptalBul(tarih, say);
  }
  async _iptalBul(tarih, say) {
    const liste = await musteriRandevulariApi(this.salonId, this.userId);
    const eslesen = aktifRandevular(liste).filter((r) => String(r.tarih).slice(0, 10) === String(tarih).slice(0, 10));
    if (!eslesen.length) { say(`${tarihSozlu(tarih)} tarihinde randevu bulamadım. Başka bir işlem ister misiniz?`); this.state = 'niyet'; return; }
    this.hedefRandevu = eslesen[0];
    const ad = randevuHizmetAdi(this.hedefRandevu);
    say(`${zamanSozlu(this.hedefRandevu.tarih, this.hedefRandevu.saat)}${ad ? ', ' + ad : ''} randevunuzu iptal edeyim mi?`);
    this.state = 'c_onay';
  }
  async _iptalOnay(c, say) {
    if (!olumluMu(c)) { say('Tamam, iptal etmedim. Başka bir işlem ister misiniz?'); this.state = 'niyet'; return; }
    if (!this.dryRun) await iptalApi(this.hedefRandevu.id || this.hedefRandevu.randevuId);
    say('Randevunuzu iptal ettim. Başka bir işlem ister misiniz?');
    this.state = 'niyet'; this.hedefRandevu = null;
  }

  /* -------- GUNCELLE -------- */
  async _guncelleBaslat(c, say) {
    if (!this.userId) { say('Sizi kayıtlarımızda bulamadığım için randevunuza ulaşamıyorum.'); this.state = 'niyet'; return; }
    const r = await cozApi(this.salonId, c);
    const tarih = r && r.tarih ? r.tarih : null;
    this._guncelleYeniIstek = r; // ayni cumlede yeni saat de gecmis olabilir
    if (!tarih) { this.state = 'g_tarih'; say('Hangi güne ait randevunuzu güncelleyelim?'); return; }
    return this._guncelleBul(tarih, say);
  }
  async _guncelleTarih(c, say) {
    const r = await cozApi(this.salonId, c);
    const tarih = r && r.tarih ? r.tarih : null;
    if (!tarih) { say('Tarihi anlayamadım. Bugün, yarın ya da bir gün söyleyin.'); return; }
    return this._guncelleBul(tarih, say);
  }
  async _guncelleBul(tarih, say) {
    const liste = await musteriRandevulariApi(this.salonId, this.userId);
    const eslesen = aktifRandevular(liste).filter((r) => String(r.tarih).slice(0, 10) === String(tarih).slice(0, 10));
    if (!eslesen.length) { say(`${tarihSozlu(tarih)} tarihinde randevu bulamadım. Başka bir işlem ister misiniz?`); this.state = 'niyet'; return; }
    const rv = eslesen[0]; const h = (rv.hizmetler || [])[0] || {};
    this.slots.randevuId = String(rv.id || rv.randevuId);
    this.slots.hizmetId = String(h.hizmet_id != null ? h.hizmet_id : '');
    this.slots.hizmetAdi = (h.hizmetler && h.hizmetler.hizmet_adi) || h.hizmet_adi || '';
    this.slots.hizmetSure = String(h.sure_dk != null ? h.sure_dk : '30');
    this.slots.hizmetFiyat = String(h.fiyat != null ? h.fiyat : '0');
    this.slots.personelId = String(h.personel_id != null ? h.personel_id : '');
    this.slots.guncelleme = true; this.slots.tarih = null; this.slots.saat = null; this.slots.vakit = null;
    this.state = 'g_yeni';
    say(`${zamanSozlu(rv.tarih, rv.saat)} randevunuzu hangi gün ve saate alalım?`);
  }
  async _guncelleYeni(c, say) {
    this._uygula(await cozApi(this.salonId, c, false));
    if (this.slots.tarih === null && this.slots.vakit === null && this.slots.saat === null) { say('Yeni gün ve saati anlayamadım, tekrar söyler misiniz?'); return; }
    return this._musaitlikVeOnay(say); // randevuId dolu -> olusturApi guncelleme yapar
  }

  /* -------- MUSAITLIK SORGUSU -------- */
  async _musaitlikBaslat(c, say) {
    const r = await cozApi(this.salonId, c);
    if (r) {
      if (r.tarih) this.slots.tarih = r.tarih;
      if (r.saat) this.slots.saat = r.saat;
      if (r.vakit) this.slots.vakit = String(r.vakit);
      const p = r.personel; const sabit = p && p.sabit === true;
      if (p && !sabit && p.personel_id && String(p.personel_id) !== '0') { this.slots.personelId = String(p.personel_id); this.slots.personelAdi = p.personel_adi || null; }
      const h = (r.hizmetler || [])[0]; if (h) this.slots.hizmetId = String(h.hizmet_id);
    }
    if (this.slots.personelId === null) { this.state = 'm_personel'; say('Hangi personelin müsaitliğine bakayım?'); return; }
    return this._musaitlikGoster(say);
  }
  async _musaitlikPersonel(c, say) {
    const r = await cozApi(this.salonId, c);
    const p = r && r.personel; const sabit = p && p.sabit === true;
    if (p && !sabit && p.personel_id && String(p.personel_id) !== '0') { this.slots.personelId = String(p.personel_id); this.slots.personelAdi = p.personel_adi || null; }
    if (r) { if (r.tarih && !this.slots.tarih) this.slots.tarih = r.tarih; if (r.saat && !this.slots.saat) this.slots.saat = r.saat; if (r.vakit && !this.slots.vakit) this.slots.vakit = String(r.vakit); }
    if (this.slots.personelId === null) { say('Hangi personel için baktığımı anlayamadım. Başka bir işlem ister misiniz?'); this.state = 'niyet'; return; }
    return this._musaitlikGoster(say);
  }
  async _musaitlikGoster(say) {
    const s = this.slots;
    const m = await musaitlikApi(this.salonId, s.personelId, s.hizmetId || '0', s.tarih, s.saat, s.vakit);
    if (!m || m.bulundu !== true) { say(m && m.calisma_yok ? 'Bu personelin randevu takvimi açık değil.' : 'Belirttiğiniz zaman aralığında müsait bir saat bulamadım. Başka bir işlem ister misiniz?'); this.state = 'niyet'; return; }
    s.tarih = m.tarih; s.saat = m.saat;
    say(s.personelAdi ? `${s.personelAdi} isimli personel için en yakın müsait saat ${zamanSozlu(m.tarih, m.saat)}.` : `En yakın müsait saat ${zamanSozlu(m.tarih, m.saat)}.`);
    say('Bu saate randevu oluşturmamı ister misiniz?');
    this.state = 'm_teklif';
  }
  async _musaitlikTeklif(c, say) {
    if (!olumluMu(c)) { say('Tamam. Başka bir işlem ister misiniz?'); this.state = 'niyet'; this._resetSlots(); return; }
    // Booking'e gec: personel/tarih/saat hazir; hizmet yoksa sorulur
    if (this.slots.hizmetId === null || this.slots.hizmetId === '0' || this.slots.hizmetId === '') { this.slots.hizmetId = null; }
    return this._bookingIlerle(say);
  }

  /* -------- PAKET RANDEVU (executeTool: uygun_randevu_bul/randevu_olustur, paketten:true) -------- */
  async _paketBaslat(c, say) {
    const paket = this.ctx.paket;
    const r = await cozApi(this.salonId, c); // cumlede tarih/saat/personel gecmis olabilir
    if (r) {
      if (r.tarih) this.slots.tarih = r.tarih;
      if (r.saat) this.slots.saat = r.saat;
      if (r.vakit) this.slots.vakit = String(r.vakit);
      const p = r.personel; const sabit = p && p.sabit === true;
      if (p && !sabit && p.personel_id && String(p.personel_id) !== '0') this.paketPersonelId = String(p.personel_id);
    }
    // Personel verilmediyse paketin personeli (yoksa null -> backend secer).
    if (this.paketPersonelId == null) this.paketPersonelId = (paket.personeller && paket.personeller[0] && paket.personeller[0].id) || null;
    return this._paketIlerle(say);
  }

  _paketIlerle(say) {
    if (this.slots.tarih === null && this.slots.vakit === null) { this.state = 'p_tarih'; say(`${this.ctx.paket.paketAdi} paketinizden randevu oluşturalım. Hangi gün olsun?`); return; }
    if (this.slots.saat === null && this.slots.vakit === null) { this.state = 'p_saat'; say('Saat kaçta olsun? İsterseniz en uygun saati ben ayarlayabilirim.'); return; }
    return this._paketUygunluk(say);
  }

  async _paketTarih(c, say) {
    const r = await cozApi(this.salonId, c);
    if (r) { if (r.tarih) this.slots.tarih = r.tarih; if (r.vakit) this.slots.vakit = String(r.vakit); }
    if (this.slots.tarih === null && this.slots.vakit === null) { say('Anlayamadım. Bugün, yarın ya da bir gün söyleyin.'); return; }
    return this._paketIlerle(say);
  }

  async _paketSaat(c, say) {
    if (/farketmez|fark etmez|sen ayarla|en yakin|ne uygunsa|uygun olan|onemli degil/.test(fold(c))) {
      // saat bos; asagida vakit/varsayilan ile en yakini buldururuz
    } else {
      const r = await cozApi(this.salonId, c, true);
      if (r) { if (r.saat) this.slots.saat = r.saat; if (r.vakit) this.slots.vakit = String(r.vakit); }
    }
    return this._paketUygunluk(say);
  }

  async _paketUygunluk(say) {
    say('Uygunluk aranıyor, sizi biraz bekleteceğim efendim.');
    // tarihSaat kur: saat yoksa vakitten baslangic saati (backend en yakini doner)
    let saat = this.slots.saat;
    if (!saat) { const v = this.slots.vakit; saat = v === 'sabah' ? '09:00' : v === 'aksam' ? '17:00' : v === 'ogleden_sonra' ? '13:00' : '10:00'; }
    const tarihSaat = `${this.slots.tarih} ${saat}`;
    if (this.dryRun) { this.ctx.lastAvailability = new Map(); this.ctx.lastAvailability.set('slot', { tarihSaat, paketten: true }); }
    else await this.executeTool('uygun_randevu_bul', { tarihSaat, paketten: true, personelId: this.paketPersonelId }, this.ctx);
    const slot = this.ctx.lastAvailability && this.ctx.lastAvailability.get('slot');
    if (!slot || !slot.tarihSaat) { say('Paketiniz için müsait bir saat bulamadım. Başka bir işlem ister misiniz?'); this.state = 'niyet'; this._resetSlots(); return; }
    const parts = String(slot.tarihSaat).split(' ');
    this.slots.tarih = parts[0]; this.slots.saat = (parts[1] || '').slice(0, 5);
    say(`${this.ctx.paket.paketAdi} paketinizden, ${zamanSozlu(this.slots.tarih, this.slots.saat)}. Onaylıyor musunuz?`);
    this.state = 'p_onay';
  }

  async _paketOnay(c, say) {
    if (!olumluMu(c)) { say('Tamam, oluşturmadım. Başka bir işlem ister misiniz?'); this.state = 'niyet'; this._resetSlots(); return; }
    say('Randevu oluşturuluyor, lütfen bekleyin.');
    const r = this.dryRun ? { isError: false } : await this.executeTool('randevu_olustur', { paketten: true }, this.ctx);
    if (r && r.isError) say('Paket randevusu oluşturulurken bir sorun oldu. Başka bir işlem ister misiniz?');
    else say('Paket randevunuzu oluşturdum ve size bilgilendirme mesajı ilettim. Başka bir işlem ister misiniz?');
    this.state = 'niyet'; this._resetSlots();
  }
}

module.exports = { RulesEngine };
