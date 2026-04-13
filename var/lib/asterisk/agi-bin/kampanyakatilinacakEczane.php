#!/usr/bin/php
<?php
require('phpagi.php');
$agi = new AGI();
$agi->verbose('ulaşıldı işaretlenecek');

$sonuc = kampanyaKatilicakKatilmayacakIsaretle($agi,$argv[1],$argv[2],$argv[3],$argv[4],$argv[5]);
$agi->verbose('json data : ' . json_encode($sonuc, JSON_UNESCAPED_UNICODE));
if($sonuc["success"]==true)
	$agi->set_variable('AGI_STATUS',"success");
else
	$agi->set_variable('AGI_STATUS','failure');
function kampanyaKatilicakKatilmayacakIsaretle($agi,$katilimciId,$katilacak,$etkinlikRandevuTarihi,$hizmetId,$urunId)
{

    $etkinlikRandevuSaati = '';

    if($etkinlikRandevuTarihi != '')
        $etkinlikRandevuSaati = etkinlikRandevuSaatiniBelirle();


    $url = 'https://app.eczella.com/api/v1/kampanyaKatilinacak';

    //  Gönderilen parametreleri logla
    //global $agi;
 
    $data = ['katilimci_id' => $katilimciId,'katilacak'=>$katilacak,'etkinlikRandevuTarihi'=>$etkinlikRandevuTarihi,'etkinlikRandevuSaati'=>$etkinlikRandevuSaati,'hizmetId'=>$hizmetId,'urunId'=>$urunId,'telefon'=>$agi->request['agi_callerid']];
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

function etkinlikRandevuSaatiniBelirle()
{
    $tarihSaatstr = '';
    while(true)
    {
        $etkinlikRandevuOlusturmaId= uniqid();
        shell_exec( "node /opt/aws-nodejs/polly.js --mp3=/var/spool/asterisk/monitor/etkinlikRandevusu-$etkinlikRandevuOlusturmaId.mp3 --text=". escapeshellarg('Randevu almak istediğiniz saati söyler misiniz?') ." --wav=/var/spool/asterisk/monitor/etkinlikRandevusu-$etkinlikRandevuOlusturmaId");
        $agi->stream_file("/var/spool/asterisk/monitor/etkinlikRandevusu-$etkinlikRandevuOlusturmaId");
        $tarihSaat = parseRandevuTarihi($agi);
        $tarihSaatstr = $tarihSaat['datetime'];
        if($tarihSaatstr != ''){
            $tarihSaatstr = date('H:i',strtotime($tarihSaatstr));
            return $tarihSaatstr;
        }
        else
            continue;
    } 
}
function parseRandevuTarihi($agi) {
    $recordId = uniqid();
    $recordFile = "/var/spool/asterisk/monitor/randevuTarihSaatInput" . $recordId;
    $agi->record_file($recordFile, "wav", "", 4000, 0, false, 2);
    $recordedFile = $recordFile . ".wav";

    $cmd = "node /var/lib/asterisk/agi-bin/transcribe2.js " . escapeshellarg($recordedFile);
    $output = shell_exec($cmd);
    $result = json_decode($output, true);

    $text = strtolower(trim($result['transcription'] ?? $result['text'] ?? ''));
    $agi->verbose('tarih transcribe output: ' . $text);

    if (!$text) {
        return ['error' => 'Transcribe başarısız'];
    }
    // $url = 'https://app.randevumcepte.com.tr/api/v1/parseGunSaatText';
    $url = 'http://93.115.79.188:5678/webhook/date-parser';
    $data = ['text'=>$result['transcription']];
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
    $agi->verbose('Tarih saat çeviri response : '.$response);
    //JSON dönüşüm hatasını yakalama
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

