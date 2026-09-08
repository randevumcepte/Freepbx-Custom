'use strict';
/**
 * NLU normalizerlar — sesli-randevu-akis.php (turn-based PHP AGI) icindeki KANITLANMIS
 * saf-string fonksiyonlarinin BIREBIR JS portu. Kural-tabanli beyin (engines/rules.js)
 * bunlari kullanip metni /api/v1/sesli-randevu-coz'un anladigi forma getirir.
 *
 * Fonksiyonlar:
 *   fold(s)                       -> Turkce->ASCII katlanmis kucuk harf (yalniz ESLESME)
 *   gunEkNormalize(metin)         -> "bugünkü"->"bugün", "yarınki"->"yarın", "cumaki"->"cuma"
 *   saatSozcukleriniRakama(m,sc)  -> "öğleden sonra iki"->"öğleden sonra 2", "pazartesi 2"->
 *                                    "pazartesi saat 2" (coz'un anlamasi icin "saat" enjekte)
 *   niyetBul(metin)               -> al | sorgula | guncelle | iptal | musaitlik
 */

/** Turkce kucuk harf, diakritikleri ASCII'ye katlar (SADECE eslesme icin). */
function fold(s) {
  s = String(s == null ? '' : s).replace(/[ÇĞİIÖŞÜçğıöşüâîûÂÎÛ]/g, (ch) => ({
    'Ç': 'c', 'Ğ': 'g', 'İ': 'i', 'I': 'i', 'Ö': 'o', 'Ş': 's', 'Ü': 'u',
    'ç': 'c', 'ğ': 'g', 'ı': 'i', 'ö': 'o', 'ş': 's', 'ü': 'u',
    'â': 'a', 'î': 'i', 'û': 'u', 'Â': 'a', 'Î': 'i', 'Û': 'u',
  }[ch] || ch));
  return s.toLowerCase();
}

/**
 * Gun kelimelerindeki "-ki/-kü" ekini temizler. Token-bazli (JS \b Turkce karakterde
 * guvenilmez); noktalama kirpilir, fold ile eslesir, orijinal bosluklar korunur.
 */
function gunEkNormalize(metin) {
  const gunler = new Set(['pazartesi', 'sali', 'carsamba', 'persembe', 'cuma', 'cumartesi', 'pazar']);
  return String(metin == null ? '' : metin).split(/(\s+)/).map((tok) => {
    if (/^\s+$/.test(tok) || tok === '') return tok;
    const f = fold(tok.replace(/[.,!?;:]+$/u, ''));
    if (f === 'bugunku' || f === 'bugunki') return 'bugün';
    if (f === 'yarinki' || f === 'yarinku') return 'yarın';
    if (f === 'dunku' || f === 'dunki') return 'dün';
    if (f === 'oburku' || f === 'oburgunku' || f === 'oburgunki') return 'öbür gün';
    // haftanin gunleri + ki/ku: "pazartesiki" -> "pazartesi"
    const m = f.match(/^(.*?)(ki|ku)$/);
    if (m && gunler.has(m[1])) return m[1];
    return tok;
  }).join('');
}

/**
 * Saat sozcuklerini rakama cevirir + gerekli yerde "saat" baglami EKLER. Backend coz saati
 * yalniz RAKAM + "saat"/vakit baglaminda cozer; telefon konusmasi cok cesitli gelir.
 * saatCevabi=true -> "Saat kacta?" cevabinda ciplak sayi da saattir.
 */
