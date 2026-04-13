#!/usr/bin/php -q
<?php
// [polly-simple]

ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', '/var/spool/asterisk/monitor/agi_error.log');  // Hataların log dosyasına kaydedilmesi
set_time_limit(30);
require('phpagi.php');
$agi = new AGI();
$agi->answer();

// Debug için
$agi->verbose("Başlangıç: Script çalışmaya başladı.");

// Benzersiz bir ID oluştur
$id = uniqid();

// Arayan numarasını al
$callerId = $agi->request['agi_callerid'];
$agi->verbose('Arayan numara: '. $callerId);

// Çağrının geldiği kanal bilgisi
$trunk = $agi->request['agi_channel'];
$agi->verbose('Çağrının geldiği kanal: '. $trunk);

// Karşılama metnini al
 $agi->exec('Ringing');  // Çalma sesi başlat
sleep(2);  
$karsilama_metni = karsilamametninical($agi, $callerId, $trunk);

// Karşılama metnini ve hata durumunu logla
if (isset($karsilama_metni['karsilama_metni'])) {
    $agi->verbose('Karşılama metni: '.$karsilama_metni['karsilama_metni']);
    error_log('Karşılama metni: ' . $karsilama_metni['karsilama_metni']);
} else {
    error_log('Karşılama metni bulunamadı.');
    $agi->verbose('Karşılama metni bulunamadı. '.json_encode($karsilama_metni));
}

// Başarılı ise Pollywood çalıştır
if ($karsilama_metni['success']) {
    $agi->set_variable('operatorkanali',$karsilama_metni['operator_kanali']);
    $agi->set_variable('SALON_ID',$karsilama_metni['salon_id']);
   $agi->set_variable('USER_ID',$karsilama_metni['user_id']);
    $calinacak = $karsilama_metni['karsilama_metni'];
    $agi->verbose("Polly çalıştırılıyor, metin: $calinacak");
    $output = shell_exec("node /opt/aws-nodejs/polly.js --mp3=/var/spool/asterisk/monitor/polly-$id.mp3 --text='$calinacak' --wav=/var/spool/asterisk/monitor/polly-$id");
    error_log("Shell komutunun çıktısı: " . $output);
} else {
    $agi->verbose("Geçici hata mesajı gönderiliyor.");
    $output = shell_exec("node /opt/aws-nodejs/polly.js --mp3=/var/spool/asterisk/monitor/polly-$id.mp3 --text='Sistemde geçici bir arıza mevcut. Lütfen daha sonra tekrar deneyiniz.'");
    error_log("Shell komutunun çıktısı: " . $output);
}

 // Dosyayı oynat
$agi->exec('StopRinging'); // Çalma sesini durdur
$agi->exec("Background","/var/spool/asterisk/monitor/polly-$id");
$result = $agi->wait_for_digit(10000);

// Eğer tuşlama yapıldıysa
if ($result['result'] != -1) {
    $pressed_key = chr($result['result']);
    $agi->verbose("Tuşlama Algılandı: " . $pressed_key);
    $agi->set_variable("MENU_SECIM", $pressed_key);  // Değeri Dialplan'a gönder
} else {
    $agi->verbose("Tuşlama Yapılmadı.");
}



//$agi->stream_file("/var/spool/asterisk/monitor/polly-$id","1234567890*#");
//$agi->verbose("Polly MP3 dosyası oynatıldı.");

/*if ($result['result'] > 0) {
    $agi->verbose("Tuşlama algılandı: " . chr($result['result']));
    $pressedKey = chr($result['result']); // Basılan tuş
    $agi->set_variable('TUSLAMA', $pressedKey); // Tuşlamayı dialplan'a gönder
} else {
    $agi->verbose("Hiç tuşlama yapılmadı.");
}*/

// JSON çözümleme hatası kontrolü
function karsilamametninical($agi, $callerid, $channel)
{
    $url = 'https://app.randevumcepte.com.tr/api/v1/santralkarsilamametni';  // Laravel API URL'si
    $data = ['callerid' => $callerid, 'channel' => $channel];  // Gönderilecek veri
    $agi->verbose('Caller id : '.$callerid);
    $agi->verbose('Channel : '.$channel);
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
    $agi->verbose($response);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $json_error = json_last_error_msg();
        error_log('JSON çözümleme hatası: ' . $json_error);
        $agi->verbose('JSON çözümleme hatası: ' . $json_error);
    } else {
        $agi->verbose('JSON çözümleme başarılı.');
    }

    return $decoded_response;
}

?>
