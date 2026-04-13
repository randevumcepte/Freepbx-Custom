#!/usr/bin/php
<?php

require 'phpagi.php';
$agi = new AGI();

// cURL ile API çağrısı yapan fonksiyon
function api_call($caller_id,$trunk)
{
    $url = 'https://example.com/api/musteribilgigetir';  // Laravel API URL'si
    $data = ['caller_id' => $caller_id,'trunk'=>$trunk];  // Gönderilecek veri

    // cURL başlatma
    $ch = curl_init($url);

    // cURL seçeneklerini ayarlama
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));  // JSON formatında veri gönderme
    curl_setopt($ch, CURLOPT_HTTPHEADER, 
        'Content-Type: application/json',  // İçerik tipi belirtme
    ]);

    // API yanıtını al
    $response = curl_exec($ch);

    // cURL hatasını kontrol et
    if (curl_errno($ch)) {
        throw new Exception('cURL Hatası: ' . curl_error($ch));
    }

    // cURL bağlantısını kapatma
    curl_close($ch);

    // Yanıtı JSON formatında çözümle
    return json_decode($response, true);
}
?>
