#!/usr/bin/php
<?php

require 'phpagi.php';
require '/var/lib/asterisk/agi-bin/vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\TranscribeService\TranscribeServiceClient;

$agi = new AGI();

try {
    $agi->answer();
    
    //  Ses kaydını başlat (Daha iyi süre parametreleri ile)
    $record_file = '/var/spool/asterisk/monitor/temp_input';
    $agi->record_file($record_file, 'wav', '#', -1, 0, true, 0);

    //  Ses dosyasını transcribe.js ile işleme
    $recordedFilePath = '/var/spool/asterisk/monitor/temp_input.wav';
    $command = "node /var/lib/asterisk/agi-bin/transcribe.js " . escapeshellarg($recordedFilePath);
    $output = shell_exec($command);
    
    // JSON çıktısını çözümleme
    $result = json_decode($output, true);

    if (isset($result['success']) && $result['success']) {
        $transcription_text = $result['transcription'];
        $agi->verbose("Transcription: " . $transcription_text);

        //  Laravel API ile hizmet bulma
        $salon_id = isset($argv[1]) ? $argv[1] : null;
        $uyguntarih = hizmetbul($transcription_text, $salon_id, $agi);

        if ($uyguntarih) {
            //  Değişkenleri Asterisk'e gönder
            $agi->set_variable('uyguntarih', $uyguntarih['tarihsaat']);
            $agi->set_variable('personelid', $uyguntarih['personelid']);
            $agi->set_variable('hizmetid', $uyguntarih['hizmetid']);
        } else {
            $agi->verbose("Hizmet bulunamadı.");
        }
    } else {
        $agi->verbose("Transcription Error: " . ($result['error'] ?? "Bilinmeyen hata"));
    }
} catch (Exception $e) {
    $agi->verbose('Hata: ' . $e->getMessage());
    $agi->hangup();
}

/**
 * Laravel API üzerinden hizmet bulma fonksiyonu
 */
function hizmetbul($transcribe, $salonid, $agi) {
    $url = 'https://app.randevumcepte.com.tr/api/v1/hizmetbul';
    $data = ['hizmet' => $transcribe, 'salonid' => $salonid];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
        error_log('cURL Hatası: ' . $error_msg);
        $agi->verbose('cURL Hatası: ' . $error_msg);
        curl_close($ch);
        return null;
    }

    curl_close($ch);

    //  JSON dönüşüm hatasını yakalama
    $decoded_response = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $json_error = json_last_error_msg();
        error_log('JSON Çözümleme Hatası: ' . $json_error);
        $agi->verbose('JSON Çözümleme Hatası: ' . $json_error);
        return null;
    }

    return $decoded_response;
}

?>
