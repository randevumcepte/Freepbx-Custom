#!/usr/bin/php
<?php

require 'phpagi.php';
$agi = new AGI();
$agi->answer();

$result = yoltarifigonder($argv[1],$argv[2],$agi);

$agi->verbose('Sonuç '.$result["success"],1);


$agi->set_variable('AGISTATUS',($result["success"]?"SUCCESS":"FAILURE"));



function yoltarifigonder($salonid,$userid,$agi)
{
    $url = 'https://app.eczella.com/api/v1/yolTarifiGonder';  // Laravel API URL'si
    $data = ['salonid' => $salonid,'userid'=>$userid,'cep_telefon'=>$agi->request["agi_callerid"]];  // Gönderilecek veri;
    // cURL başlatma
    $ch = curl_init($url);

    // cURL seçeneklerini ayarlama
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));  // JSON formatında veri gönderme
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',  // İçerik tipi belirtme
    ]);

    // API yanıtını al
    $response = curl_exec($ch);

    // cURL hatasını kontrol et
    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
        error_log('cURL Hatası: ' . $error_msg);
	$agi->verbose('cURL Hatası: ' . $error_msg);

    } else {
        $agi->verbose('cURL yanıtı alındı.');
    }

    // cURL bağlantısını kapatma
    
    curl_close($ch);
    
    // Yanıtı JSON formatında çözümle
    $decoded_response = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        $json_error = json_last_error_msg();
	error_log('JSON çözümleme hatası: ' . $json_error);
	$agi->verbose($response);
	$agi->verbose('JSON çözümleme hatası: ' . $json_error);
	error_log("Gelen JSON:\n" . json_encode(json_decode($response), JSON_PRETTY_PRINT));
    } else {
	    $agi->verbose('JSON çözümleme başarılı ' . json_encode($decoded_response), 1);
    }

    return $decoded_response;
}



?>
