#!/usr/bin/php -q
<?php
/**
 * SESLI ASISTAN (santral) — MOBIL uygulamadaki akisin telefon karsiligi.
 *
 * Ayni "beyin"i kullanir (mobil ile birebir):
 *   /api/v1/sesli-randevu-coz        -> metni coz (hizmet/personel/tarih/saat)
 *   /api/v1/sesli-randevu-musaitlik  -> en yakin bos slot
 *   /api/v1/randevuekleguncelle      -> randevu olustur (WA+push bildirim dahil)
 *
 * Akis (mobil _basla / _randevuAkisi'nin telefon portu):
 *   1) Karsila (Polly)
 *   2) Dinle (record_file + transcribe2.js) -> metin
 *   3) coz -> alanlari doldur
 *   4) EKSIK alan dongusu: hizmet -> personel -> tarih -> saat  (her biri: sor->dinle->coz)
 *   5) musaitlik -> en yakin slot
 *   6) Onay (evet/hayir)
 *   7) randevuekleguncelle
 *
 * MUSTERI = ARAYAN. Dialplan CallerID'den userId cozup 2. arg olarak verir.
 *   userId bossa -> yeni musteri (ad sorulur, CallerID telefonuyla kaydedilir).
 *
 * Cagri: AGI(sesli-asistan.php, ${salonid}, ${userId})
 *   $argv[1] = salonid   (DID -> salon eslemesi dialplan'da)
 *   $argv[2] = userId    (CallerID -> musteri; bos olabilir)
 *
 * NOT: Personel telefonda SORULUR ve backend cumleden cozer (mobil "her personel"
 *      mantigi). coz'a personel_id GONDERMEYIZ -> personel cumleden gelir.
 */

require 'phpagi.php';
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', '/tmp/sesli-asistan.log');

const API = 'https://app.randevumcepte.com.tr/api/v1';
const MON = '/var/spool/asterisk/monitor';
const POLLY = '/opt/aws-nodejs/polly.js';
const TRANSCRIBE = '/var/lib/asterisk/agi-bin/transcribe2.js';

$agi = new AGI();
$agi->answer();

$salonid  = isset($argv[1]) ? preg_replace('/[^0-9]/', '', $argv[1]) : '';
$userId   = isset($argv[2]) ? preg_replace('/[^0-9]/', '', $argv[2]) : '';
$callerId = preg_replace('/[^0-9]/', '', $agi->request['agi_callerid'] ?? '');

$agi->verbose("SESLI-ASISTAN basladi salon=$salonid user=$userId caller=$callerId", 1);

if ($salonid === '') {
    konus($agi, 'Sistemde bir aksaklik var. Lutfen daha sonra tekrar deneyin.');
    $agi->hangup();
    exit;
}

/* -------------------------------------------------------------------------- */
/* DURUM (mobil state'lerinin karsiligi)                                       */
/* -------------------------------------------------------------------------- */
$hizmetId = null; $hizmetAdi = null; $hizmetFiyat = '0'; $hizmetSure = '30';
$personelId = null; $personelAdi = null;
$tarih = null; $saat = null; $vakit = null;

/* 1) KARSILAMA -------------------------------------------------------------- */
konus($agi, 'Merhaba, randevu asistanina hos geldiniz. Hangi hizmet icin, hangi '
    . 'personelden ve ne zaman randevu istediginizi tek seferde soyleyebilirsiniz.');

/* 2) ILK KOMUT -------------------------------------------------------------- */
$metin = dinle($agi, 6000);
if (trim($metin) === '') {
    konus($agi, 'Sizi duyamadim. Randevu bilgilerini soyler misiniz?');
    $metin = dinle($agi, 6000);
}
if (trim($metin) === '') { konus($agi, 'Sizi duyamadim, iyi gunler.'); $agi->hangup(); exit; }

/* 3) COZ -> alanlari doldur ------------------------------------------------- */
uygula($agi, $salonid, $metin, $hizmetId, $hizmetAdi, $hizmetFiyat, $hizmetSure,
       $personelId, $personelAdi, $tarih, $saat, $vakit);

