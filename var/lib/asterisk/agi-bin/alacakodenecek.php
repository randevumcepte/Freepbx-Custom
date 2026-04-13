#!/usr/bin/php
<?php
require('phpagi.php');
$agi = new AGI();
$agi->verbose('alacak ödenecek işaretlenecek');

$sonuc = alacakOdenecekIsaretle($agi,$argv[1]);
$agi->verbose('json data : ' . json_encode($sonuc, JSON_UNESCAPED_UNICODE));
if($sonuc['success'])
	$agi->set_variable('AGI_STATUS','success');
else
	$agi->set_variable('AGI_STATUS','failure');
function alacakOdenecekIsaretle($agi, $alacakIdler)
{
    $url = 'https://app.randevumcepte.com.tr/api/v1/alacakOdenecek';

    //  Gönderilen parametreleri logla
    //global $agi;
    //$agi->verbose("Gönderilen randevu ID: " . $randevuId);
    $agi->verbose("Gönderilen alacak ID'ler: " . json_encode($alacakIdler));

    $data = [ 'alacak_idler' => json_decode($alacakIdler, true)];
    $agi->verbose("JSON Gönderilen Data: " . json_encode($data, JSON_UNESCAPED_UNICODE));

    // cURL başlat
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

    //  API Yanıtını Al
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    //  cURL Hatası Varsa Logla
    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
        error_log('cURL Hatası: ' . $error_msg);
        $agi->verbose('cURL Hatası: ' . $error_msg);
        curl_close($ch);
        return null;
    }

    //  API Yanıtı Logla
    $agi->verbose("API HTTP Kodu: " . $http_code);
    $agi->verbose("API Yanıtı: " . $response);

    curl_close($ch);

    // API Yanıtı Boşsa Uyarı Ver
    if (!$response) {
        $agi->verbose("Uyarı: API hiçbir yanıt döndürmedi!");
        return null;
    }

    // JSON Parse Hatası Varsa Logla
    $decoded_response = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $json_error = json_last_error_msg();
        $agi->verbose('JSON Çözümleme Hatası: ' . $json_error);
        $agi->verbose('API Yanıtı: ' . $response);
        return null;
    }

    $agi->verbose('Json başarılı');
    return $decoded_response;
}
?>

