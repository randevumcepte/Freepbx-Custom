#!/usr/bin/php
<?php

// Hata raporlama ayarları
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'phpagi.php';
$agi = new AGI();

try {
    $agi->verbose("AGI Başladı", 1);

   
    

    // API çağrısı:
    $response = api_call($argv[1]);
   
    $agi->verbose('randevu id '.$argv[1]);
    $agi->verbose($response);
    if (!empty($response)) {
        $agi->verbose('API başarılı, gelen veri: ' . json_encode($response), 1);

        $agi->set_variable('calinacakKayit', $response["message"]);
	$agi->set_variable('randevuid',$response["randevuid"]); 
	$agi->set_variable('AGISTATUS',$response["success"]? "success" : "failure");
    } else {
        $agi->verbose("API'den boş cevap geldi!", 1);
    }

} catch (Exception $e) {
    $agi->verbose('Hata: ' . $e->getMessage(), 1);
    $agi->set_variable('AGISTATUS', 'failure');
}

// API çağrısı yapan fonksiyon
function api_call($randevuid)
{
    $url = 'https://app.randevumcepte.com.tr/api/v1/asistanRandevuIptalEt';  
    $data = ['randevuid'=>$randevuid];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        throw new Exception('cURL Hatası: ' . curl_error($ch));
    }

    curl_close($ch);
    
    return json_decode($response, true);
}

?>
