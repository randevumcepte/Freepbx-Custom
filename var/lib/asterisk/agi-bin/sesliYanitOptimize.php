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
       
        
        $paketBilgisi = null;
        $gonderilecekPaket = null;
        $paket = $argv[5];
        $paketRandevusuOlusturulacak = false;
        $hizmetPersonelleri = array();
        $paketId = null;
        $hizmetlerDosya = isset($argv[3]) ? $argv[3] : '';
        $hizmetler = [];
        if (!empty($hizmetlerDosya) && file_exists($hizmetlerDosya)) {
            $content = file_get_contents($hizmetlerDosya);
            $hizmetler = json_decode($content, true);
            
            if (json_last_error() === JSON_ERROR_NONE) {
                $agi->verbose("✅ Hizmetler dosyadan okundu! Sayı: " . count($hizmetler));
                // Okuyunca dosyayı sil (temizlik)
                unlink($hizmetlerDosya);
                $agi->verbose("Hizmetler dosyası silindi: $hizmetlerDosya");
            } else {
                $agi->verbose("❌ JSON decode hatası: " . json_last_error_msg());
                $hizmetler = [];
            }
        } else {
            $agi->verbose("⚠️ Hizmetler dosyası bulunamadı veya boş: $hizmetlerDosya");
        }
           // DEBUG: Argümanları kontrol et
        file_put_contents('/tmp/agi_debug.log', 
            "Argüman sayısı: " . count($argv) . "\n" .
            "Argüman 3: " . ($argv[3] ?? 'NULL') . "\n" .
            "Base64 decode: " . (isset($argv[3]) ? base64_decode($argv[3]) : 'NULL') . "\n" .
            "Hizmet sayısı: " . count($hizmetler) . "\n" .
            "Hizmetler: " . print_r($hizmetler, true) . "\n\n",
            FILE_APPEND
        );
        $hizmetId = null;
        $personelId = null;    
        $sure = null;
        $fiyat = null;
        $randevuOlustu = false; 
        $personelSecimiGerekli = $argv[4];
        if(!empty($paket))
        {
            $paketBilgisi = json_decode(base64_decode($paket),true);

            $paketVarAnonsu = $paketBilgisi['paketAdi'].' paketinizden '.$paketBilgisi['bekleyenSeans']." adet seansınız mevcuttur. Paketiniz için randevu oluşturmak istiyor musunuz?";
            
            while(true)
            {

                $sesDosyasi1 = anonsCal($agi,$paketVarAnonsu,'paketVaranonsu',$argv[2],$sesDosyasi1);


                $paketRandevuOnayEvetHayir = kayitAl($agi,$argv[2], "paketRandevusuOnayInput-".$argv[2]."-" . date('YmdHis'),2000);
                $evetHayir = sesiMetneDonustur($paketRandevuOnayEvetHayir);
                $evetHayirSonuc = evetHayirVaryasyon($evetHayir);
                $agi->verbose('Evet hayır '.$evetHayir);

                if($evetHayirSonuc=='evet')
                {
                    $paketRandevusuOlusturulacak = true;
                    $paketId = $paketBilgisi['paketId'];
                    $sure= $paketBilgisi['paketSuresi'];
                    $gonderilecekPaket = $paketBilgisi;
                    break;
                }
                if($evetHayirSonuc == 'hayır')
                {
                    $paketRandevusuOlusturulacak = false;
                    break;
                }
                else
                {
                    $siziAnlayamadimAnonsu = 'Sizi anlayamadım';
                   $sesDosyasi13 = anonsCal($agi,$siziAnlayamadimAnonsu,'siziAnlayamadimAnonsu',$argv[2],$sesDosyasi13);
                }
            } 
        }
        
        if(!$paketRandevusuOlusturulacak)
        {
            while(true)
            {
                $randevuAlmakIstediginizHimzetAnonsu = 'Randevu almak istediğiniz hizmeti söyler misiniz?';

                $sesDosyasi2 = anonsCal($agi,$randevuAlmakIstediginizHimzetAnonsu,'randevuAlmakIstediginizHimzetAnonsu',$argv[2],$sesDosyasi2);
              
                $agi->verbose('Ses Kaydını al '.date('H:i:s')."\n");
                $hizmetKayitDosyasi = kayitAl($agi,$argv[2], "hizmetSoylemeInput-".$argv[2]."-" . date('YmdHis'),3000);
                $soylenenHizmet = sesiMetneDonustur($hizmetKayitDosyasi);
                 $agi->verbose('Ses Kaydını tamamla '.date('H:i:s')."\n");
                $agi->verbose('Söylenen hizmet '.$soylenenHizmet);

                if($soylenenHizmet != '')
                { 
                    $agi->verbose("ARGV3 RAW: ".$argv[3]); 
                    $agi->verbose("JSON ERROR: ".json_last_error_msg());
                    $agi->verbose("HIZMET COUNT: ".count((array)$hizmetler));

                    
                    
                    foreach ($hizmetler as $index => $hizmet) {
                        $agi->verbose("Hizmet {$index}: {$hizmet['hizmetAdi']}, Süre: {$hizmet['sureDk']}dk");
                        if (mb_stripos(strtolower($hizmet['hizmetAdi']), $soylenenHizmet) !== false){
                            $agi->verbose('hizmet bulundu şimdi sıra personel seçiminde');
                            $hizmetId = $hizmet['hizmetId'];
                            $sure = $hizmet['sureDk'];
                            $fiyat = $hizmet['fiyat'];
                            $hizmetPersonelleri = $hizmet['personeller'];
                            break;
                        }
                    }
                    if($hizmetId == null){
                        $hizmetiniVeremiyoruzAnonsu = $soylenenHizmet.' hizmetini maalesef veremiyoruz.';
                        $sesDosyasi14 = anonsCal($agi,$hizmetiniVeremiyoruzAnonsu,'hizmetiniVeremiyoruzAnonsu',$argv[2],$sesDosyasi14);
                        $agi->set_variable('HIZMETYOK','EVET');

                    }
                    else 
                        break;
                } 

            }

               
        }
        else{
            $hizmetPersonelleri = $paketBilgisi['personeller'];
        }
        

        if($paketRandevusuOlusturulacak || $hizmetId != null)
        {
            /*if($personelSecimiGerekli && count($hizmetPersonelleri)>0)
            {
                        while(true)
                        {

                            $anonsMetni = '';
                            if($paketRandevusuOlusturulacak)
                                $anonsMetni .= $paketBilgisi['paketAdi']. ' paket randevusunu almak istediğiniz personeli belirtmek istiyor musunuz?';
                            else 
                                $anonsMetni = $soylenenHizmet." hizmetini almak istediğiniz personeli belirtmek istiyor musunuz?";
                            $sesDosyasi3 = anonsCal($agi,$anonsMetni,"personelSecimiIstiyormusunuzAnonsu",$argv[2],$sesDosyasi3);
                            $evetHayirKayit = kayitAl($agi,$argv[2],'personelSecimiIstiyorumIstemiyorumInput_'.$argv[2]."_".date('YmdHis'),2000);
                            $evetHayir = sesiMetneDonustur($evetHayirKayit);
                            $evetHayirSonuc = evetHayirVaryasyon($evetHayir);
                            if($evetHayirSonuc == 'evet')
                            {
                                $personelSecmekIstiyor = true;
                                break;
                            }
                            elseif($evetHayirSonuc == 'hayır')
                                break;

                            else{
                                $siziAnlayamadimAnonsu = 'Sizi anlayamadım';
                                $sesDosyasi4 = anonsCal($agi,$siziAnlayamadimAnonsu,'siziAnlayamadimAnonsu',$argv[2],$sesDosyasi4);
                            }

                        }
                        if($personelSecmekIstiyor)
                        {
                           
                            while(true)
                            {
                                $personelMetin = '';
                                foreach($hizmetPersonelleri as $index=>$personel){

                                    $personelMetin .= $personel['personel_adi'].". ";
                                    
                                }
                                $personelSecimAnonsMetni = '';
                                if($paketRandevusuOlusturulacak)
                                    $personelSecimAnonsMetni = $paketBilgisi['paketAdi'].' paket randevusunu hangi personelimizden almak istersiniz. '.$personelMetin.' diyebilirsiniz';
                                else
                                    $personelSecimAnonsMetni = $soylenenHizmet .' hizmetini hangi personelimizden almak istersiniz. '.$personelMetin.' diyebilirsiniz';
                                $sesDosyasi5 =  anonsCal($agi,$personelSecimAnonsMetni,'personelSecimAnonsu',$argv[2],$sesDosyasi5);
                                $personelSoylemeKaydi = kayitAl($agi,$argv[2],"personelSecimiInput_".$argv[2]."_".date('YmdHis'),2000);
                                $soylenenPersonel = sesiMetneDonustur($personelSoylemeKaydi);
                                foreach($hizmetPersonelleri as $index=>$personel){
                                
                                     if (mb_stripos(strtolower($personel['personel_adi']), $soylenenPersonel) !== false){
                                        $personelId = $personel['id'];
                                        break;
                                    }
                                }
                                if($personelId != null)
                                    break;
                                else
                                {
                                    $siziAnlayamadimAnonsuPresonel = 'Sizi anlayamadım';
                                    $sesDosyasi6 = anonsCal($agi,$siziAnlayamadimAnonsuPresonel,'siziAnlayamadimAnonsuPresonel',$argv[2],$sesDosyasi6);
                                }
                            } 
                        }
                    
            }*/
           
            while(true)
            {
                        $randevuTarihSaatAnonsMetni = "Randevu almak istediğiniz tarih ve saati söyler misiniz?";
                        $sesDosyasi7 = anonsCal($agi, $randevuTarihSaatAnonsMetni, 'randevuTarihSaatBelirtmeAnonsu', $argv[2],$sesDosyasi7);
                        
                        $randevuTarihSaatKaydi = kayitAl($agi, $argv[2], "randevuTarihSaatInput_".$argv[2]."_".date('YmdHis'), 4000);
                        
                        // Debug: Kaydın alınıp alınmadığını kontrol et
                        $agi->verbose("Kayıt dosyası: " . $randevuTarihSaatKaydi);
                        
                        $randevuTarihSaat = sesiMetneDonustur($randevuTarihSaatKaydi);
                        
                        // Debug: Transkripsiyon çıktısını kontrol et
                        $agi->verbose("Transkripsiyon sonucu: " . $randevuTarihSaat);
                        
                        // Transkripsiyon boş mu kontrol et
                        if (empty(trim($randevuTarihSaat))) {
                            $agi->verbose("Transkripsiyon boş! Tekrar deneyin.");
                            continue; // While döngüsünün başına dön
                        }
                        
                        //$tarihSaatParser = new DateParser();
                        
                        // Debug: Parser'ın çalıştığını kontrol et
                        $agi->verbose("DateParser yüklendi, metni parse ediyor: " . $randevuTarihSaat);
                        
                        try {
                            $parsedDateTime = parseDateWithChrono($randevuTarihSaat,$agi); //$tarihSaatParser->parseTurkishDate($randevuTarihSaat);
                            /*$agi->verbose("Parse edilen ham tarih: " . $parsedDateTime);
                            
                            $validation = $tarihSaatParser->validateDateTime($parsedDateTime);
                            
                            // Debug: Validation sonucunu göster
                            $agi->verbose("Validation sonucu: " . print_r($validation, true));*/
                                


                            if ($parsedDateTime != null) {

                                $turkceGunler = [
                                    'Monday' => 'Pazartesi',
                                    'Tuesday' => 'Salı',
                                    'Wednesday' => 'Çarşamba',
                                    'Thursday' => 'Perşembe',
                                    'Friday' => 'Cuma',
                                    'Saturday' => 'Cumartesi',
                                    'Sunday' => 'Pazar'
                                ];
                                $timestamp = strtotime($parsedDateTime);
                                $ingilizceGun = date('l', $timestamp);
                                $turkceGun = $turkceGunler[$ingilizceGun];
                                

                                $agi->verbose('RANDEVU_TARIH : '. $parsedDateTime);
                            

                                $uyguntarih = randevuBul(
                                    $argv[1],
                                    
                                    $agi,
                                    $personelId,
                                    $hizmetId,
                                    date('Y-m-d H:i',strtotime($parsedDateTime)),
                                    $gonderilecekPaket

                                );
                                if($uyguntarih['success'])
                                {


                                    $kontrolSaglamaAnonsu = date('Y-m-d',strtotime($parsedDateTime)).' '.$turkceGun.' günü saat '.date('H:i',strtotime($parsedDateTime)).' için randevunuz oluşturulacaktır. Onaylıyor musunuz?.';


                                    $sesDosyasi8 = anonsCal($agi,$kontrolSaglamaAnonsu,'randevuKontrolSaglamaAnonsu',$argv[2],$sesDosyasi8);
                                    

                                    $randevuOnayEvetHayir = kayitAl($agi,$argv[2],"randevuOnayInput-".$argv[2]."-" . date('YmdHis'),2000);
                                    $randevuevetHayir = sesiMetneDonustur($randevuOnayEvetHayir);
                                    $randevuevetHayirSonuc = evetHayirVaryasyon($randevuevetHayir);

                                    if($randevuevetHayirSonuc=='evet')
                                    {
                                        $secilenPersonel = $personelId;
                                        if($secilenPersonel == null)
                                            $secilenPersonel = $uyguntarih['personelid'];

                                        $randevu = randevuolustur($agi,$hizmetId,$secilenPersonel,date('Y-m-d',strtotime($parsedDateTime)),date('H:i:s',strtotime($parsedDateTime)),$argv[2],$argv[1],$sure,$fiyat,null,$gonderilecekPaket);
                                        if($randevu['success'])
                                            $randevuOlustu = true;
                                    }

                                   
                                    
                                    /*while(true)
                                    {
                                        $anonsAlternatif = date('Y-m-d',strtotime($validation['formatted'])).' '.$turkceGun.' günü saat '.date('H:i',strtotime($parsedDateTime)).' için uygun randevu bulunmaktadır. Randevunuzu oluşturmak istiyor musunuz?';
                                        /*$evetHayirKayit = kayitAl($agi,$argv[2],'randevuOnayliyorumOnaylamiyorumInput_'.$argv[2]."_".date('YmdHis'),2000);
                                        $evetHayir = sesiMetneDonustur($evetHayirKayit);
                                        $evetHayirSonuc = evetHayirVaryasyon($evetHayir);
                                        if($evetHayirSonuc == 'evet')
                                        {
                                             
                                            
                                        }
                                        elseif($evetHayirSonuc == 'hayır')
                                            break;
                                        else{
                                            $siziAnlayamadimAnonsu = 'Sizi anlayamadım';
                                            $sesDosyasi10 = anonsCal($agi,$siziAnlayamadimAnonsu,'siziAnlayamadimAnonsu',$argv[2],$sesDosyasi10);
                                        }

                                    }*/
                                    
                                    
                                }
                                else  // false dönmesine rağmen buraya girmiyor döngünün başına geçiyor 
                                {  
                                    $anonsAlternatif2 = date('Y-m-d',strtotime($parsedDateTime)).' '.$turkceGun.' günü saat '.date('H:i',strtotime($parsedDateTime)).' için maalesef randevu veremiyoruz. ';
                                    $sesDosyasi_9 = anonsCal($agi,/*base64_decode($uyguntarih['metin'])*/$anonsAlternatif2,'randevuUygunAnonsu',$argv[2],$sesDosyasi_9);

                                }


                                
                                // Kullanıcıya onay için sesli geri bildirim
                                /*$onayMetni = "Randevunuzu " . $validation['formatted'] . " için ayarlıyorum. Onaylıyor musunuz? Evet için 1, Hayır için 2 tuşlayın.";
                                anonsCal($agi, $onayMetni, 'randevuOnayAnonsu', $argv[2]);
                                
                                // Kullanıcı onayı al
                                $onay = $agi->get_data('beep', 3000, 1);
                                
                                if ($onay['result'] == '1') {
                                    $agi->verbose("Kullanıcı randevuyu onayladı.");
                                    $agi->set_variable('RANDEVU_TARIH', $parsedDateTime);
                                    $agi->set_variable('RANDEVU_TARIH_FORMATTED', $validation['formatted']);
                                    break;
                                } else {
                                    $agi->verbose("Kullanıcı randevuyu reddetti, tekrar sorulacak.");
                                    continue;
                                }*/
                            }
                            else
                            {
                               $sesDosyasi11 = anonsCal($agi, "Sizi anlayamadım.", 'tarihHataAnonsu', $argv[2],$sesDosyasi11);
                                /*$agi->verbose("Tarih valid değil: " . $validation['message']);
                                
                                // Kullanıcıya hata mesajını söyle
                                $hataMetni = $validation['message'];
                                if (isset($validation['suggestion'])) {
                                    $suggestionDate = new DateTime($validation['suggestion']);
                                    $suggestionFormatted = $suggestionDate->format('d.m.Y H:i');
                                    $hataMetni .= " " . $suggestionFormatted . " öneriyorum. Kabul ediyor musunuz?";
                                    
                                    anonsCal($agi, $hataMetni, 'tarihHataAnonsu', $argv[2]);
                                    
                                    $onay = $agi->get_data('beep', 3000, 1);
                                    if ($onay['result'] == '1') {
                                        $agi->verbose("Kullanıcı önerilen tarihi kabul etti.");
                                        $agi->set_variable('RANDEVU_TARIH', $validation['suggestion']);
                                        $agi->set_variable('RANDEVU_TARIH_FORMATTED', $suggestionFormatted);
                                        break;
                                    }
                                } else {
                                    anonsCal($agi, $hataMetni . " Lütfen başka bir tarih söyleyin.", 'tarihHataAnonsu', $argv[2]);
                                }*/
                            }

                            if($randevuOlustu){
                                 $agi->set_variable('RANDEVUOLUSTU','EVET');
                                 $agi->set_variable('HIZMETYOK','HAYIR');
                                break;
                            }
                        } catch (Exception $e) {
                            $agi->verbose("DateParser hatası: " . $e->getMessage());
                            $hataMetni = "Sizi anlayamadım.";
                           $sesDosyasi12 = anonsCal($agi, $hataMetni, 'tarihHataAnonsu', $argv[2],$sesDosyasi12);
                        }
                   
            } 
        }
        else
        {
            $siziAnlayamadimAnonsuHizmet = 'Sizi anlayamadım.';
            $sesDosyasi13 = anonsCal($agi,$siziAnlayamadimAnonsuHizmet,'siziAnlayamadimAnonsuHizmet',$argv[3],$sesDosyasi13);
             
        }

        
    
    
    



}
catch (Exception $e) {
    $agi->verbose("Hata: " . $e->getMessage());
    $agi->hangup();
}

 // sesliYanitOptimize.php içinde
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


