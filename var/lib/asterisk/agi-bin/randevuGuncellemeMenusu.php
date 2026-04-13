#!/usr/bin/php -q
<?php
require "phpagi.php";
require_once '/var/lib/asterisk/agi-bin/DateParser.php';
//require "/var/lib/asterisk/agi-bin/vendor/autoload.php";
//use Aws\S3\S3Client;
//use Aws\TranscribeService\TranscribeServiceClient;

error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', '/tmp/php_agi_errors.log');
$agi = new AGI();

try{

    $agi->answer(); 
    $enYakinRandevu = json_decode(base64_decode($argv[1]),true);
    $anons = '';

    if(count($enYakinRandevu) > 1)
    {
        $anons  .= 'Bu hafta '.count($enYakinRandevu).' aktif randevunuz görünüyor. ';
        foreach($enYakinRandevu as  $key=> $randevu)
        {
            $anons .= $key+1 .'. randevunuz. '.turkceGun($randevu['tarih']) ." ".$randevu['saat'].", "; 
            if($randevu['paketAdi'] != null)
            {
                $anons .= $randevu['paketAdi'].' paketinizin '.$randevu['seansNo'].'. seansı. ';
            }
            if($randevu['hizmetler'] != null)
                $anons .= $randevu['hizmetler'].'. ';
        }
        $anons.= 'Hangisini güncellemek istersiniz? Güncellemek istediğiniz randevunuzun numarasını söylemeniz yeterlidir.';
    }
    else
    {
        $anons .= $enYakinRandevu[0]['tarih'].' '.turkceGun($enYakinRandevu[0]['tarih']).' günü saat '.$enYakinRandevu[0]['saat'].' ';
        if($enYakinRandevu[0]['paketAdi'] != null)
        {
            $anons .= $enYakinRandevu[0]['paketAdi'].' paketinizin '.$enYakinRandevu[0]['seansNo'].'. seans ';
        }
        if($enYakinRandevu[0]['hizmetler'] != null)
            $anons .= $enYakinRandevu[0]['hizmetler'].' ';
        $anons.='randevunuzu başka bir gün ve saate erteleyebiliriz. Randevunuzu hangi gün ve saate ertelemek istersiniz?';
    } 

    $secilenRandevu = $enYakinRandevu[0];
    $userId = $secilenRandevu['userId'];

    $sesDosyasi = anonsCal($agi, $anons, 'guncellenecekRandevulariBelirtmeAnonsu', $userId,$sesDosyasi);

    /* =========================
       RANDEVU SEÇİMİ (MAX 2)
    ==========================*/

    if(count($enYakinRandevu)>1)
    {
        $randevuSecildi = false;

        for($dongu1 = 0; $dongu1 < 2; $dongu1++)
        {
            $randevuSecimKaydi = kayitAl($agi, $userId, "guncellenecekRandevuBelirlemeInput_".$userId."_".date('YmdHis'), 2000);
            $secilenRandevuTranscribed = sesiMetneDonustur($randevuSecimKaydi); 
            $agi->verbose('hangi randevuyu seçtim '.$secilenRandevuTranscribed);

            if(strpos($secilenRandevuTranscribed, 'son') !== false 
                || strpos($secilenRandevuTranscribed, 'sonuncu') !== false
                || strpos($secilenRandevuTranscribed, 'son randevu') !== false
                || strpos($secilenRandevuTranscribed, 'bonbon') !== false
            )
            {
                $randevuIndex = count($enYakinRandevu)-1;
                $secilenRandevu = $enYakinRandevu[$randevuIndex];
                $randevuSecildi = true;
                break;
            }
            else
            {
                $randevuIndex = turkceSayiyiIntCevir($secilenRandevuTranscribed);
                $agi->verbose('kaçıncı randevuyu seçtim '.$randevuIndex);

                if(is_numeric($randevuIndex) && isset($enYakinRandevu[$randevuIndex - 1]))
                {
                    $secilenRandevu = $enYakinRandevu[$randevuIndex - 1];
                    $randevuSecildi = true;
                    break;
                }
            }
            $sesDosyasiSecimAnlayamadim = anonsCal($agi, "Sizi anlayamadım.", 'randevuSecimHatasi', $userId,$sesDosyasiSecimAnlayamadim);
            if($dongu1==0)
            {
                
                $sesDosyasi = anonsCal($agi, $anons, 'guncellenecekRandevulariBelirtmeAnonsu', $userId,$sesDosyasi);
            }
           
        }

        if(!$randevuSecildi)
        {
            $agi->set_variable('MENU_RESULT', 'FAILURE');
            return;
        }

        $ikinciAnons = turkceGun($secilenRandevu['tarih']).' günü saat '.$secilenRandevu['saat'].' ';
        if($secilenRandevu['paketAdi'] != null)
        {
            $ikinciAnons .= $secilenRandevu['paketAdi'].' paketinizin '.$secilenRandevu['seansNo'].'. seans ';
        }
        if($secilenRandevu['hizmetler'] != null)
            $ikinciAnons .= $secilenRandevu['hizmetler'].' ';

        $ikinciAnons.='randevunuzu başka bir gün ve saate erteleyebiliriz. Randevunuzu hangi gün ve saate ertelemek istersiniz?';
        $sesDosyasi2 = anonsCal($agi, $ikinciAnons, 'randevuErtelemeAnonsu', $userId,$sesDosyasi2);
    }

    /* =========================
       TARİH SAAT (MAX 2)
    ==========================*/

    for($dongu2 = 0; $dongu2 < 2; $dongu2++)
    {
        $randevuTarihSaatKaydi = kayitAl($agi, $userId, "randevuGuncellemeTarihSaatInput_".$userId."_".date('YmdHis'), 4000); 
        $randevuTarihSaat = sesiMetneDonustur($randevuTarihSaatKaydi); 

        $agi->verbose("Kayıt dosyası: " . $randevuTarihSaatKaydi); 
        $agi->verbose("Transkripsiyon sonucu: " . $randevuTarihSaat);

        if (empty(trim($randevuTarihSaat))) 
        {
            $sesDosyasiTarihSecimAnlayamadim = anonsCal($agi, "Sizi anlayamadım.", 'randevuTarihSaatHatasi', $userId,$sesDosyasiTarihSecimAnlayamadim);

             // ❗ SADECE İLK DENEMEDE TEKRAR ANONS
            if($dongu2 == 0)
            {
                if(count($enYakinRandevu)>1)
                    $sesDosyasi2 = anonsCal($agi, $ikinciAnons, 'randevuErtelemeAnonsu', $userId,$sesDosyasi2);
                else
                    $sesDosyasi = anonsCal($agi, $anons, 'guncellenecekRandevulariBelirtmeAnonsu', $userId,$sesDosyasi);
            }
            continue;
        }

        try {

            $tarihSaatParser = new DateParser();
            

            //$parsedDateTime = $tarihSaatParser->parseTurkishDate($randevuTarihSaat);

            $parsedDateTime = parseDateWithChrono($randevuTarihSaat);


            //$validation = $tarihSaatParser->validateDateTime($parsedDateTime);

            if ($parsedDateTime != null) 
            {


                $kontrolSaglamaAnonsu = date('Y-m-d',strtotime($parsedDateTime)).' '.turkceGun(date('Y-m-d',strtotime($parsedDateTime))).' günü saat '.date('H:i',strtotime($parsedDateTime)).' için kontrol sağlıyorum.';
                $sesDosyasi8 = anonsCal($agi,$kontrolSaglamaAnonsu,'randevuKontrolSaglamaAnonsu',$secilenRandevu['userId'],$sesDosyasi8);
                $uyguntarih = randevuBul(
                    $secilenRandevu['salonId'],
                    $agi,
                    null,
                    null,
                    date('Y-m-d H:i',strtotime($parsedDateTime)),
                    null,
                    $secilenRandevu['randevuId']
                );

                if($uyguntarih['success'])
                {
                    $response = randevuGuncelle(
                        $secilenRandevu['randevuId'],
                        date('Y-m-d',strtotime($parsedDateTime)),
                        date('H:i:s',strtotime($parsedDateTime))
                    );

                    if ($response) 
                    {
                        $agi->set_variable('MENU_RESULT', 'SUCCESS');
                        $agi->set_variable('TARIH_BUGUN_YARIN',convertToBugunYarin(date('Y-m-d',strtotime($parsedDateTime))));
                        $agi->set_variable('uygunrandevusaat',convertToBugunYarin(date('H:i',strtotime($parsedDateTime))));
                        return;
                    }
                }
                else
                {
                    $anonsAlternatif2 = date('Y-m-d',strtotime($parsedDateTime)).' '.turkceGun(date('Y-m-d',strtotime($parsedDateTime))).' günü saat '.date('H:i',strtotime($parsedDateTime)).' için maalesef randevu veremiyoruz. ';
                    $sesDosyasi_9 = anonsCal($agi,$anonsAlternatif2,'randevuGuncellemeUygunAnonsu',$userId,$sesDosyasi_9);
                    continue;
                }
            }
            else
            {
                $sesDosyasi11 = anonsCal($agi, "Sizi anlayamadım.", 'guncellemeTarihHataAnonsu', $userId,$sesDosyasi11);
               // ❗ SADECE İLK DENEMEDE TEKRAR ANONS
                if($dongu2 == 0)
                {
                    if(count($enYakinRandevu)>1)
                        $sesDosyasi2 = anonsCal($agi, $ikinciAnons, 'randevuErtelemeAnonsu', $userId,$sesDosyasi2);
                    else
                        $sesDosyasi = anonsCal($agi, $anons, 'guncellenecekRandevulariBelirtmeAnonsu', $userId,$sesDosyasi);
                }

                continue;
            }

        } catch (Exception $e) {

            $sesDosyasi12 = anonsCal($agi, "Sizi anlayamadım.", 'guncellemetarihHataAnonsu', $userId,$sesDosyasi12);
             // ❗ SADECE İLK DENEMEDE TEKRAR ANONS
            if($dongu2 == 0)
            {
                if(count($enYakinRandevu)>1)
                    $sesDosyasi2 = anonsCal($agi, $ikinciAnons, 'randevuErtelemeAnonsu', $userId,$sesDosyasi2);
                else
                    $sesDosyasi = anonsCal($agi, $anons, 'guncellenecekRandevulariBelirtmeAnonsu', $userId,$sesDosyasi);
            }
            continue;
        }
    }

    $agi->set_variable('MENU_RESULT', 'FAILURE');
}
catch (Exception $e) {
    $agi->verbose("Hata: " . $e->getMessage());
    $agi->hangup();
}
function turkceSayiyiIntCevir($input)
{
    $input = strtolower(trim($input));

    $input = str_replace(
        ['ı','i̇','ş','ğ','ü','ö','ç'],
        ['i','i','s','g','u','o','c'],
        $input
    );

    $map = [
        'ilk randevu'=>1,
        'ilk'=>1,
        'bir' => 1,
        'birinci' => 1,
        'ilk' => 1,
        '1' => 1,

        'iki' => 2,
        'ikinci' => 2,
        '2' => 2,

        'uc' => 3,
        'ucuncu' => 3,
        '3' => 3,

        'dort' => 4,
        'dorduncu' => 4,
        '4' => 4,

        'bes' => 5,
        'besinci' => 5,
        '5' => 5,

        'alti' => 6,
        'altinci' => 6,
        '6' => 6,

        'yedi' => 7,
        'yedinci' => 7,
        '7' => 7,

        'sekiz' => 8,
        'sekizinci' => 8,
        '8' => 8,

        'dokuz' => 9,
        'dokuzuncu' => 9,
        '9' => 9,

        'on' => 10,
        'onuncu' => 10,
        '10' => 10,
    ];

    // direkt eşleşme
    if(isset($map[$input]))
        return $map[$input];

    // cümle içinden yakalama
    foreach($map as $kelime => $sayi)
    {
        if(strpos($input, $kelime) !== false)
            return $sayi;
    }

    return null;
}