function saatSozcukleriniRakama(metin, saatCevabi = false) {
  metin = gunEkNormalize(metin); // "bugünkü"->"bugün" vb.

  const birler = { bir: 1, iki: 2, uc: 3, dort: 4, bes: 5, alti: 6, yedi: 7, sekiz: 8, dokuz: 9 };
  const onlar = { on: 10, yirmi: 20 };
  const vakit = new Set(['saat', 'sabah', 'sabaha', 'ogle', 'oglen', 'oglende', 'ogleyin', 'ogleden', 'aksam', 'aksama', 'gece', 'geceye', 'gunduz']);
  const gunler = new Set(['pazartesi', 'sali', 'carsamba', 'persembe', 'cuma', 'cumartesi', 'pazar', 'bugun', 'yarin', 'obur', 'oburgun', 'oburgune', 'haftaya', 'pazartesiye', 'saliya', 'carsambaya', 'persembeye', 'cumaya', 'cumartesiye', 'pazara', 'yarina']);
  const birim = new Set(['kisi', 'kisilik', 'seans', 'hizmet', 'islem', 'dakika', 'dk', 'adet', 'tane', 'kere', 'kez', 'defa', 'saniye', 'numara', 'tl', 'lira', 'gun', 'hafta', 'ay', 'yil']);
  const aylar = new Set(['ocak', 'subat', 'mart', 'nisan', 'mayis', 'haziran', 'temmuz', 'agustos', 'eylul', 'ekim', 'kasim', 'aralik']);
  const suffix = /(de|da|te|ta|ye|ya)$/;

  const tokens = String(metin).trim().split(/\s+/);
  if (!tokens.length || tokens[0] === '') return String(metin);

  // PASS 1: sayi SOZCUKLERI -> rakam. meta[i].time = token saat kasti mi.
  const out = [];
  const meta = [];
  let vakitAktif = 0;
  let gunAktif = !!saatCevabi;
  const n = tokens.length;
  for (let i = 0; i < n; i++) {
    const w = tokens[i];
    const wf = fold(w);
    if (wf === 'bucuk' || wf === 'bucukta') { out.push('@B@'); meta.push({ time: true }); if (vakitAktif > 0) vakitAktif--; continue; }

    if (gunler.has(wf)) gunAktif = true;

    const suf = suffix.test(wf);
    const base = suf ? wf.replace(suffix, '') : wf;
    const ctx = vakitAktif > 0;

    const nf = (i + 1 < n) ? fold(tokens[i + 1]) : '';
    const nsuf = suffix.test(nf);
    const nbase = nsuf ? nf.replace(suffix, '') : nf;

    const isTens = Object.prototype.hasOwnProperty.call(onlar, base);
    const isUnit = Object.prototype.hasOwnProperty.call(birler, base);
    const zaman = ctx || suf || (isTens && (nsuf || Object.prototype.hasOwnProperty.call(birler, nbase))) || gunAktif;

    if (isTens && zaman) {
      let val = onlar[base];
      if (Object.prototype.hasOwnProperty.call(birler, nbase)) { val += birler[nbase]; i++; } // "on bir" -> 11
      out.push(String(val)); meta.push({ time: true });
      vakitAktif = 0;
    } else if (isUnit && zaman) {
      out.push(String(birler[base])); meta.push({ time: true });
      vakitAktif = 0;
    } else {
      out.push(w); meta.push({ time: false });
      if (vakit.has(wf)) vakitAktif = 3;
      else if (vakitAktif > 0) vakitAktif--;
    }
  }

  // PASS 2: "@B@" -> onceki rakama :30; ciplak saat rakaminin onune "saat" ekle.
  const res = [];
  let vakitSeen = false;
  const m2 = out.length;
  for (let i = 0; i < m2; i++) {
    const tok = out[i];
    if (tok === '@B@') {
      const k = res.length - 1;
      if (k >= 0 && /^\d{1,2}$/.test(res[k])) res[k] = res[k] + ':30';
      continue;
    }
    const tf = fold(tok);
    const next = (i + 1 < m2) ? fold(out[i + 1]) : '';
    const isHour = /^([01]?\d|2[0-3])$/.test(tok);
    const timeMeta = meta[i] && meta[i].time;
    const engelli = birim.has(next) || aylar.has(next);

    if (isHour && !vakitSeen && !engelli && (gunAktif || timeMeta)) {
      res.push('saat');
      vakitSeen = true;
    }
    res.push(tok);
    if (vakit.has(tf)) vakitSeen = true;
  }

  return res.join(' ').trim();
}

/* ------------------------------------------------------------------ *
 * NIYET — EGITILEBILIR SOZLUK + FUZZY (uygulamadaki SesliRandevuCozService
 * yontemi: fold + es-anlamli + benzerlik). Kurallar KODDA degil src/intents.json'da;
 * yeni varyasyon = dosyaya 1 satir (deploy yok, dosya her cagride okunur).
 * ------------------------------------------------------------------ */
const path = require('path');
const INTENTS_PATH = path.join(__dirname, 'intents.json');

