#!/usr/bin/php -q
<?php
error_reporting(E_ALL);
set_time_limit(0);
ob_implicit_flush();

// Log dosyası
$logFile = "/tmp/live_audio.log";
file_put_contents($logFile, "AGI Başladı\n", FILE_APPEND);

// Ham ses verisini kaydet
$audioFile = "/var/spool/asterisk/monitor/agideneme.raw";
$fp = fopen($audioFile, "wb");

if (!$fp) {
    file_put_contents($logFile, "Ses dosyası açılamadı!\n", FILE_APPEND);
    exit(1);
}

while (!feof(STDIN)) {
    $audioData = fread(STDIN, 320); // 20ms'lik PCM ses verisi
    if (!$audioData) {
        file_put_contents($logFile, "Boş veri alındı.\n", FILE_APPEND);
        break;
    }
    
    fwrite($fp, $audioData);
    file_put_contents($logFile, "Veri alındı: " . strlen($audioData) . " byte\n", FILE_APPEND);
}

fclose($fp);
file_put_contents($logFile, "AGI Bitti\n", FILE_APPEND);
?>

