#!/usr/bin/php
<?php

require 'phpagi.php';
require '/var/lib/asterisk/agi-bin/vendor/autoload.php';
use Aws\S3\S3Client;
use Aws\TranscribeService\TranscribeServiceClient;
$agi = new AGI();
try {
    $agi->answer();
	$recordId= uniqid();
    // Ses kaydını başlat
    $record_file = '/var/spool/asterisk/monitor/temp_input_'.$recordId.'';
    $flac_path = "/var/spool/asterisk/monitor/flacs/temp_input_".$recordId.".flac";

    $agi->verbose('ses kaydı başlatıldı');
    //$agi->exec('Playback', 'silence/1');
    $agi->record_file($record_file, 'wav', '', -1, 0, false, 1);

    // Ses dosyasının yolu
$recordedFilePath = '/var/spool/asterisk/monitor/temp_input_'.$recordId.'.wav';


//exec("flac --fast $recordedFilePath -o $flac_path");
//$command = "flac --fast $recordedFilePath -o $flac_path";
//exec($command . " 2>&1", $output, $return_var);

//$agi->verbose("Exec Output: " . implode("\n", $output));
//$agi->verbose("Exec Return Code: " . $return_var);



$command = "node /var/lib/asterisk/agi-bin/transcribe-and-dialog.js " . escapeshellarg($recordedFilePath);
$agi->verbose('Ses kaydı bitti, gönderiliyor');
//$id2 = uniqid();
//shell_exec("node /opt/aws-nodejs/polly.js --mp3=/var/spool/asterisk/monitor/polly-$id2.mp3 --text=Memnuniyetle --wav=/var/spool/asterisk/monitor/polly-$id2");
//$agi->stream_file("/var/spool/asterisk/monitor/polly-$id2");
$command = "node /usr/share/asterisk/agi-bin/transcribe-and-dialog.js '$recordedFilePath' 2>&1";
$agi->verbose("Komut çalıştırılıyor: " . $command);

$handle = popen("node /usr/share/asterisk/agi-bin/transcribe-and-dialog.js '$recordedFilePath' 2>&1", "r");
$sistemYaniti = "";

if ($handle) {
    while (($buffer = fgets($handle, 4096)) !== false) {
        $agi->verbose("Node.js Çıktısı: " . trim($buffer));

        if (strpos($buffer, "SET VARIABLE SISTEM_YANITI") !== false) {
            $sistemYaniti = trim(str_replace("SET VARIABLE SISTEM_YANITI ", "", $buffer));
            $agi->set_variable("SISTEM_YANITI", $sistemYaniti);
        }
    }
    pclose($handle);
}

$agi->verbose("Asterisk'e gönderilen yanıt: " . $sistemYaniti);
// JSON çıktısını çözümleyin
//$result = json_decode($output, true);

/*if ($result['success']) {
	$id = uniqid();
	$agi->set_variable('UNIQUE_ID',$id);
	
	$agi->verbose("Transcription: " . $result['transcription'] . "\n");
	$yanit = cevapVer($result['transcription'],$argv[2],$agi);
	if($yanit["http_code"] == 200){
	        $agi->verbose('sistemden yanıt geldi');
		$agi->set_variable('SISTEM_YANITI',$yanit['yanit']);
		$agi->set_variable('HIZMET',$yanit['hizmet']);
		$agi->set_variable('TARIH_SAAT',$yanit['tarih_saat']);
		//shell_exec("node /opt/aws-nodejs/polly.js --mp3=/var/spool/asterisk/monitor/polly-$id.mp3 --text=".escapeshellarg($yanit['yanit'])." --wav=/var/spool/asterisk/monitor/polly-$id");
		$agi->set_variable('INTENT',$yanit['intent_detected']);
		$agi->set_variable('INTENT_TEXT',$argv[1]." ".$yanit['intent_text']);
		$agi->set_variable('VERILER_TAMMI',$yanit['veriler_tammi']);
	
	}	
} else {
	$agi->verbose("transcription Error: " . $result['error'] . "\n");
}
 */

} catch (Exception $e) {
    $agi->verbose('Hata: ' . $e->getMessage());
    $agi->hangup();
}
function cevapVer($transcribe,$salonid,$agi)
{
    $url = 'https://app.randevumcepte.com.tr/api/v1/cevapVer';  // Laravel API URL'si
    $data = ['text' => $transcribe,'salonid'=>$salonid];  // Gönderilecek veri
    // cURL başlatma
    $ch = curl_init($url);

    // cURL seçeneklerini ayarlama
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data)); // JSON formatÄ±nda veri gÃ¶nderme 
    curl_setopt($ch, CURLOPT_HTTPHEADER, [ 'Content-Type: application/json' ]);
    // API yanıtını al
$start = microtime(true);
$response = curl_exec($ch);
$end = microtime(true);
$agi->verbose("API Yanıt Süresi: " . ($end - $start) . " saniye");

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
        $agi->verbose('JSON çözümleme başarılı.');
    }

    return $decoded_response;
}



?>