function turkceGun($tarih)
{
                                $turkceGunler = [
                                    'Monday' => 'Pazartesi',
                                    'Tuesday' => 'Salı',
                                    'Wednesday' => 'Çarşamba',
                                    'Thursday' => 'Perşembe',
                                    'Friday' => 'Cuma',
                                    'Saturday' => 'Cumartesi',
                                    'Sunday' => 'Pazar'
                                ];
                                $timestamp = strtotime($tarih);
                                $ingilizceGun = date('l', $timestamp);
                                $turkceGun = $turkceGunler[$ingilizceGun];
                                return $turkceGun;
 // sesliYanitOptimize.php içinde
}
function kayitAlYeni($agi)
{
    $uniqueId = $agi->request['agi_uniqueid'];
    $channelId = $agi->request['agi_channel'] ?? $_SERVER['REMOTE_ADDR'] . ':' . $_SERVER['REMOTE_PORT'];
    
    $agi->verbose("📞 ARI ExternalMedia başlatılıyor: " . $uniqueId);
    
    // 1. UNIQUEID'yi STT sunucuya kaydet
    $ch2 = curl_init('http://127.0.0.1:5002/register');
    curl_setopt_array($ch2, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'channelId' => $channelId,
            'uniqueId' => $uniqueId
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 1
    ]);
    curl_exec($ch2);
    curl_close($ch2);
    
    // 2. ARI'ye POST
    $ariUrl = "http://localhost:8088/ari/channels/externalMedia";
    $ariAuth = base64_encode("admin:9fc2028e7d4a56655529ff01dad31945");
    
    $payload = [
        "channelId" => $uniqueId,
        "app" => "external-media",
        "external_host" => "127.0.0.1:5001",
        "format" => "slin16"
    ];
    
    $ch = curl_init($ariUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Authorization: Basic " . $ariAuth
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 2
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode != 200 && $httpCode != 201) {
        $agi->verbose("❌ ARI hatası: HTTP $httpCode");
        return '';
    }
    
    $agi->verbose("✅ ExternalMedia başlatıldı");
    
    // 3. Transcript bekle
    $transcriptFile = "/tmp/transcript_{$uniqueId}.txt";
    $maxWait = 10;
    $waited = 0;
    
    while (!file_exists($transcriptFile) && $waited < $maxWait) {
        usleep(500000);
        $waited += 0.5;
    }
    
    if (file_exists($transcriptFile)) {
        $transcript = file_get_contents($transcriptFile);
        unlink($transcriptFile);
        $agi->verbose("✅ Transkript: " . $transcript);
        return $transcript;
    }
    
    $agi->verbose("⚠️ Transcript zaman aşımı");
    return '';
}
function kayitAl($agi,$userId,$dosyaAdi,$kayitSuresi)
{
    $kayitDosyasi = "/var/spool/asterisk/monitor/".$dosyaAdi;
    $agi->record_file($kayitDosyasi, "wav", "", $kayitSuresi, 0, false, 2000);
    return $kayitDosyasi.".wav";

}
function sesiMetneDonustur($sesDosyasi)
{

    $sesDosyasiYolu = $sesDosyasi;
    $transcribeKomutu = "node /var/lib/asterisk/agi-bin/transcribe2.js " .escapeshellarg($sesDosyasiYolu);
    $transcribeCiktisi = shell_exec($transcribeKomutu);
    $transcribeSonucu = json_decode($transcribeCiktisi, true);
    if($transcribeSonucu['success'])
        return strtolower($transcribeSonucu['transcription']);
    else{

        return $transcribeSonucu;
    }
}
function anonsCal($agi,$metin,$anonsTuru,$userId,$eskiSesDosyasi)
{   
    
    $fileName = "/var/spool/asterisk/monitor/".$anonsTuru."_".$userId."_".date('YmdHis');
    if($eskiSesDosyasi != ''){
        $fileName = $eskiSesDosyasi;
        $agi->verbose('Stt yeniden çalışmayacak ses kaydı var zaten');
    }
    else
        shell_exec("node /opt/aws-nodejs/polly.js --mp3=".$fileName.".mp3 --text=" . escapeshellarg($metin) ." --wav=".$fileName );
    $agi->stream_file($fileName);
    return $fileName;
}
function evetHayirVaryasyon($evetHayir)
{
    $evetVaryasyonlar = ["evet","elbette","tabi","tabii","tabii olur","tabi olur","neden olmasın","tabiki","tabii ki","tabiiki","istiyorum","olur"];
    $hayirVaryasyonlar = ["hayır","hayir","olmaz","istemiyorum","siktir git","çek git","siktir lan","siktir","defol","defol ulen","defol lan","kapat","kapat la","kapat lan","hayır tabiki","hayır tabiiki","hayır tabi","hayır lan"];
    if(in_array($evetHayir,$evetVaryasyonlar))
        return 'evet';
    elseif(in_array($evetHayir,$hayirVaryasyonlar))
        return  'hayır';
    else
        return '';
    
 
}