function randevuBul($salonid, $agi, $personelId,$salonHizmetId,$tarihSaat,$paketBilgi)
{
    $agi->verbose(date('H:i:s').' sunucu hizmet personel ve uygun tarih  araması yapılıyor');
    $url = "https://app.randevumcepte.com.tr/api/v1/randevuUygunlukKontrolEt"; // Laravel API URL'si
    $data = ["salonHizmetId" => $salonHizmetId, "salonId" => $salonid, "tarihSaat" => $tarihSaat,"personelId"=>$personelId,'paketBilgi'=>$paketBilgi]; // Gönderilecek veri
    

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

function randevuolustur($agi,$hizmetid,$personelid,$tarih,$saat,$userId,$salonId,$sure,$fiyat,$odaid,$paketBilgi)
{
    $url = 'https://app.randevumcepte.com.tr/api/v1/santralRandevuEkle';

   //$agi->set_variable('hizmet_id',$hizmetid);
    $hizmetlerArr = array();
    if($paketBilgi != null)
    {
        foreach($paketBilgi['hizmetler'] as $pHizmet)
        {
            array_push($hizmetlerArr, $pHizmet['hizmet_id']);
        }

    }
    else
        array_push($hizmetlerArr,$hizmetid);
    $data = ['easistan'=>1,'olusturan_user_id'=>$userId,'salon_id'=>$salonId,'user_id'=>$userId,'durum'=>0,'hizmetler' => $hizmetlerArr, 'randevuPersonelleri' => [$personelid],'tarih'=>$tarih,'saat'=>$saat,'hizmetSuresi'=>[$sure],'hizmetFiyati'=>[$fiyat],'randevuOdalari'=>[$odaid],'paketBilgi'=>$paketBilgi];

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


function getHizmetbulResult($pid)
{
    // Result dosyalarını ara
    $pattern = "/tmp/hizmetbul_*.json";
    $files = glob($pattern);
    
    if (empty($files)) {
        return null;
    }
    
    // En son oluşturulan dosyayı al
    $latestFile = array_reduce($files, function($a, $b) {
        return filemtime($a) > filemtime($b) ? $a : $b;
    });
    
    $content = file_get_contents($latestFile);
    $data = json_decode($content, true);
    
    // Temizlik
    unlink($latestFile);
    
    return $data;
}
function uygunlukArarkenAnonsCal($agi, $metin, $anonsTuru, $userId, $hizmetData)
{
    $fileName = "/var/spool/asterisk/monitor/".$anonsTuru."_".$userId."_".date('YmdHis');
    
    // 1. HİZMETBUL API'sini BACKGROUND'da başlat (anons ÖNCESİ)
    $agi->verbose("Background hizmetbul API çağrısı başlatılıyor...");
    $hizmetbulPid = hizmetbulAsync(
        $hizmetData['transcribe'],
        $hizmetData['salonid'],
        $agi,
        $hizmetData['personeltranscribe'] ?? '',
        $hizmetData['personelEkle'] ?? false,
        $hizmetData['tarihSaat'] ?? ''
    );
    
    // 2. Polly anonsunu BACKGROUND'da oluştur
    $pollyCmd = "node /opt/aws-nodejs/polly.js --mp3=".$fileName.".mp3 " .
                "--text=" . escapeshellarg($metin) . " --wav=".$fileName . 
                " > /dev/null 2>&1 & echo $!";
    
    $pollyPid = shell_exec($pollyCmd);
    $agi->verbose("Polly başlatıldı, PID: " . trim($pollyPid));
    
    // 3. ANONS OLUŞURKEN yapılacak diğer işler
    $startTime = time();
    $maxWait = 12; // maksimum 12 saniye
    
    while ((time() - $startTime) < $maxWait) {
        // A) Polly dosyası hazır mı kontrol et
        if (file_exists($fileName)) {
            $agi->verbose("Anons dosyası hazır!");
            break;
        }
        
        // B) Hizmetbul API sonucu geldi mi kontrol et
        $hizmetResult = getHizmetbulResult($hizmetbulPid);
        if ($hizmetResult) {
            $agi->verbose("Hizmetbul API sonucu geldi!");
            $agi->set_variable('HIZMETBUL_RESULT', json_encode($hizmetResult));
            
            // Özel işlemler yapabilirsiniz
            if (isset($hizmetResult['success']) && $hizmetResult['success']) {
                $agi->set_variable('HIZMET_ID', $hizmetResult['hizmet_id'] ?? '');
                $agi->set_variable('PERSONEL_ID', $hizmetResult['personel_id'] ?? '');
                $agi->set_variable('UYGUN_SAATLER', json_encode($hizmetResult['uygun_saatler'] ?? []));
            }
        }
        
        // C) Diğer background işleri yap
        // Örneğin: Kullanıcı geçmişini kontrol et, cache temizle, vs.
        if ((time() - $startTime) > 3) { // 3. saniyeden sonra
            cleanupOldFiles($agi);
        }
        
        usleep(300000); // 300ms bekle
    }
    
    // 4. Anonsu çal
    if (file_exists($fileName)) {
        $agi->verbose("Anons çalınıyor...");
        $agi->stream_file($fileName);
        
        // Anonstan sonra API sonucunu tekrar kontrol et
        if (!isset($hizmetResult)) {
            $hizmetResult = getHizmetbulResult($hizmetbulPid);
            if ($hizmetResult) {
                $agi->set_variable('HIZMETBUL_RESULT_FINAL', json_encode($hizmetResult));
            }
        }
        
        return [
            'anons_success' => true,
            'hizmetbul_result' => $hizmetResult ?? null
        ];
    } else {
        $agi->verbose("Anons zaman aşımı, fallback TTS");
        $agi->exec('SayAlpha', $metin);
        return ['anons_success' => false];
    }
}

function parseDateWithChrono($text,$agi) {
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