/* 4) EKSIK ALAN DONGUSU (mobil _hizmetCoz / personel / _tarihCozSes) --------- */

// 4a) HIZMET
$deneme = 0;
while ($hizmetId === null && $deneme < 3) {
    $deneme++;
    konus($agi, $deneme === 1
        ? 'Hangi hizmet icin randevu olusturalim?'
        : 'Bu hizmeti bulamadim. Lutfen baska bir hizmet soyleyin.');
    $c = dinle($agi, 5000);
    if (trim($c) === '') continue;
    uygula($agi, $salonid, $c, $hizmetId, $hizmetAdi, $hizmetFiyat, $hizmetSure,
           $personelId, $personelAdi, $tarih, $saat, $vakit);
}
if ($hizmetId === null) { konus($agi, 'Hizmeti anlayamadim, islemi iptal ediyorum.'); $agi->hangup(); exit; }

// 4b) PERSONEL (telefonda sorulur; backend cumleden cozer)
$deneme = 0;
while ($personelId === null && $deneme < 3) {
    $deneme++;
    konus($agi, $deneme === 1
        ? ($hizmetAdi ? "$hizmetAdi icin hangi personelden randevu istersiniz?" : 'Hangi personelden randevu istersiniz?')
        : 'Personeli anlayamadim. Lutfen personelin adini soyleyin.');
    $c = dinle($agi, 5000);
    if (trim($c) === '') continue;
    uygula($agi, $salonid, $c, $hizmetId, $hizmetAdi, $hizmetFiyat, $hizmetSure,
           $personelId, $personelAdi, $tarih, $saat, $vakit);
}
if ($personelId === null) { konus($agi, 'Personeli belirleyemedim, islemi iptal ediyorum.'); $agi->hangup(); exit; }

// 4c) TARIH (net alinamazsa zorlamaz -> musaitlik en yakini bulur)
$deneme = 0;
while ($tarih === null && $vakit === null && $deneme < 2) {
    $deneme++;
    konus($agi, $deneme === 1
        ? 'Randevu hangi gun olsun?'
        : 'Anlayamadim. Bugun, yarin ya da bir tarih soyleyebilirsiniz.');
    $c = dinle($agi, 5000);
    if (trim($c) === '') continue;
    uygula($agi, $salonid, $c, $hizmetId, $hizmetAdi, $hizmetFiyat, $hizmetSure,
           $personelId, $personelAdi, $tarih, $saat, $vakit);
}

// 4d) SAAT (opsiyonel — bir kez sor; net degilse musaitlik en yakini bulur)
if ($saat === null && $vakit === null) {
    konus($agi, 'Saat kacta olsun? Isterseniz en uygun saati ben ayarlayabilirim.');
    $c = dinle($agi, 5000);
    if (trim($c) !== '' && !preg_match('/sen ayarla|en yakin|farketmez|fark etmez|uygun olan/i', tr($c))) {
        uygula($agi, $salonid, $c, $hizmetId, $hizmetAdi, $hizmetFiyat, $hizmetSure,
               $personelId, $personelAdi, $tarih, $saat, $vakit);
    }
}

/* 5) MUSTERI = ARAYAN (userId yoksa yeni musteri) --------------------------- */
if ($userId === '' || $userId === null) {
    $userId = yeniMusteriAkisi($agi, $salonid, $callerId);
    if ($userId === '') { konus($agi, 'Kaydinizi olusturamadim, islemi iptal ediyorum.'); $agi->hangup(); exit; }
}

/* 6) MUSAITLIK + ONAY (mobil _musaitlikVeOnay) ------------------------------ */
konus($agi, 'Uygun saat araniyor, lutfen bekleyin.');
$m = musaitlik($agi, $salonid, $personelId, $hizmetId, $tarih ?? '', $saat ?? '', $vakit ?? '');
if (($m['bulundu'] ?? false) !== true) {
    konus($agi, 'Belirttiginiz tarihlerde musait bir saat bulamadim. Iyi gunler.');
    $agi->hangup(); exit;
}
$tarih = $m['tarih']; $saat = $m['saat'];