/*function hizmetbulAsync($transcribe, $salonid, $agi, $personeltranscribe, $personelEkle, $tarihSaat)
{
    // Async olarak çalıştırmak için background process başlat
    $command = sprintf(
        'php -r \'%s\' > /dev/null 2>&1 & echo $!',
        escapeshellarg(sprintf('
            $data = %s;
            $ch = curl_init("%s");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
                CURLOPT_TIMEOUT => 10
            ]);
            $response = curl_exec($ch);
            $resultFile = "/tmp/hizmetbul_" . uniqid() . ".json";
            file_put_contents($resultFile, $response);
            curl_close($ch);
        ', 
        var_export([
            "hizmet" => $transcribe,
            "salonid" => $salonid,
            "tarihSaat" => $tarihSaat,
            "personelAdi" => ($personeltranscribe != "" || $personelEkle) ? $personeltranscribe : null
        ], true),
        "https://app.randevumcepte.com.tr/api/v1/hizmetbul"
        ))
    );
    
    $pid = shell_exec($command);
    $agi->verbose("Background hizmetbul başlatıldı, PID: " . trim($pid));
    
    return trim($pid);
}*/
function randevuBul($salonid, $agi, $personelId,$salonHizmetId,$tarihSaat,$paketBilgi,$randevuId)
{
    $agi->verbose(date('H:i:s').' sunucu hizmet personel ve uygun tarih  araması yapılıyor');
    $url = "https://app.randevumcepte.com.tr/api/v1/randevuUygunlukKontrolEt"; // Laravel API URL'si
    $data = ["salonHizmetId" => $salonHizmetId, "salonId" => $salonid, "tarihSaat" => $tarihSaat,"personelId"=>$personelId,'paketBilgi'=>$paketBilgi,'randevuId'=>$randevuId]; // Gönderilecek veri
    

    foreach ($data as $key => $value) {
        $agi->verbose("  $key => $value");
    }
  

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

function randevuGuncelle($randevuId,$randevuTarihi,$randevuSaati)
{
    $url = 'https://app.randevumcepte.com.tr/api/v1/randevuyuenyakintariheguncelle';  // Laravel API URL'si
    $data = ['randevuid' => $randevuId,'randevutarihi'=>$randevuTarihi,'randevusaati'=>$randevuSaati];  // Gönderilecek veri

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
function convertToBugunYarin($tarih)
{
        if(date('Y-m-d',strtotime($tarih))==date('Y-m-d'))
            return "Bugün";
        else if(date('Y-m-d',strtotime('+ 1 day',strtotime(date('Y-m-d')))) == date('Y-m-d',strtotime($tarih)))
            return "Yarın";
        else
            return $tarih;
 }  

function parseDateWithChrono($text) {
    global $agi;  // 🚨 ÇOK ÖNEMLİ!
    
    if (empty(trim($text))) {
        $agi->verbose("⚠️ Boş metin");
        return null;
    }
    
    $command = sprintf(
        'echo %s | node /var/lib/asterisk/agi-bin/tarihParser.js 2>&1',
        escapeshellarg($text)
    );
    
    $output = shell_exec($command);
    $agi->verbose("📤 Komut: " . $command);
    $agi->verbose("📥 Ham çıktı: " . trim($output));
    
    $lines = explode("\n", trim($output));
    $lastLine = end($lines);
    $agi->verbose("📌 Son satır: " . $lastLine);
    
    if ($lastLine !== 'NULL' && strtotime($lastLine)) {
        $agi->verbose("✅ Geçerli tarih: " . $lastLine);
        return $lastLine;
    }
    
    $agi->verbose("❌ Geçersiz tarih veya NULL");
    return null;
}

 


