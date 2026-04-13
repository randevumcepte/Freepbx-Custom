#!/usr/bin/php -q
<?php
require "phpagi.php";
//require "/var/lib/asterisk/agi-bin/vendor/autoload.php";
//use Aws\S3\S3Client;
//use Aws\TranscribeService\TranscribeServiceClient;

error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', '/tmp/php_agi_errors.log');

$agi = new AGI();
try {
    $agi->answer();
    $recordId = uniqid();
    // ÖNCE BU AYARLARI MUTLAKA YAPIN:
// Silence detection ayarlarını DOĞRU şekilde set et
$agi->exec('Set', array('SILENCETHRESHOLD=128'));
$agi->exec('Set', array('SILENCECOUNT=50'));
$agi->exec('Set', array('TXGAIN=0'));
$agi->exec('Set', array('RXGAIN=0'));

    // Ses kaydını başlat
    $agi->verbose(date('H:i:s').' ses kaydı başladı');

    $record_file = "/var/spool/asterisk/monitor/temp_input_" . $recordId . "";
    
    $agi->record_file($record_file, "wav", "", 3000, 0, false, 2000);
    $agi->verbose(date('H:i:s').' ses kaydı bitti');

    // Ses dosyasının yolu
    $recordedFilePath =
        "/var/spool/asterisk/monitor/temp_input_" . $recordId . ".wav";

    $command = "node /var/lib/asterisk/agi-bin/transcribe2.js " .escapeshellarg($recordedFilePath);
    $agi->verbose(date('H:i:s').' ses kaydı çıktısı alınacak');
    $output = shell_exec($command);

    // JSON çıktısını çözümleyin
    $result = json_decode($output, true);
    $agi->verbose(date('H:i:s').' ses çıktısı alındı');
    $personelAdi = "";
    $hizmetAdi = '';
    $personelSecimiGerekli = false;
    if ($result["success"]) {
        $hizmetAdi = $result['transcription'];
        $uygunluk = hizmetbul($result["transcription"],$argv[1],$agi,'',false,'');
        $agi->verbose('dönen veri '.json_encode($uygunluk));
        if($uygunluk['hizmetbulunamadi'])
        {
                $hizmetVeremiyoruzId = uniqid();

                    shell_exec(
                        "node /opt/aws-nodejs/polly.js --mp3=/var/spool/asterisk/monitor/polly-$hizmetVeremiyoruzId.mp3 --text=".
                            escapeshellarg($result['transcription'] .' hizmetini maalesef veremiyoruz.') .
                            " --wav=/var/spool/asterisk/monitor/polly-$hizmetVeremiyoruzId"
                    );
                    $agi->stream_file("/var/spool/asterisk/monitor/polly-$hizmetVeremiyoruzId");
                $agi->set_variable('HIZMETYOK','EVET');

        }
        else{

            if(!$uygunluk['success'] && $uygunluk['personelSecimiGerekli'])
            {
                $breakOuter = false;
                    $personelSecimiGerekli = true;
                while(true){
                    $personelSecimAnonsId1 = uniqid();

                    shell_exec(
                        "node /opt/aws-nodejs/polly.js --mp3=/var/spool/asterisk/monitor/polly-$personelSecimAnonsId1.mp3 --text=" .
                            escapeshellarg($result['transcription'] .' hizmetini almak istediğiniz personeli belirtmek istiyor musunuz? Evet veya hayır diyebilirsiniz.') .
                            " --wav=/var/spool/asterisk/monitor/polly-$personelSecimAnonsId1"
                    );
                    $agi->stream_file("/var/spool/asterisk/monitor/polly-$personelSecimAnonsId1");
                    $evetHayir = evetHayir($agi);
                    $secim = $evetHayir["transcription"];

                    if (strtolower($secim) == "evet") {
                      while($personelSecimiGerekli){
                        $agi->verbose("Kullanıcı personel seçimi yapmak istedi.");
                        $personelSecimMesajId2 = uniqid();
                        $personelMesaj =    shell_exec(
                            "node /opt/aws-nodejs/polly.js --mp3=/var/spool/asterisk/monitor/polly-$personelSecimMesajId2.mp3 --text=" .
                                escapeshellarg($uygunluk['metin']) .
                                " --wav=/var/spool/asterisk/monitor/polly-$personelSecimMesajId2"
                        );
                        $agi->stream_file(
                            "/var/spool/asterisk/monitor/polly-" . $personelSecimMesajId2
                        );
                        $personelRecordId = uniqid();
                        $personelRecordFile =
                            "/var/spool/asterisk/monitor/personel_input_" .
                            $personelRecordId;
                        $agi->record_file(
                            $personelRecordFile,
                            "wav",
                            "",
                            2000,
                            0,
                            false,
                            2
                        );
                        $personelRecordedFilePath =
                            "/var/spool/asterisk/monitor/personel_input_" .
                            $personelRecordId .
                            ".wav";
                        $personelBilgiAl =
                            "node /var/lib/asterisk/agi-bin/transcribe2.js " .
                            escapeshellarg($personelRecordedFilePath);
                        $personelOutput = shell_exec($personelBilgiAl);
                        $personelMetinSonuc = json_decode($personelOutput, true);
                        $personelAdi = $personelMetinSonuc["transcription"];
                        
                        $agi->verbose('Hizmet '.$hizmetAdi);
                        $agi->verbose('Personel '.$personelAdi);
                        $hizmetPersonelBul = hizmetbul($hizmetAdi,$argv[1],$agi,$personelAdi,true,'');
                        foreach ($hizmetPersonelBul as $key => $value) {
                            $agi->verbose("  $key => $value");
                        }   
                        if($hizmetPersonelBul['personelSecimiGerekli'])
                        {
                            $personelSecimAnonsId3 = uniqid();
                            shell_exec("node /opt/aws-nodejs/polly.js --mp3=/var/spool/asterisk/monitor/polly-$personelSecimAnonsId3.mp3 --text=".scapeshellarg($hizmetPersonelBul['metin'])." --wav=/var/spool/asterisk/monitor/polly-$personelSecimAnonsId3"
                            );
                            $agi->stream_file(
                            "/var/spool/asterisk/monitor/polly-" . $personelSecimAnonsId3
                            );
                        
                        }       
                        else{
                            $personelSecimiGerekli = false;
                            $breakOuter=true;
                        }
                               
                      }
                     
                    } elseif (strtolower($secim) == "hayır") {
                        $agi->verbose("Kullanıcı direkt devam etmek istedi.");
                        $personelSecimiGerekli = false;
                        break; // Döngüden çık ve devam et
                    } else {
                        // Hatalı veya zaman aşımı oldu, uyarı ver
                        $uyariId = uniqid();
                        $uyariMesaj =
                            "Sizi anlayamadım.";
                        shell_exec(
                            "node /opt/aws-nodejs/polly.js --mp3=/var/spool/asterisk/monitor/polly-$uyariId.mp3 --text=" .
                                escapeshellarg($uyariMesaj) .
                                " --wav=/var/spool/asterisk/monitor/polly-$uyariId"
                        );
                        $agi->stream_file("/var/spool/asterisk/monitor/polly-$uyariId");
                    }
                    if($breakOuter) break;
                }
            }



            
           /* $tarihSaatId = uniqid();
            $tarihSaatSoyleAnons = base64_encode(
                "Randevu almak istediğiniz günü ve saati söyler misiniz?"
            );
             shell_exec(
                "node /opt/aws-nodejs/polly.js --mp3=/var/spool/asterisk/monitor/randevuTarihSaatAnons-$tarihSaatId.mp3 --text=" .
                    escapeshellarg(base64_decode($tarihSaatSoyleAnons)) .
                    " --wav=/var/spool/asterisk/monitor/randevuTarihSaatAnons-$tarihSaatId"
            );

            $agi->stream_file("/var/spool/asterisk/monitor/randevuTarihSaatAnons-" . $tarihSaatId);
            
            $tarihSaat = parseRandevuTarihi($agi);

            $agi->verbose('tarih saat str '.json_encode($tarihSaat, JSON_PRETTY_PRINT));*/
            $bekletmeId = uniqid();
            $bekletme = base64_encode(
                "Hemen kontrol sağlıyorum, sizi birkaç saniye bekleteceğim efendim."
            );
            shell_exec(
                "node /opt/aws-nodejs/polly.js --mp3=/var/spool/asterisk/monitor/polly-$bekletmeId.mp3 --text=" .
                    escapeshellarg(base64_decode($bekletme)) .
                    " --wav=/var/spool/asterisk/monitor/polly-$bekletmeId"
            );

            $agi->stream_file("/var/spool/asterisk/monitor/polly-" . $bekletmeId);

            $id = uniqid();
            $agi->set_variable("UNIQUE_ID_UYGUN_RANDEVU", $id);
            $agi->verbose("Transcription: " . $result["transcription"] . "\n");
            $uyguntarih = hizmetbul(
                $hizmetAdi,
                $argv[1],
                $agi,
                $personelAdi,
                true,
                ''
            );
            $uygunlukDuyuruId = uniqid();
            shell_exec(
                "node /opt/aws-nodejs/polly.js --mp3=/var/spool/asterisk/monitor/polly-$uygunlukDuyuruId.mp3 --text=" .
                    escapeshellarg(base64_decode($uyguntarih["metin"])) .
                    " --wav=/var/spool/asterisk/monitor/polly-$uygunlukDuyuruId"
            );
            $agi->stream_file("/var/spool/asterisk/monitor/polly-" . $uygunlukDuyuruId);
                $evetHayir = evetHayir($agi);     
            $secim = $evetHayir["transcription"];
            if(strtolower($secim)=="evet")
            {
                $randevuOlustur = randevuolustur($agi,$uyguntarih["hizmetid"],$uyguntarih["personelid"],date('Y-m-d',strtotime($uyguntarih['tarihsaat'])),date('H:i:s',strtotime($uyguntarih['tarihsaat'])),$argv[2],$argv[1],$uyguntarih["sure"],$uyguntarih["fiyat"],$uyguntarih["odaid"]);
                if($randevuOlustur['success'] == true)
                {
                        $agi->verbose('belirtilen tarihte randvu oluştu');
                        $agi->set_variable('RANDEVUOLUSTU','EVET');
                        $agi->set_variable('HIZMETYOK','HAYIR');
                }
                else{
                        $agi->set_variable('RANDEVUOLUSTU','HAYIR');
                        $agi->set_variable('HIZMETYOK','HAYIR');
                }
                
            }
            else{
                $tarihSaat2 = '';
                while(true)
                {
                    $tarihBelirtId = uniqid();

                    shell_exec(
                        "node /opt/aws-nodejs/polly.js --mp3=/var/spool/asterisk/monitor/polly-$tarihBelirtId.mp3 --text=".escapeshellarg('Randevu almak istediğiniz tarihi söyler misiniz. Örneğin '.birSonrakiTarihMetin($uyguntarih['tarihsaat']).' diyebilirsiniz.').  
                            " --wav=/var/spool/asterisk/monitor/polly-$tarihBelirtId"
                    );
                    $agi->stream_file("/var/spool/asterisk/monitor/polly-$tarihBelirtId");
                    
                    $tarihSaat2  = tarihSaatBelirt($agi);
                    if(gecmisTarihmi($tarihSaat2['transcription']))
                    {
                        $gecmisTarihId = uniqid();

                        shell_exec(
                            "node /opt/aws-nodejs/polly.js --mp3=/var/spool/asterisk/monitor/polly-$gecmisTarihId.mp3 --text=".escapeshellarg('Üzgünüm, geçmiş tarihe randevu oluşturamıyorum.').  
                                " --wav=/var/spool/asterisk/monitor/polly-$gecmisTarihId"
                        );
                        $agi->stream_file("/var/spool/asterisk/monitor/polly-$gecmisTarihId");
                    }
                    else 
                        break;
                }

                $agi->verbose('Söylenen tarih : '.$tarihSaat2['transcription']);
                $uyguntarih2 = hizmetbul(
                    $hizmetAdi,
                    $argv[1],
                    $agi,
                    $personelAdi,
                    true,
                    $tarihSaat2['transcription']
                );
                $uygunlukDuyuruId2 = uniqid(); 
                shell_exec(
                    "node /opt/aws-nodejs/polly.js --mp3=/var/spool/asterisk/monitor/polly-$uygunlukDuyuruId2.mp3 --text=" .
                        escapeshellarg(base64_decode($uyguntarih2["metin"])) .
                        " --wav=/var/spool/asterisk/monitor/polly-$uygunlukDuyuruId2"
                ); 

                $agi->stream_file("/var/spool/asterisk/monitor/polly-" . $uygunlukDuyuruId2);
                 $evetHayir2 = evetHayir($agi);
                $secim2 = $evetHayir2["transcription"];
                if(strtolower($secim2)=="evet")
                {
                    $randevuOlustur2 = randevuolustur($agi,$uyguntarih2["hizmetid"],$uyguntarih2["personelid"],date('Y-m-d',strtotime($uyguntarih2['tarihsaat'])),date('H:i:s',strtotime($uyguntarih2['tarihsaat'])),$argv[2],$argv[1],$uyguntarih2["sure"],$uyguntarih2["fiyat"],$uyguntarih2["odaid"]);
                    if($randevuOlustur2['success'] == true)
                    {
                        $agi->verbose('ikinci kez belirtilen tarihte randevu oluştu');
                        $agi->set_variable('RANDEVUOLUSTU','EVET');
                        $agi->set_variable('HIZMETYOK','HAYIR');
                    }
                    else{
                            $agi->set_variable('RANDEVUOLUSTU','HAYIR');
                            $agi->set_variable('HIZMETYOK','HAYIR');
                    }     
                }
                else
                        $agi->set_variable('RANDEVUOLUSTU','HAYIR');
                        $agi->set_variable('HIZMETYOK','HAYIR');
                
            }
        }
        
    } else {
        $agi->verbose("transcription Error: " . $result["error"] . "\n");
    }
} catch (Exception $e) {
    $agi->verbose("Hata: " . $e->getMessage());
    $agi->hangup();
}
function evetHayir($agi)
{
    $evetHayirRecordId = uniqid();
    $evetHayirRecordFile = "/var/spool/asterisk/monitor/evetHayirInput".$evetHayirRecordId;
    $agi->record_file($evetHayirRecordFile, "wav", "", 2000, 0, false, 2);
    $evetHayirRecordedFile = $evetHayirRecordFile . ".wav"; // aynı isimden devam
    $evetHayirAl = "node /var/lib/asterisk/agi-bin/transcribe2.js " .
                   escapeshellarg($evetHayirRecordedFile);
    $evetHayirSonuc = shell_exec($evetHayirAl);
    $agi->verbose("Evet/Hayır raw response: ".$evetHayirSonuc);
    $result = json_decode($evetHayirSonuc, true);
    return $result;
}
function gecmisTarihmi($tarih)
{

        $aylar = [
        'ocak' => '01',
        'şubat' => '02',
        'mart' => '03',
        'nisan' => '04',
        'mayıs' => '05',
        'haziran' => '06',
        'temmuz' => '07',
        'ağustos' => '08',
        'eylül' => '09',
        'ekim' => '10',
        'kasım' => '11',
        'aralık' => '12'
    ];


    list($gun, $ayIsmi) = explode(' ', strtolower($tarih));
    $ayNumara = $aylar[$ayIsmi];
    $tarihDonusturulmus = date('Y')."-$ayNumara-$gun";
    if(date('Y-m-d')>date('Y-m-d',strtotime($tarihDonusturulmus)))
        return true;
    else
        return false;


}


