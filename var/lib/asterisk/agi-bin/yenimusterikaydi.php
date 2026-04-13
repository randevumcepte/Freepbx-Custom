#!/usr/bin/php
<?php

require 'phpagi.php';
//require '/var/lib/asterisk/agi-bin/vendor/autoload.php';
//use Aws\S3\S3Client;
//use Aws\TranscribeService\TranscribeServiceClient;
$agi = new AGI();
try {
    $agi->answer();
	$recordId= uniqid();
    // Ses kaydını başlat
    $record_file = '/var/spool/asterisk/monitor/temp_input_'.$recordId.'';
   
   // $agi->exec('Playback', 'silence/1');
    //$agi->record_file($record_file, 'wav', '#', -1, 0, false, 0);
    //$agi->record_file($record_file, 'wav', '#',3000, 3, true, 2000);
   // $agi->record_file($record_file, 'wav', '#', 10000, 5000, false, 0);
    //$agi->record_file($record_file, 'wav', '', -1, 0, false, 2);
    $agi->record_file($record_file, "wav", "", 3000, 0, false, 2);
    // Kaydedilen dosyayı STT API'ye gönder
    //$stt_result = transcribe_audio($record_file . '.wav');

    // Gelen metni analiz et
    //$destination = determine_destination($stt_result);

    // Yönlendirme
   

// Ses dosyasının yolu
$recordedFilePath = '/var/spool/asterisk/monitor/temp_input_'.$recordId.'.wav';
$command = "node /var/lib/asterisk/agi-bin/transcribe2.js " . escapeshellarg($recordedFilePath);
$output = shell_exec($command);

// JSON çıktısını çözümleyin
$result = json_decode($output, true);

if ($result['success']) {
	$id = uniqid();

	$agi->verbose("Transcription: " . $result['transcription'] . "\n");
	$yenimusteri = yenimusteri($result['transcription'],$argv[1],$agi);
	$agi->verbose('Başarılı : '.$yenimusteri['success']);
		$agi->set_variable('AGISTATUS',$yenimusteri['success']? "success":"failure");	
		$agi->set_variable('yeniMusteriHosgeldin',$yenimusteri['message']);
	        $agi->set_variable('userId',$yenimusteri["userId"]);
	
} else {
	$agi->verbose("transcription Error: " . $result['error'] . "\n");
}


} catch (Exception $e) {
    $agi->verbose('Hata: ' . $e->getMessage());
    $agi->hangup();
}
function yenimusteri($transcribe,$salonid,$agi)
{
    $url = 'https://app.randevumcepte.com.tr/api/v1/yenimusteridanisankaydi';  // Laravel API URL'si
    $data = ['santraldenkayit'=>true,'name' => $transcribe,'salonidler'=>$salonid,'cep_telefon'=>$agi->request["agi_callerid"]];  // Gönderilecek veri
    $agi->verbose('Müşteri adı : '.$transcribe." salon id : ".$salonid);
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
        $agi->verbose('JSON çözümleme başarılı. Gelen json '.json_encode(json_decode($response)));
    }

    return $decoded_response;
}



?>