/** intents.json'u her cagride taze oku (canliyken duzenlenince aninda etki eder). */
function _intentsYukle() {
  try {
    delete require.cache[require.resolve(INTENTS_PATH)];
    return require(INTENTS_PATH);
  } catch (e) {
    return { esAnlamli: {}, niyetler: [] };
  }
}

/** Levenshtein tabanli 0..1 benzerlik (PHP similar_text muadili, typo/STT toleransi). */
function _benzerlik(a, b) {
  a = String(a); b = String(b);
  if (a === b) return 1;
  const m = a.length, n = b.length;
  if (!m || !n) return 0;
  const d = Array.from({ length: m + 1 }, (_, i) => [i, ...Array(n).fill(0)]);
  for (let j = 0; j <= n; j++) d[0][j] = j;
  for (let i = 1; i <= m; i++) {
    for (let j = 1; j <= n; j++) {
      const c = a[i - 1] === b[j - 1] ? 0 : 1;
      d[i][j] = Math.min(d[i - 1][j] + 1, d[i][j - 1] + 1, d[i - 1][j - 1] + c);
    }
  }
  return 1 - d[m][n] / Math.max(m, n);
}

/** Eş-anlamlı sözlükten kanonik forma indir (yoksa aynen döner). */
function _kanonik(w, esAnlamli) { return (esAnlamli && esAnlamli[w]) || w; }

/**
 * Tek trigger sözcüğü (fold+kanonik) cümledeki bir sözcükle eşleşiyor mu?
 *  1) kanonik birebir aynı
 *  2) YÖNLÜ ÖNEK: cümle sözcüğü trigger ile başlıyor (Türkçe ek: adres->adresiniz),
 *     trigger >=4 harf — "randevu" trigger'ı "randevu almak"a takılsın diye DEĞİL,
 *     tersine trigger "randevum" iken "randevu"ya UYMAZ (iyelik ayrımı korunur).
 *  3) FUZZY (>=0.84, ikisi de >=5 harf): typo/STT sapmasi.
 */
function _sozcukUyar(trigger, kelime, esAnlamli) {
  const t = _kanonik(trigger, esAnlamli);
  const k = _kanonik(kelime, esAnlamli);
  if (t === k) return true;
  // Yönlü önek: cümle sözcüğü trigger ile başlıyor (adres->adresiniz, yer->yeriniz).
  if (t.length >= 3 && k.startsWith(t)) return true;
  // Fuzzy typo/STT — ama girdi trigger'ın KÖKÜ/ÖNEKİ ise UYGULAMA (generic "randevu",
  // iyelik "randevum" trigger'ına eşleşmesin diye): t "randevum" iken k "randevu" -> hayır.
  if (t.length >= 5 && k.length >= 5 && !t.startsWith(k) && _benzerlik(t, k) >= 0.84) return true;
  return false;
}

/** "ifade" (tek/çok sözcük) cümledeki sözcüklere karşı eşleşiyor mu (TÜM sözcükleri geçmeli)? */
function _ifadeUyar(ifade, kelimeler, esAnlamli) {
  const parcalar = fold(ifade).split(/\s+/).filter(Boolean);
  if (!parcalar.length) return false;
  return parcalar.every((p) => kelimeler.some((k) => _sozcukUyar(p, k, esAnlamli)));
}

/** Cümlede verilen köklerden (gerekli/haric) biri geçiyor mu? */
function _kokVar(kokler, kelimeler, esAnlamli) {
  return (kokler || []).some((kok) => kelimeler.some((k) => _sozcukUyar(kok, k, esAnlamli)));
}

/** Niyet: intents.json'daki SIRAYLA ilk eşleşen; hiçbiri değilse 'al' (randevu al — varsayılan). */
function niyetBul(metin) {
  const { esAnlamli, niyetler } = _intentsYukle();
  const kelimeler = fold(metin).split(/\s+/).filter(Boolean);
  if (!kelimeler.length) return 'al';
  for (const n of (niyetler || [])) {
    if (n.haric && _kokVar(n.haric, kelimeler, esAnlamli)) continue;
    if (n.gerekli && !_kokVar(n.gerekli, kelimeler, esAnlamli)) continue;
    if ((n.kelimeler || []).some((ifade) => _ifadeUyar(ifade, kelimeler, esAnlamli))) return n.ad;
  }
  return 'al';
}

module.exports = { fold, gunEkNormalize, saatSozcukleriniRakama, niyetBul };