function birSonrakiTarihMetin($tarih)
{
    $gun = date('d',strtotime($tarih));
    $gunStr = str_replace(['01','02','03','04','05','06','07','08','09'],['1','2','3','4','5','6','7','8','9'],$gun);
    $ay = date('m',strtotime($tarih));
    $ayStr = str_replace(['01','02','03','04','05','06','07','08','09','10','11','12'],['ocak','şubat','mart','nisan','mayıs','haziran','temmuz','ağustos','eylül','ekim','kasım','aralık'],$ay);
    return $gunStr." ".$ayStr;
}
function tarihSaatBelirt($agi)
{
    $tarihSaatRecordId = uniqid();
    $tarihSaatRecordFile = "/var/spool/asterisk/monitor/evetHayirInput".$tarihSaatRecordId;
    $agi->record_file($tarihSaatRecordFile, "wav", "", 3000, 0, false, 2);
    $tarihSaatRecordedFile = $tarihSaatRecordFile . ".wav"; // aynı isimden devam
    $tarihSaatAl = "node /var/lib/asterisk/agi-bin/transcribe2.js " .
                   escapeshellarg($tarihSaatRecordedFile);
    $tarihSaatSonuc = shell_exec($tarihSaatAl);
    $agi->verbose("Evet/Hayır raw response: ".$tarihSaatSonuc);
    $result = json_decode($tarihSaatSonuc, true);
    return $result;
}

