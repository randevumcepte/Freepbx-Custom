#!/usr/bin/php
<?php

// Hata raporlama ayarları
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'phpagi.php';
$agi = new AGI();

try {
    $agi->verbose("AGI Başladı", 1);

   
    

    // API çağrısı
    $response = api_call($argv[1],$argv[2],$argv[3]);
    $agi->verbose('randevu id '.$argv[1]);
    $agi->verbose('user id '.$argv[2]);
    $agi->verbose($response);
    if (!empty($response)) {
        $agi->verbose('API başarılı, gelen veri: ' . json_encode($response), 1);

        $agi->set_variable('calinacakKayit', $response["metin"]);
        $agi->set_variable('uygunrandevutarih',$response["tarihsaat"]!=""? date('Y-m-d', strtotime($response["tarihsaat"])) : "");
        $agi->set_variable('uygunrandevusaat', $response["tarihsaat"]!=""? date('H:i', strtotime($response["tarihsaat"])):"");
        $agi->set_variable('randevuid',$response["randevuid"]); 
    } else {
        $agi->verbose("API'den boş cevap geldi!", 1);
    }

} catch (Exception $e) {
    $agi->verbose('Hata: ' . $e->getMessage(), 1);
    $agi->set_variable('AGISTATUS', 'FAILURE');
}

// API çağrısı yapan fonksiyon
function api_call($randevuid,$userid,$salonid)
{
    $url = 'https://app.randevumcepte.com.tr/api/v1/uygunrandevubul';  
    $data = ['randevuid' => $randevuid,'userid'=>$userid,'salonid'=>$salonid];

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
