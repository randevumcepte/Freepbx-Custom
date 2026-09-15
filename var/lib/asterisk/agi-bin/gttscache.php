#!/usr/bin/php -q
<?php
/**
 * GOOGLE TTS + LOKAL CACHE (dialplan) — SADECE randevu hatirlatma aramasi akisi.
 *
 * pollysimple.php'nin cache'li Google WaveNet ERKEK karsiligi. pollysimple.php'ye
 * DOKUNULMAZ; diger akislar (alacak-hatirlatma, ilac-eczane, kampanya vb.) Polly'de
 * kalir. Sadece [randevu-hatirlatma] context'inde pollysimple.php yerine bu script
 * cagrilir.
 *
 * Sozlesme pollysimple.php ile AYNI kalir:
 *   - arg[1] = base64 metin
 *   - UNIQUE_ID kanal degiskeni set edilir
 *   - /var/spool/asterisk/monitor/polly-<UNIQUE_ID>.wav (8kHz mono 16-bit) uretilir
 *   => Dialplan'daki "Background(/var/spool/asterisk/monitor/polly-${UNIQUE_ID})"
 *      satirlari HIC DEGISMEDEN calisir; dialplan'da sadece AGI adi degisir.
 *
 * CACHE: dosya adi = md5(ses|metin). Ayni cumle daha once uretildiyse tekrar
 * Google'a gidilmez (ne ag turu ne ffmpeg) — aninda cache'ten calinir. Polly'nin
 * her cagrida uniqid ile yeniden urettigi davranistan farkli olarak burada bounded
 * bir cache olusur.
 *
 * SES: tr-TR-Wavenet-E (Google Turkce ERKEK WaveNet) acikca gonderilir; sunucu
 * default'u degisse bile erkek ses garanti. Sunucu (/api/v1/seslendir) ayrica
 * kendi tarafinda da onbellekler.
 *
 * Google basarisizsa Polly'ye duser (cagride sessizlik olmasin).
 */
set_time_limit(30);
require('phpagi.php');
$agi = new AGI();
$agi->answer();

$SES  = 'tr-TR-Wavenet-E'; // Google Turkce erkek WaveNet

// arg[1] base64 OLABILIR (${ENCODED_DATA}) ya da HAM metin olabilir
// (${calinacakKayit}, "${RANDEVU_BILDIRIM_BASARILI}" gibi). pollysimple.php her
// ikisini de kabul ettigi icin drop-in kalabilmek adina otomatik algila:
//  - strict base64 cozulup canonical (yeniden encode == girdi) ve gecerli UTF-8 ise
//    -> base64 kabul et; degilse ham metin olarak kullan.
$rawArg  = (string) ($argv[1] ?? '');
$decoded = base64_decode($rawArg, true);
if ($decoded !== false && $decoded !== '' && base64_encode($decoded) === $rawArg && preg_match('//u', $decoded)) {
    $text = $decoded;
} else {
    $text = $rawArg;
}

// Cache anahtari: ayni ses + ayni metin => ayni dosya
$id   = md5($SES . '|' . $text);
$agi->set_variable('UNIQUE_ID', $id);

$base = '/var/spool/asterisk/monitor/polly-' . $id; // polly- oneki: dialplan Background degismesin
$wav  = $base . '.wav';
$mp3  = $base . '.mp3';

$agi->verbose('gttscache: ' . $text, 1);

/* 0) CACHE HIT — daha once uretilmis, dokunma */
if (is_file($wav) && filesize($wav) > 0) {
    $agi->verbose('gttscache: cache hit ' . $id, 1);
    exit();
}

/* 1) /api/v1/seslendir -> mp3 URL (Google WaveNet erkek, Turkce normalize sunucuda) */
if ($text !== '') {
    $ch = curl_init('https://app.randevumcepte.com.tr/api/v1/seslendir');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['metin' => $text, 'ses' => $SES]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $resp = curl_exec($ch);
    curl_close($ch);
    $j = json_decode($resp, true);

    if (is_array($j) && !empty($j['basarili']) && !empty($j['url'])) {
        /* 2) mp3'u indir */
        $dl = curl_init($j['url']);
        curl_setopt($dl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($dl, CURLOPT_TIMEOUT, 15);
        $data = curl_exec($dl);
        curl_close($dl);
        if ($data !== false && $data !== '') {
            @file_put_contents($mp3, $data);
            /* 3) mp3 -> Asterisk wav (8kHz mono 16-bit PCM) */
            shell_exec('ffmpeg -y -i ' . escapeshellarg($mp3) . ' -ar 8000 -ac 1 -acodec pcm_s16le ' . escapeshellarg($wav) . ' 2>/dev/null');
            @unlink($mp3);
        }
    }
}

/* 4) Google basarisizsa Polly'ye dus (sessizlik olmasin) */
if (!is_file($wav) || filesize($wav) === 0) {
    $agi->verbose('gttscache: google basarisiz, polly fallback', 1);
    shell_exec('node /opt/aws-nodejs/polly.js --mp3=' . escapeshellarg($mp3) . ' --text=' . escapeshellarg($text) . ' --wav=' . escapeshellarg($base));
}
exit();
?>