function hizmetbul($transcribe, $salonid, $agi, $personeltranscribe,$personelEkle,$tarihSaat)
{
    $agi->verbose(date('H:i:s').' sunucu hizmet personel ve uygun tarih  araması yapılıyor');
    $url = "https://app.randevumcepte.com.tr/api/v1/hizmetbul"; // Laravel API URL'si
    $data = ["hizmet" => $transcribe, "salonid" => $salonid, "tarihSaat" => $tarihSaat]; // Gönderilecek veri
    $agi->verbose('personel bilgi ekleniyor '.$personelEkle);
    if ($personeltranscribe != "" || $personelEkle) {
        $data["personelAdi"] = $personeltranscribe;
    }

    foreach ($data as $key => $value) {
        $agi->verbose("  $key => $value");
    }
   // $agi->verbose('REquest parametreleri : '.json_encode($data,JSON_PRETTY_PRINT));
    //$agi->verbose("hizmet : " . $transcribe . " salon id : " . $salonid);
    // cURL başlatma

    $agi->verbose('hizmet bul datası '.json_encode($data, JSON_PRETTY_PRINT));
    $ch = curl_init($url);

    // cURL seçeneklerini ayarlama
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data)); // JSON formatında veri gönderme
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json", // İçerik tipi belirtme
    ]);

    // API yanıtını al
    $response = curl_exec($ch);

    // cURL hatasını kontrol et
    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
        error_log("cURL Hatası: " . $error_msg);
        $agi->verbose(date('H:i:s')." cURL Hatası: " . $error_msg);
    } else {
        $agi->verbose(date('H:i:s')." cURL yanıtı alındı.");
    }

    // cURL bağlantısını kapatma

    curl_close($ch);

    // Yanıtı JSON formatında çözümle
    $decoded_response = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        $json_error = json_last_error_msg();
        error_log("JSON çözümleme hatası: " . $json_error);
        $agi->verbose($response);
        $agi->verbose("JSON çözümleme hatası: " . $json_error);
        error_log(
            "Gelen JSON:\n" .
                json_encode(json_decode($response), JSON_PRETTY_PRINT)
        );
    } else {
        $agi->verbose("JSON çözümleme başarılı.".json_encode(json_decode($response), JSON_PRETTY_PRINT));
    }

    return $decoded_response;
}
function parseRandevuTarihi($agi) {
    $recordId = uniqid();
    $recordFile = "/var/spool/asterisk/monitor/randevuTarihSaatInput" . $recordId;
    $agi->record_file($recordFile, "wav", "", 5000, 0, false, 2);
    $recordedFile = $recordFile . ".wav";

    $cmd = "node /var/lib/asterisk/agi-bin/transcribe2.js " . escapeshellarg($recordedFile);
    $output = shell_exec($cmd);
    $result = json_decode($output, true);

    $text = strtolower(trim($result['transcription'] ?? $result['text'] ?? ''));
    $agi->verbose('tarih transcribe output: ' . $text);

    if (!$text) {
        return ['error' => 'Transcribe başarısız'];
    }

    // Yazım ve yanlış algı düzeltmeleri
    $replacements = [
        'ya 1 kat' => 'saat',
        'bir kat' => 'saat',
        'kat' => 'saat',
        'gelecek' => 'önümüzdeki',
        'buçuk' => ':30',
        'yarım' => ':30',
        'çeyrek geçe' => ':15',
        'çeyrek kala' => '-15',
    ];
    $text = str_replace(array_keys($replacements), array_values($replacements), $text);
    $text = preg_replace('/[^a-z0-9ğüşıöç\s:.-]/u', '', $text);

    date_default_timezone_set('Europe/Istanbul');

    // Gün isimleri
    $days = [
        'pazartesi' => 1,
        'salı' => 2,
        'sali' => 2,
        'çarşamba' => 3,
        'carsamba' => 3,
        'perşembe' => 4,
        'persembe' => 4,
        'cuma' => 5,
        'cumartesi' => 6,
        'pazar' => 0
    ];

    $today = date('d-m-Y');
    $tomorrow = date('d-m-Y', strtotime('+1 day'));
    $dayAfter = date('d-m-Y', strtotime('+2 day'));

    $text = str_replace(['bugün', 'yarın', 'öbür gün'], [$today, $tomorrow, $dayAfter], $text);

    // Haftaya / Önümüzdeki hafta ifadeleri
    foreach ($days as $gun => $num) {
        if (preg_match("/(önümüzdeki|haftaya)\s*$gun/", $text)) {
            $todayNum = date('w');
            $addDays = ($num - $todayNum + 7) % 7;
            if ($addDays == 0) $addDays = 7;
            $target = date('d-m-Y', strtotime("+$addDays days +7 days"));
            $text = preg_replace("/(önümüzdeki|haftaya)\s*$gun/", $target, $text);
        } elseif (preg_match("/$gun/", $text)) {
            $todayNum = date('w');
            $addDays = ($num - $todayNum + 7) % 7;
            if ($addDays == 0) $addDays = 7;
            $target = date('d-m-Y', strtotime("+$addDays days"));
            $text = preg_replace("/$gun/", $target, $text);
        }
    }

    // Saat düzeltmesi ("sabah 9", "öğleden sonra 3", "akşam 7 buçuk" vs.)
    $text = preg_replace_callback('/(sabah|öğleden sonra|öğlen|akşam)?\s*saat?\s*(\d{1,2})([:\.]?(\d{2}))?/', function($m) {
        $hour = intval($m[2]);
        $minute = isset($m[4]) ? $m[4] : '00';
        $mod = $m[1] ?? '';

        if ($mod == 'öğleden sonra' || $mod == 'akşam') {
            if ($hour < 12) $hour += 12;
        } elseif ($mod == 'sabah' && $hour == 12) {
            $hour = 0;
        } elseif ($mod == 'öğlen') {
            $hour = 12;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }, $text);

    // “çeyrek kala” düzeltmesi (örnek: “çeyrek kala 3” → “14:45”)
    if (preg_match('/(\d{1,2}):?-15/', $text, $m)) {
        $h = intval($m[1]) - 1;
        if ($h < 0) $h = 23;
        $text = preg_replace('/(\d{1,2}):?-15/', sprintf('%02d:45', $h), $text);
    }

    // d-m-Y ve H:i'yi yakala
    if (preg_match('/(\d{2}-\d{2}-\d{4}).*(\d{2}:\d{2})/', $text, $m)) {
        $datetime = DateTime::createFromFormat('d-m-Y H:i', "{$m[1]} {$m[2]}");
        return [
            'datetime' => $datetime->format('Y-m-d H:i:s'),
            'original_text' => $result['transcription'],
            'parsed_text' => $text
        ];
    }

    // Sadece saat varsa bugünün tarihini kullan
    if (preg_match('/(\d{2}:\d{2})/', $text, $m)) {
        $datetime = DateTime::createFromFormat('d-m-Y H:i', date('d-m-Y') . " {$m[1]}");
        return [
            'datetime' => $datetime->format('Y-m-d H:i:s'),
            'original_text' => $result['transcription'],
            'parsed_text' => $text
        ];
    }

    return [
        'datetime' => '',
        'error' => 'Tarih çözümlenemedi',
        'original_text' => $result['transcription'],
        'parsed_text' => $text
    ];
}



function randevuolustur($agi,$hizmetid,$personelid,$tarih,$saat,$userId,$salonId,$sure,$fiyat,$odaid)
{
    $url = 'https://app.randevumcepte.com.tr/api/v1/santralRandevuEkle';

   //$agi->set_variable('hizmet_id',$hizmetid);

    $data = ['easistan'=>1,'olusturan_user_id'=>$user_id,'salon_id'=>$salonId,'user_id'=>$userId,'durum'=>0,'hizmetler' => [$hizmetid], 'randevuPersonelleri' => [$personelid],'tarih'=>$tarih,'saat'=>$saat,'hizmetSuresi'=>[$sure],'hizmetFiyati'=>[$fiyat],'randevuOdalari'=>[$odaid]];

    $agi->verbose("RANDEVU_JSON: " . json_encode($data, JSON_UNESCAPED_UNICODE));

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
    $agi->verbose('Randevu olşuturma response : '.$response);
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
