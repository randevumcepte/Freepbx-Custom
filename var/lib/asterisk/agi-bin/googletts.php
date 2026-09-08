#!/usr/bin/php -q
<?php
/**
 * GOOGLE TTS (dialplan) — pollysimple.php'nin Google WaveNet karsiligi.
 * Ayni arayuz: arg[1] = base64 metin; UNIQUE_ID kanal degiskeni set edilir;
 * /var/spool/asterisk/monitor/gtts-<UNIQUE_ID>.wav (8kHz mono) uretilir.
 * Dialplan: Playback/Background(/var/spool/asterisk/monitor/gtts-${UNIQUE_ID}).
 *
 * Beyin/akis modulu (randevu_ai) icin: Amazon Polly yerine Google TTS. Sunucu
 * /api/v1/seslendir'i onbellekliyor -> ayni cumleye Google'a tekrar para yazilmaz.
 */
set_time_limit(30);
require('phpagi.php');
$agi = new AGI();
$agi->answer();

$id = uniqid();
$agi->set_variable('UNIQUE_ID', $id);
$text = base64_decode($argv[1] ?? '');
$agi->verbose('googletts: ' . $text, 1);

$base = '/var/spool/asterisk/monitor/gtts-' . $id;
$wav  = $base . '.wav';
$mp3  = $base . '.mp3';

/* 1) /api/v1/seslendir -> mp3 URL (Google WaveNet erkek, Turkce normalize sunucuda) */
$ch = curl_init('https://app.randevumcepte.com.tr/api/v1/seslendir');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['metin' => $text]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
$resp = curl_exec($ch);
curl_close($ch);
$j = json_decode($resp, true);

if (is_array($j) && !empty($j['basarili']) && !empty($j['url'])) {
    /* 2) mp3'u indir (curl) */
    $dl = curl_init($j['url']);
    curl_setopt($dl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($dl, CURLOPT_TIMEOUT, 15);
    $data = curl_exec($dl);
    curl_close($dl);
    if ($data !== false && $data !== '') {
        @file_put_contents($mp3, $data);
        /* 3) mp3 -> Asterisk'in caldigi wav (8kHz mono 16-bit PCM) */
        shell_exec('ffmpeg -y -i ' . escapeshellarg($mp3) . ' -ar 8000 -ac 1 -acodec pcm_s16le ' . escapeshellarg($wav) . ' 2>/dev/null');
        @unlink($mp3);
    }
}

/* 4) Google basarisizsa Polly'ye dus (sessizlik olmasin) */
if (!is_file($wav) || filesize($wav) === 0) {
    shell_exec('node /opt/aws-nodejs/polly.js --mp3=' . escapeshellarg($mp3) . ' --text=' . escapeshellarg($text) . ' --wav=' . escapeshellarg($base));
}
exit();
?>
