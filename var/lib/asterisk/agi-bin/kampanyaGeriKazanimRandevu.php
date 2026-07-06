#!/usr/bin/php -q
<?php
/**
 * kampanyaGeriKazanimRandevu.php
 *
 * Geri kazanim (win-back) kampanya aramasinin PHASE-3 adimi: musteri indirim
 * kodunu almayi kabul ettikten sonra, canli sesli randevu olusturma diyalogu.
 *
 * Diyalog:
 *   1) "Size uygun bir randevu olusturmami ister misiniz?"  -> evet/hayir
 *   2) evet ise: "Hangi gun ve saat olsun?"                 -> STT + tarih parse
 *   3) API/kontrol -> en yakin uygun slot -> "... uygun. Onayliyor musunuz?"
 *   4) evet ise: API/olustur -> randevu (durum=0) olusur
 *   5) "... icin randevunuzu olusturdum. 15 dakika once gelmenizi rica ederiz."
 *
 * Tum uygunluk/olusturma mantigi Laravel'de:
 *   POST https://app.randevumcepte.com.tr/api/v1/kampanyaSesliRandevu
 *        { mod: kontrol|olustur, katilimci_id, tarihSaat }
 * AGI yalnizca kayit/STT/TTS/onay yapar (ince katman).
 *
 * Cagrilis (dialplan):
 *   AGI(/var/lib/asterisk/agi-bin/kampanyaGeriKazanimRandevu.php,${kampanyaKatilimciId})
 */

require_once '/var/lib/asterisk/agi-bin/phpagi.php';
require_once '/var/lib/asterisk/agi-bin/sesliYanitOrtak.php';

$agi = new AGI();
$GLOBALS['agi'] = $agi; // sesiMetneDonustur / parseDateWithChrono `global $agi` kullanir

$katilimciId = isset($argv[1]) ? trim($argv[1]) : '';
$uid = 'gk_' . ($katilimciId !== '' ? $katilimciId : 'x'); // gecici dosya adlari icin
$API = 'https://app.randevumcepte.com.tr/api/v1/kampanyaSesliRandevu';

$agi->verbose("Geri kazanim randevu diyalogu basladi. katilimci=$katilimciId");

if ($katilimciId === '') {
    $agi->verbose('katilimciId bos — randevu adimi atlaniyor');
    return; // cagrian context Hangup eder
}

/** kampanyaSesliRandevu ucuna JSON POST atar, dizi doner. */
function gkApi($url, $data)
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $resp = curl_exec($ch);
    curl_close($ch);
    $decoded = json_decode($resp, true);
    return is_array($decoded) ? $decoded : ['success' => false];
}

try {
    // 0) On-kontrol: bu katilimci icin sesli randevu uygun mu? Degilse (katilimci
    //    yok / hizmet yok — orn. bu context'i eczane paylasiyorsa) HIC konusmadan
    //    sessizce atla. Boylece paylasilan context'e eklenmesi guvenlidir.
    $bilgi = gkApi($API, ['mod' => 'bilgi', 'katilimci_id' => $katilimciId]);
    if (empty($bilgi['bookable'])) {
        $agi->verbose('Sesli randevu bu katilimci icin uygun degil — sessizce atlaniyor.');
        return; // cagrian context farewell/Hangup ile devam eder
    }

    // 1) Randevu istiyor mu?
    anonsCal($agi, 'Size uygun bir randevu oluşturmamı ister misiniz?', 'gkRandevuSor', $uid, '');
    $istekKaydi = kayitAl($agi, $uid, 'gkRandevuIstek_' . $katilimciId . '_' . date('YmdHis'), 3000);
    $istek = evetHayirVaryasyon(sesiMetneDonustur($istekKaydi));

    if ($istek !== 'evet') {
        anonsCal($agi, 'Anladım efendim. Dilediğiniz zaman bize ulaşabilirsiniz. Sağlıklı ve mutlu günler dileriz.', 'gkVazgec', $uid, '');
        $agi->hangup();
        return;
    }

    // 2-4) Gun/saat -> kontrol -> onay -> olustur (en fazla 3 deneme)
    $denemeMax = 3;
    $olustu = false;

    for ($deneme = 0; $deneme < $denemeMax && !$olustu; $deneme++) {
        anonsCal($agi, 'Hangi gün ve saat olsun?', 'gkTarihSor', $uid, '');
        $tarihKaydi = kayitAl($agi, $uid, 'gkTarih_' . $katilimciId . '_' . date('YmdHis'), 4000);
        $tarihMetni = sesiMetneDonustur($tarihKaydi);

        if (trim($tarihMetni) === '') {
            anonsCal($agi, 'Sizi duyamadım. Lütfen gün ve saati tekrar söyler misiniz?', 'gkBos', $uid, '');
            continue;
        }

        $parsed = parseDateWithChrono($tarihMetni, $agi);
        if (!$parsed) {
            anonsCal($agi, 'Sizi anlayamadım. Lütfen örneğin çarşamba saat on üç gibi söyleyin.', 'gkAnlamadim', $uid, '');
            continue;
        }

        $kontrol = gkApi($API, [
            'mod' => 'kontrol',
            'katilimci_id' => $katilimciId,
            'tarihSaat' => date('Y-m-d H:i', strtotime($parsed)),
        ]);

        if (empty($kontrol['success'])) {
            anonsCal($agi, 'Belirttiğiniz zaman için uygun bir randevu bulamadım. Lütfen başka bir gün ve saat söyleyin.', 'gkUygunYok', $uid, '');
            continue;
        }

        $dogal = isset($kontrol['dogalIfade']) ? $kontrol['dogalIfade'] : '';
        anonsCal($agi, $dogal . ' için randevunuzu oluşturmamı onaylıyor musunuz?', 'gkOnay', $uid, '');
        $onayKaydi = kayitAl($agi, $uid, 'gkOnay_' . $katilimciId . '_' . date('YmdHis'), 2500);
        $onay = evetHayirVaryasyon(sesiMetneDonustur($onayKaydi));

        if ($onay !== 'evet') {
            // Onaylamadi: baska bir gun/saat sor
            continue;
        }

        $sonuc = gkApi($API, [
            'mod' => 'olustur',
            'katilimci_id' => $katilimciId,
            'tarihSaat' => $kontrol['tarihsaat'],
        ]);

        if (!empty($sonuc['success'])) {
            $olustu = true;
            $dogal2 = isset($sonuc['dogalIfade']) ? $sonuc['dogalIfade'] : $dogal;
            anonsCal($agi, $dogal2 . ' için randevunuzu oluşturdum. Randevunuza on beş dakika önce gelmenizi rica eder, sağlıklı günler dileriz.', 'gkTamam', $uid, '');
        } else {
            anonsCal($agi, 'Şu an randevunuzu oluştururken bir sorun oluştu. En kısa sürede sizinle iletişime geçeceğiz. Sağlıklı günler dileriz.', 'gkHata', $uid, '');
            break;
        }
    }

    if (!$olustu) {
        anonsCal($agi, 'Randevunuzu şimdi oluşturamadık. Dilerseniz bizi tekrar arayabilirsiniz. Sağlıklı günler dileriz.', 'gkOlmadi', $uid, '');
    }

    $agi->hangup();
} catch (Exception $e) {
    $agi->verbose('Geri kazanim randevu hatasi: ' . $e->getMessage());
    $agi->hangup();
}
