#!/usr/bin/php
<?php

// Hata raporlama ayarlarını etkinleştiriyoruz
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'phpagi.php';
$agi = new AGI();

try {
	$randevuid = $argv[1];
	$randevutarihi = $argv[2];
	$randevusaati = $argv[3];
    

    // API çağrısı
    $response = api_call($argv,$agi);

    if ($response) {
        $agi->verbose('API başarılı: ' . $response['message'], 1);
	$agi->set_variable('AGISTATUS', 'SUCCESS');
	$agi->set_variable('TARIH_BUGUN_YARIN',convertToBugunYarin($argv[2]));
    } else {
        $agi->set_variable('AGISTATUS','FAILURE');
    }
} catch (Exception $e) {
    $agi->verbose('Hata: ' . $e->getMessage(), 1); // Hata mesajını AGI verbose çıktısına yazdırıyoruz
    $agi->set_variable('AGISTATUS', 'FAILURE');
}
function convertToBugunYarin($tarih)
{
        if(date('Y-m-d',strtotime($tarih))==date('Y-m-d'))
            return "Bugün";
        else if(date('Y-m-d',strtotime('+ 1 day',strtotime(date('Y-m-d')))) == date('Y-m-d',strtotime($tarih)))
            return "Yarın";
        else
            return $tarih;
 }   
// API çağrısı yapan örnek fonksiyon
function api_call($argv,$agi)
{
    $url = 'https://app.randevumcepte.com.tr/api/v1/randevuyuenyakintariheguncelle';  // Laravel API URL'si
    $data = ['randevuid' => $argv[1],'randevutarihi'=>$argv[2],'randevusaati'=>$argv[3]];  // Gönderilecek veri

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
        throw new Exception('cURL Hatası: ' . curl_error($ch));
    }

    // cURL bağlantısını kapatma
    curl_close($ch);

    // Yanıtı JSON formatında çözümle
    return $response;
}
?>