$zaman = tarihSozlu($tarih) . ' saat ' . str_replace(':', ' ', substr($saat, 0, 5));
konus($agi, "$hizmetAdi, $personelAdi personeli, $zaman. Onayliyor musunuz?");
if (!evetMi($agi)) { konus($agi, 'Randevu olusturulmadi, iyi gunler.'); $agi->hangup(); exit; }

/* 7) OLUSTUR (mobil ile ayni uc -> WA+push bildirim dahil) ------------------ */
$r = randevuOlustur($agi, $salonid, $userId, $tarih, $saat, $hizmetId, $personelId, $hizmetFiyat, $hizmetSure);
if (($r['cakismavar'] ?? '') === '1') {
    konus($agi, 'Maalesef o saat az once doldu. Lutfen tekrar arayin. Iyi gunler.');
} elseif (isset($r['hata'])) {
    konus($agi, 'Randevu olusturulurken bir sorun oldu. Lutfen tekrar deneyin.');
} else {
    konus($agi, "Randevunuz olusturuldu. $zaman, $hizmetAdi. Iyi gunler dileriz.");
}
$agi->hangup();
exit;

/* ========================================================================== */
/* YARDIMCILAR                                                                 */
/* ========================================================================== */

/** Cache'li Google erkek (tr-TR-Wavenet-E) ile seslendir + cal; Google olmazsa Polly fallback.
 *  Kampanya/randevu hatirlatma seslendirmesiyle AYNI ses ve ayni cache mantigi
 *  (gttscache.php / sesliYanitOrtak.php googleAnonsCal ile birebir). Sabit cumleler
 *  bir kez uretilir, sonra diskten (bedava + aninda). */
