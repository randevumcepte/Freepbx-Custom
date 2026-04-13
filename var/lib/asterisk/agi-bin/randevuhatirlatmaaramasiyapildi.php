#!/usr/bin/php
<?php

 
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'phpagi.php';
$agi = new AGI();

try {
    $randevuid = $agi->get_variable('randevuid')['data'];
    if (!$randevuid) {
        throw new Exception('Randevu ID bulunamadı.');
    }

     
    $response = api_call($randevuid,$argv);

    if ($response['success']) {
        $agi->verbose('API başarılı: ' . $response['message'], 1);
        $agi->set_variable('AGISTATUS', 'SUCCESS');
    } else {
        throw new Exception($response['message']);
    }
} catch (Exception $e) {
    $agi->verbose('Hata: ' . $e->getMessage(), 1); // Hata mesajını AGI verbose ıçıktısına yazdırıyoruz
    $agi->set_variable('AGISTATUS', 'FAILURE');
}

// API çağrısı yapan örnek fonksiyon
function api_call($randevuid,$argv)
{
    $url = 'https://app.randevumcepte.com.tr/api/v1/randevuhatirlatmaaramasiyapildi';  // Laravel API URL'si
    $data = ['randevuid' => $randevuid,'hatirlamtaaramasiyapildi'=>$argv[2]];  // Gönderilecek veri

    // cURL başlatma
    $ch = curl_init($url);

    // cURL seıçeneklerini ayarlama
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));  // JSON formatında veri gönderme
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',  // içerik tipi belirtme
    ]);

    // API yanıtını al
    $response = curl_exec($ch);

    // cURL hatasını kontrol et
    if (curl_errno($ch)) {
        throw new Exception('cURL Hatası: ' . curl_error($ch));
    }

    // cURL bağlantısını kapatma
    curl_close($ch);

    // Yanıtı JSON formatında ıçözÃ¼mle
    return json_decode($response, true);
}
?>
