#!/usr/bin/php -q
<?php
// [polly-simple]
//

ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', '/var/spool/asterisk/monitor/agi_error.log');
error_reporting(E_ALL);
set_time_limit(30);
require('phpagi.php');
$agi = new AGI();
$agi->answer();

//$text= $argv[1];

$id= uniqid();
// Arayan numarasını al
$callerId = $agi->request['agi_callerid'];
$agi->verbose('Arayan numara: '. $callerId);

// Çağrının geldiği kanal bilgisi
$trunk = $agi->request['agi_channel'];

// Kanal bilgisi üzerinden trunk bilgisini ayıklayabilirsiniz
//$agi->verbose('Çağrının geldiği kanal: '. $trunk);


$karsilama_metni = karsilamametninical($agi,$callerId,$trunk);
error_log($karsilama_metni['karsilama_metni']);
$agi->verbose('Karşılama metni : '.$karsilama_metni['karsilama_metni']);

if($karsilama_metni['success']){
	$agi->set_variable('SALON_ID', $karsilama_metni['salon_id']);
    $calinacak=$karsilama_metni['karsilama_metni'];
    shell_exec("node /opt/aws-nodejs/polly.js --mp3=/var/spool/asterisk/monitor/polly-$id.mp3 --text='$calinacak' --wav=/var/spool/asterisk/monitor/polly-$id");

}
else
    shell_exec("node /opt/aws-nodejs/polly.js --mp3=/var/spool/asterisk/monitor/polly-$id.mp3 --text='Sistemde geçici bir arıza mevcut. Lütfen daha sonra tekrar deneyiniz.'");
//shell_exec("node /opt/aws-nodejs/polly.js --mp3=/var/spool/asterisk/monitor/polly-$id.mp3 --text='$text' --wav=/var/spool/asterisk/monitor/polly-$id");
$agi->stream_file("/var/spool/asterisk/monitor/polly-$id");

/*:$mp3file = "/tmp/polly-$id.mp3";
unlink($mp3file) or die("Couldn't delete file"); //deletes mp3 file
 
$wavfile = "/tmp/polly-$id.wav";
unlink($wavfile) or die("Couldn't delete file"); //deletes wav file
 */

function karsilamametninical($agi,$callerid,$channel)
{
    $url = 'https://app.randevumcepte.com.tr/api/v1/santralkarsilamametni';  // Laravel API URL'si
    $data = ['callerid' => $callerid,'channel'=>$channel];  // Gönderilecek veri

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
      $agi->verbose('curl hatası var' );
    }

    // cURL bağlantısını kapatma
    curl_close($ch);

    // Yanıtı JSON formatında çözümle
    return json_decode($response,true);

?>