function konus($agi, $text)
{
    $ses  = 'tr-TR-Wavenet-E';        // Google Turkce erkek WaveNet
    $text = seslendirmeMetni($text);  // BUYUK harf/marka duzeltmesi (ORBEY -> Orbey)
    $id   = md5($ses . '|' . $text);
    $base = MON . "/gtts-$id";
    $wav  = "$base.wav";
    $mp3  = "$base.mp3";

    // Cache HIT -> dogrudan cal
    if (is_file($wav) && filesize($wav) > 0) { $agi->stream_file($base); return; }

    if ($text !== '') {
        $ch = curl_init(API . '/seslendir');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['metin' => $text, 'ses' => $ses]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $resp = curl_exec($ch);
        curl_close($ch);
        $j = json_decode($resp, true);
        if (is_array($j) && !empty($j['basarili']) && !empty($j['url'])) {
            $dl = curl_init($j['url']);
            curl_setopt($dl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($dl, CURLOPT_TIMEOUT, 15);
            $data = curl_exec($dl);
            curl_close($dl);
            if ($data !== false && $data !== '') {
                @file_put_contents($mp3, $data);
                shell_exec('ffmpeg -y -i ' . escapeshellarg($mp3) . ' -ar 8000 -ac 1 -acodec pcm_s16le ' . escapeshellarg($wav) . ' 2>/dev/null');
                @unlink($mp3);
            }
        }
    }

    // Google basarisizsa Polly fallback (sessizlik olmasin)
    if (!is_file($wav) || filesize($wav) === 0) {
        shell_exec('node ' . POLLY . ' --mp3=' . escapeshellarg($mp3) . ' --text='
            . escapeshellarg($text) . ' --wav=' . escapeshellarg($base));
    }
    $agi->stream_file($base);
}

/** Kaydet + transcribe2.js -> metin (mevcut kalip). Bos donebilir. */
function dinle($agi, $maxms = 5000)
{
    $id = uniqid();
    $f = MON . "/asistan_input_$id";
    // (dosya, format, escapeDigits, maxms, silenceSeconds)
    $agi->record_file($f, 'wav', '', $maxms, 0, false, 2);
    $out = shell_exec('node ' . TRANSCRIBE . ' ' . escapeshellarg("$f.wav"));
    $r = json_decode($out, true);
    return ($r && ($r['success'] ?? false)) ? trim($r['transcription'] ?? '') : '';
}

/** Evet/hayir dinle -> true/false (mevcut evetHayir kalibi). */
function evetMi($agi)
{
    $c = tr(dinle($agi, 3000));
    return (strpos($c, 'evet') !== false || strpos($c, 'onay') !== false
        || strpos($c, 'tamam') !== false || strpos($c, 'olur') !== false);
}

/**
 * Metni /sesli-randevu-coz'e cozdurup alanlari doldurur (mobil _uygula).
 * DOLU alanlari EZMEZ (bos gelen alanlar korunur) — mobil ile ayni davranis.
 * NOT: coz'a personel_id GONDERMEYIZ -> personel cumleden cozulur.
 */
function uygula($agi, $salonid, $metin, &$hizmetId, &$hizmetAdi, &$hizmetFiyat,
                &$hizmetSure, &$personelId, &$personelAdi, &$tarih, &$saat, &$vakit)
{
    $r = apiGet($agi, '/sesli-randevu-coz', ['salonid' => $salonid, 'metin' => $metin]);
    if (!$r || ($r['basarili'] ?? false) !== true) return;

    if (!empty($r['tarih'])) $tarih = $r['tarih'];
    if (!empty($r['saat']))  $saat  = $r['saat'];
    if (!empty($r['vakit'])) $vakit = $r['vakit'];

    $hizmetler = $r['hizmetler'] ?? [];
    if (!empty($hizmetler)) {
        $h = $hizmetler[0];
        $hizmetId    = (string)($h['hizmet_id'] ?? '');
        $hizmetAdi   = $h['hizmet_adi'] ?? $hizmetAdi;
        $hizmetFiyat = (string)($h['fiyat'] ?? $hizmetFiyat);
        $hizmetSure  = (string)($h['sure_dk'] ?? $hizmetSure);
    }
    $p = $r['personel'] ?? null;
    if ($p && !empty($p['personel_id'])) {
        $personelId  = (string)$p['personel_id'];
        $personelAdi = $p['personel_adi'] ?? $personelAdi;
    }
    $agi->verbose("COZ -> hizmet=$hizmetId personel=$personelId tarih=$tarih saat=$saat vakit=$vakit", 1);
}

/** /sesli-randevu-musaitlik -> {bulundu,tarih,saat,...} */
function musaitlik($agi, $salonid, $personelId, $hizmetId, $tarih, $saat, $vakit)
{
    return apiGet($agi, '/sesli-randevu-musaitlik', [
        'salonid' => $salonid, 'personel_id' => $personelId, 'hizmet_id' => $hizmetId,
        'tarih' => $tarih, 'saat' => $saat, 'vakit' => $vakit,
    ]) ?: ['bulundu' => false];
}

/**
 * Randevu olustur — MOBIL ile AYNI uc (/randevuekleguncelle) -> WhatsApp+push
 * bildirim algoritmasi otomatik calisir. randevuKaynak=salon, olusturan=null
 * (telefon musterisi), user_id = arayan musteri.
 */
function randevuOlustur($agi, $salonid, $userId, $tarih, $saat, $hizmetId, $personelId, $fiyat, $sure)
{
    $payload = [
        'randevu_id' => '', 'user_id' => $userId,
        'randevu_tarihi' => $tarih, 'randevu_saati' => $saat,
        'hizmetler' => [[
            'hizmet_id' => $hizmetId, 'personel_id' => $personelId,
            'oda_id' => '', 'cihaz_id' => '', 'yardimci_personel' => '',
            'sure_dk' => $sure, 'fiyat' => $fiyat, 'birlestir' => '',
        ]],
        'yardimcipersoneller' => [], 'tekrarlayan' => false,
        'tekrar_sayisi' => '', 'tekrar_sikligi' => null,
        'notlar' => 'Sesli asistan (telefon)', 'salonid' => $salonid,
        'cakisma_varmi' => '', 'cakisanrandevuekle' => '',
        'olusturan' => null, 'olusturanMusteri' => null,
        'randevuKaynak' => 'salon', 'durum' => '1',
    ];
    return apiPostJson($agi, '/randevuekleguncelle', $payload) ?: ['hata' => 'baglanti'];
}

/** userId yoksa: ad sor -> CallerID telefonuyla yeni musteri olustur. userId doner ya da ''. */
function yeniMusteriAkisi($agi, $salonid, $callerId)
{
    if ($callerId === '') {
        konus($agi, 'Numaraniz gizli goründügü icin sizi kaydedemiyorum.');
        return '';
    }
    konus($agi, 'Sizi kayitlarimizda bulamadim. Lutfen adinizi ve soyadinizi soyleyin.');
    $ad = '';
    for ($i = 0; $i < 2 && $ad === ''; $i++) {
        $c = trim(dinle($agi, 4000));
        if ($c !== '') $ad = $c;
        elseif ($i === 0) konus($agi, 'Anlayamadim, adinizi tekrar soyler misiniz?');
    }
    if ($ad === '') return '';

    $tel = $callerId;
    if (strlen($tel) === 10 && $tel[0] === '5') $tel = '0' . $tel;

    $r = apiPost($agi, '/yenimusteridanisankaydi', [
        'salonidler' => $salonid, 'name' => $ad, 'cep_telefon' => $tel,
        'isletmeadi' => '', 'santraldenkayit' => '1',
    ]);
    if (is_array($r) && !empty($r['userId'])) return (string)$r['userId'];
    return '';
}

/* -------- HTTP -------- */
function apiGet($agi, $path, $params)
{
    $url = API . $path . '?' . http_build_query($params);
    return httpJson($agi, $url, null);
}
function apiPostJson($agi, $path, $payload)
{
    return httpJson($agi, API . $path, json_encode($payload), true);
}
function apiPost($agi, $path, $form)
{
    $ch = curl_init(API . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    $resp = curl_exec($ch);
    curl_close($ch);
    $agi->verbose("POST $path -> " . substr((string)$resp, 0, 200), 1);
    $j = json_decode($resp, true);
    return $j !== null ? $j : $resp;
}
function httpJson($agi, $url, $body, $json = false)
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    $h = ['Accept: application/json'];
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        if ($json) $h[] = 'Content-Type: application/json';
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
    $resp = curl_exec($ch);
    if (curl_errno($ch)) $agi->verbose('cURL: ' . curl_error($ch), 1);
    curl_close($ch);
    $agi->verbose('HTTP ' . substr(strrchr($url, '/'), 0, 30) . ' -> ' . substr((string)$resp, 0, 200), 1);
    return json_decode($resp, true);
}

/* -------- Metin -------- */
/** Turkce kucuk harf (I->ı, İ->i). */
function tr($s) { return mb_strtolower(str_replace(['I', 'İ'], ['ı', 'i'], $s), 'UTF-8'); }

/** BUYUK harf kelimeleri bas-harfi-buyuk forma cevir (Polly harf harf okumasin). */
function seslendirmeMetni($s)
{
    return preg_replace_callback('/[A-ZÇĞİÖŞÜ]{2,}/u', function ($m) {
        $w = $m[0];
        return mb_substr($w, 0, 1, 'UTF-8') . tr(mb_substr($w, 1, null, 'UTF-8'));
    }, $s);
}

/** "2026-08-27" -> "27 Agustos" */
function tarihSozlu($ymd)
{
    $aylar = ['', 'Ocak', 'Subat', 'Mart', 'Nisan', 'Mayis', 'Haziran',
        'Temmuz', 'Agustos', 'Eylul', 'Ekim', 'Kasim', 'Aralik'];
    $p = explode('-', $ymd);
    if (count($p) !== 3) return $ymd;
    $ay = (int)$p[1];
    return ($ay >= 1 && $ay <= 12) ? ((int)$p[2] . ' ' . $aylar[$ay]) : $ymd;
}
?>
