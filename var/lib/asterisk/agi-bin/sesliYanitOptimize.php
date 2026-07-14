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

// Debug log dosyası — her çağrıda tek dosya (çağrı başı timestamp ile)
$DEBUG_LOG_DIR = '/var/log/sesliYanit';
if (!is_dir($DEBUG_LOG_DIR)) {
    @mkdir($DEBUG_LOG_DIR, 0777, true);
}
// /var/log yazılamazsa /tmp'a düş
if (!is_dir($DEBUG_LOG_DIR) || !is_writable($DEBUG_LOG_DIR)) {
    $DEBUG_LOG_DIR = '/tmp';
}
$DEBUG_LOG_FILE = $DEBUG_LOG_DIR . '/sesliYanit_debug_' . date('Ymd_His') . '_' . getmypid() . '.log';

// Dosyayı baştan oluştur (izin sorununu erken yakala)
$fpTest = @fopen($DEBUG_LOG_FILE, 'a');
if ($fpTest === false) {
    // /var/log yazılamıyorsa /tmp'a düş
    $DEBUG_LOG_FILE = '/tmp/sesliYanit_debug_' . date('Ymd_His') . '_' . getmypid() . '.log';
    $fpTest = @fopen($DEBUG_LOG_FILE, 'a');
}
if ($fpTest !== false) {
    @chmod($DEBUG_LOG_FILE, 0666);
    fclose($fpTest);
}

function debugLog($etiket, $deger = null) {
    global $DEBUG_LOG_FILE, $agi;
    $ts = date('Y-m-d H:i:s') . '.' . substr(microtime(), 2, 3);

    // Dosyaya tam içerik (pretty JSON)
    $dosyaSatir = "[{$ts}] {$etiket}";
    if ($deger !== null) {
        if (is_array($deger) || is_object($deger)) {
            $dosyaSatir .= ': ' . json_encode($deger, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } else {
            $dosyaSatir .= ': ' . $deger;
        }
    }
    $yazildi = @file_put_contents($DEBUG_LOG_FILE, $dosyaSatir . "\n", FILE_APPEND | LOCK_EX);
    if ($yazildi === false) {
        @file_put_contents('/tmp/sesliYanit_fallback.log', $dosyaSatir . "\n", FILE_APPEND);
    }

    // Verbose'a sadece etiket (değer dosyada — JSON virgülleri Asterisk AGI arg parser'ını bozuyor)
    if (isset($agi) && $agi) {
        @$agi->verbose("DBG " . $etiket);
    }
}
debugLog('=== YENI CAGRI BASLADI ===');
debugLog('PHP_VERSION', PHP_VERSION);
debugLog('WHOAMI', trim(shell_exec('whoami') ?? ''));
debugLog('LOG_DOSYASI', $DEBUG_LOG_FILE);
debugLog('ARGV', $GLOBALS['argv'] ?? []);

try{


        $agi->answer();
       
        
        $maxDeneme = 3; // Bir adimda 3 basarisiz denemeden sonra operatore aktar (sonsuz dongu engeli)
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


                $paketRandevuOnayEvetHayir = kayitAl($agi,$argv[2], "paketRandevusuOnayInput-".$argv[2]."-" . date('YmdHis'),6000,2);
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
            $hizmetDeneme = 0;
            while(true)
            {
                if(++$hizmetDeneme > $maxDeneme){
                    debugLog('HIZMET_MAX_DENEME_OPERATORE', $hizmetDeneme);
                    $agi->set_variable('HIZMETYOK','HAYIR'); // menuyu restart etme; dialplan failure -> operator-bagla
                    break;
                }
                $randevuAlmakIstediginizHimzetAnonsu = 'Randevu almak istediğiniz hizmeti söyler misiniz?';

                $sesDosyasi2 = anonsCal($agi,$randevuAlmakIstediginizHimzetAnonsu,'randevuAlmakIstediginizHimzetAnonsu',$argv[2],$sesDosyasi2);
              
                $agi->verbose('Ses Kaydını al '.date('H:i:s')."\n");
                $hizmetKayitDosyasi = kayitAl($agi,$argv[2], "hizmetSoylemeInput-".$argv[2]."-" . date('YmdHis'),9000,3);
                debugLog('HIZMET_KAYIT_DOSYASI', $hizmetKayitDosyasi);
                $sttSonuc = sesiMetneDonustur($hizmetKayitDosyasi, true);
                debugLog('HIZMET_STT_SONUC', $sttSonuc);

                if (is_array($sttSonuc)) {
                    $birincilMetin = isset($sttSonuc['metin']) ? $sttSonuc['metin'] : '';
                    $sttAdaylari = array_merge([$birincilMetin], isset($sttSonuc['alternatifler']) ? $sttSonuc['alternatifler'] : []);
                } else {
                    $birincilMetin = is_string($sttSonuc) ? $sttSonuc : '';
                    $sttAdaylari = [$birincilMetin];
                }

                // Tüm adayları fonetik alias ile normalize et ve tekilleştir
                $hizmetAdaylari = [];
                foreach ($sttAdaylari as $ham) {
                    $n = is_string($ham) ? fonetikNormalize($ham) : '';
                    if ($n !== '' && !in_array($n, $hizmetAdaylari, true)) {
                        $hizmetAdaylari[] = $n;
                    }
                }
                $soylenenHizmet = is_string($birincilMetin) ? fonetikNormalize($birincilMetin) : '';

                debugLog('HIZMET_BIRINCIL_NORMALIZE', $soylenenHizmet);
                debugLog('HIZMET_ADAYLARI', $hizmetAdaylari);
                debugLog('HIZMET_LISTESI', array_map(function($h){ return $h['hizmetAdi']; }, $hizmetler));

                $agi->verbose('Ses Kaydını tamamla '.date('H:i:s')."\n");
                $agi->verbose('Söylenen hizmet (birincil): '.$soylenenHizmet);
                $agi->verbose('STT adayları: '.implode(' | ', $hizmetAdaylari));

                if(count($hizmetAdaylari) > 0)
                {
                    $agi->verbose("ARGV3 RAW: ".$argv[3]);
                    $agi->verbose("JSON ERROR: ".json_last_error_msg());
                    $agi->verbose("HIZMET COUNT: ".count((array)$hizmetler));

                    $eslesenHizmet = null;
                    $eslesenSkor = 0;
                    $esikDeger = 60; // Minimum %60 benzerlik

                    foreach ($hizmetAdaylari as $aday) {
                        $adayNorm = turkceNormalize($aday);
                        if ($adayNorm === '') continue;

                        foreach ($hizmetler as $index => $hizmet) {
                            $hizmetAdiNorm = turkceNormalize($hizmet['hizmetAdi']);

                            // Birebir (substring) eşleşme: iki yönlü
                            $birebir = (mb_stripos($hizmetAdiNorm, $adayNorm) !== false)
                                    || (mb_stripos($adayNorm, $hizmetAdiNorm) !== false);
                            $skor = $birebir ? 100 : fuzzyHizmetEslestir($aday, $hizmet['hizmetAdi']);

                            $agi->verbose("Aday '{$aday}' vs '{$hizmet['hizmetAdi']}': %{$skor}" . ($birebir ? ' (birebir)' : ''));

                            if ($skor > $eslesenSkor && $skor >= $esikDeger) {
                                $eslesenSkor = $skor;
                                $eslesenHizmet = $hizmet;
                            }
                        }
                    }

                    if ($eslesenHizmet !== null) {
                        $agi->verbose("En iyi eşleşme: {$eslesenHizmet['hizmetAdi']} (%{$eslesenSkor})");
                        debugLog('HIZMET_ESLESME', ['hizmet' => $eslesenHizmet['hizmetAdi'], 'skor' => $eslesenSkor, 'id' => $eslesenHizmet['hizmetId']]);
                        $hizmetId = $eslesenHizmet['hizmetId'];
                        $sure = $eslesenHizmet['sureDk'];
                        $fiyat = $eslesenHizmet['fiyat'];
                        $hizmetPersonelleri = $eslesenHizmet['personeller'];
                    } else {
                        debugLog('HIZMET_ESLESME_YOK', ['en_yuksek_skor' => $eslesenSkor]);
                    }

                    if($hizmetId == null){
                        $hizmetiniVeremiyoruzAnonsu = $soylenenHizmet.' hizmetini maalesef veremiyoruz.';
                        $sesDosyasi14 = anonsCal($agi,$hizmetiniVeremiyoruzAnonsu,'hizmetiniVeremiyoruzAnonsu',$argv[2],$sesDosyasi14);
                        $agi->set_variable('HIZMETYOK','EVET');
                        continue;
                    }

                    // Onay adımı: yanlış eşleşmeleri burada yakala
                    $onaylandi = null;
                    $onayDeneme = 0;
                    while (true) {
                        if(++$onayDeneme > $maxDeneme){ $onaylandi = false; break; }
                        $onayMetni = $eslesenHizmet['hizmetAdi'].' hizmeti için devam ediyorum, onaylıyor musunuz?';
                        $sesDosyasiOnay = anonsCal($agi, $onayMetni, 'hizmetOnayAnonsu_'.date('YmdHis'), $argv[2], '');
                        $onayKayit = kayitAl($agi, $argv[2], "hizmetOnayInput-".$argv[2]."-".date('YmdHis'), 6000, 2);
                        $onayMetin = sesiMetneDonustur($onayKayit);
                        $onaySonuc = evetHayirVaryasyon($onayMetin);

                        if ($onaySonuc === 'evet') { $onaylandi = true; break; }
                        if ($onaySonuc === 'hayır') { $onaylandi = false; break; }

                        $sesDosyasiOnayAnlamadim = anonsCal($agi, 'Sizi anlayamadım', 'siziAnlayamadimAnonsuOnay', $argv[2], $sesDosyasiOnayAnlamadim ?? '');
                    }

                    if ($onaylandi) {
                        break;
                    }

                    // Onaylanmadı: seçimi sıfırla, hizmet sorusuna dön
                    $hizmetId = null;
                    $sure = null;
                    $fiyat = null;
                    $hizmetPersonelleri = [];
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
           
            $tarihDeneme = 0;
            while(true)
            {
                        if(++$tarihDeneme > $maxDeneme){
                            debugLog('TARIH_MAX_DENEME_OPERATORE', $tarihDeneme);
                            $agi->set_variable('HIZMETYOK','HAYIR'); // dialplan failure -> operator-bagla
                            break;
                        }
                        $randevuTarihSaatAnonsMetni = "Randevu almak istediğiniz tarih ve saati söyler misiniz?";
                        $sesDosyasi7 = anonsCal($agi, $randevuTarihSaatAnonsMetni, 'randevuTarihSaatBelirtmeAnonsu', $argv[2],$sesDosyasi7);
                        
                        $randevuTarihSaatKaydi = kayitAl($agi, $argv[2], "randevuTarihSaatInput_".$argv[2]."_".date('YmdHis'), 10000, 3);

                        // Debug: Kaydın alınıp alınmadığını kontrol et
                        $agi->verbose("Kayıt dosyası: " . $randevuTarihSaatKaydi);
                        debugLog('TARIH_KAYIT_DOSYASI', $randevuTarihSaatKaydi);

                        $tarihSttAlt = sesiMetneDonustur($randevuTarihSaatKaydi, true);
                        debugLog('TARIH_STT_TUM_ADAYLAR', $tarihSttAlt);
                        $randevuTarihSaat = is_array($tarihSttAlt) ? ($tarihSttAlt['metin'] ?? '') : $tarihSttAlt;

                        // Debug: Transkripsiyon çıktısını kontrol et
                        $agi->verbose("Transkripsiyon sonucu: " . $randevuTarihSaat);
                        debugLog('TARIH_STT_BIRINCIL', $randevuTarihSaat);

                        // Transkripsiyon boş mu kontrol et
                        if (empty(trim($randevuTarihSaat))) {
                            $agi->verbose("Transkripsiyon boş! Tekrar deneyin.");
                            continue; // While döngüsünün başına dön
                        }

                        //$tarihSaatParser = new DateParser();

                        // Debug: Parser'ın çalıştığını kontrol et
                        $agi->verbose("DateParser yüklendi, metni parse ediyor: " . $randevuTarihSaat);

                        try {
                            debugLog('TARIH_PARSER_INPUT', $randevuTarihSaat);
                            $parsedDateTime = parseDateWithChrono($randevuTarihSaat,$agi); //$tarihSaatParser->parseTurkishDate($randevuTarihSaat);
                            debugLog('TARIH_PARSER_OUTPUT', $parsedDateTime);
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
                                debugLog('RANDEVU_BUL_ISTEGI', [
                                    'salonId' => $argv[1],
                                    'personelId' => $personelId,
                                    'hizmetId' => $hizmetId,
                                    'tarihSaat' => date('Y-m-d H:i',strtotime($parsedDateTime)),
                                    'turkceGun' => $turkceGun
                                ]);

                                $uyguntarih = randevuBul(
                                    $argv[1],

                                    $agi,
                                    $personelId,
                                    $hizmetId,
                                    date('Y-m-d H:i',strtotime($parsedDateTime)),
                                    $gonderilecekPaket

                                );
                                debugLog('RANDEVU_BUL_YANIT', $uyguntarih);
                                if($uyguntarih['success'])
                                {
                                    // Backend uygun slotu dondurdu; exact ya da alternatif olabilir
                                    $uygunTarihSaatStr = isset($uyguntarih['tarihsaat']) && !empty($uyguntarih['tarihsaat'])
                                        ? $uyguntarih['tarihsaat']
                                        : date('Y-m-d H:i', strtotime($parsedDateTime));
                                    $uygunTs = strtotime($uygunTarihSaatStr);
                                    $uygunIngGun = date('l', $uygunTs);
                                    $uygunTurkceGun = isset($turkceGunler[$uygunIngGun]) ? $turkceGunler[$uygunIngGun] : $turkceGun;

                                    $alternatifOneri = isset($uyguntarih['alternatifOneri']) && $uyguntarih['alternatifOneri'];

                                    $dogalIfade = tarihSaatiDogalIfade($uygunTs, $uygunTurkceGun);

                                    if ($alternatifOneri) {
                                        $kontrolSaglamaAnonsu = 'Belirttiğiniz tarih ve saat için uygun randevu bulunamadı. En yakın uygun randevu '
                                            . $dogalIfade
                                            . '. Bu saatte randevunuzu oluşturmamı ister misiniz?';
                                        $anonsTuru = 'randevuAlternatifSaglamaAnonsu_' . date('YmdHis');
                                    } else {
                                        $kontrolSaglamaAnonsu = $dogalIfade
                                            . ' için randevunuz oluşturulacaktır. Onaylıyor musunuz?';
                                        $anonsTuru = 'randevuKontrolSaglamaAnonsu_' . date('YmdHis');
                                    }

                                    // Her iterasyonda fresh TTS uret (bos string ile)
                                    $sesDosyasi8 = anonsCal($agi, $kontrolSaglamaAnonsu, $anonsTuru, $argv[2], '');

                                    $randevuOnayEvetHayir = kayitAl($agi, $argv[2], "randevuOnayInput-".$argv[2]."-" . date('YmdHis'), 6000, 2);
                                    $randevuevetHayir = sesiMetneDonustur($randevuOnayEvetHayir);
                                    $randevuevetHayirSonuc = evetHayirVaryasyon($randevuevetHayir);
                                    debugLog('RANDEVU_ONAY_SONUC', ['alternatifOneri' => $alternatifOneri, 'sonuc' => $randevuevetHayirSonuc, 'uygunTarihSaat' => $uygunTarihSaatStr]);

                                    if ($randevuevetHayirSonuc == 'evet') {
                                        $secilenPersonel = $personelId;
                                        if ($secilenPersonel == null) {
                                            $secilenPersonel = $uyguntarih['personelid'];
                                        }

                                        // Oda ataması: backend odaid dondurduyse ilet (takvim_turu=3 ya da personel+oda durumu)
                                        $secilenOda = (isset($uyguntarih['odaid']) && $uyguntarih['odaid'] !== '' && $uyguntarih['odaid'] !== null)
                                            ? $uyguntarih['odaid']
                                            : null;

                                        $randevu = randevuolustur(
                                            $agi, $hizmetId, $secilenPersonel,
                                            date('Y-m-d', $uygunTs),
                                            date('H:i:s', $uygunTs),
                                            $argv[2], $argv[1], $sure, $fiyat, $secilenOda, $gonderilecekPaket
                                        );
                                        if ($randevu['success']) {
                                            $randevuOlustu = true;
                                        }
                                    }
                                    // onay 'hayir' veya anlasilamadi: while(true) dongusu tarih/saat adimina donecek
                                }
                                else  // Hicbir uygun slot bulunamadi
                                {
                                    $reddetMetni = (isset($uyguntarih['metin']) && !empty($uyguntarih['metin']))
                                        ? base64_decode($uyguntarih['metin'])
                                        : (tarihSaatiDogalIfade(strtotime($parsedDateTime), $turkceGun) . ' ve sonrası için uygun randevu bulamadık. Lütfen başka bir tarih ve saat söyleyin.');
                                    $sesDosyasi_9 = anonsCal($agi, $reddetMetni, 'randevuUygunsuzAnonsu_' . date('YmdHis'), $argv[2], '');
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
function kayitAl($agi,$userId,$dosyaAdi,$kayitSuresi,$sessizlikSn=3)
{
    $kayitDosyasi = "/var/spool/asterisk/monitor/".$dosyaAdi;
    // $kayitSuresi  = maksimum kayit suresi (MILISANIYE) — sert ust sinir.
    // $sessizlikSn  = kac saniye sessizlik olunca kayit OTOMATIK bitsin (Asterisk RECORD FILE
    //                 "s=" parametresi SANIYE cinsindendir). Eski deger 2000 idi => 2000 sn =>
    //                 sessizlik algilama fiilen kapaliydi, kayit hep tam sureye kadar surerdi
    //                 (konusma kesiliyor / gurultu giriyordu). Artik kullanici susunca kayit biter.
    $agi->record_file($kayitDosyasi, "wav", "", $kayitSuresi, 0, false, $sessizlikSn);
    return $kayitDosyasi.".wav";

}
function sesiMetneDonustur($sesDosyasi, $alternatifleriDondur = false)
{
    global $agi;

    $sesDosyasiYolu = $sesDosyasi;
    $transcribeKomutu = "node /var/lib/asterisk/agi-bin/transcribe2.js " .escapeshellarg($sesDosyasiYolu). " 2>/dev/null";
    $transcribeCiktisi = shell_exec($transcribeKomutu);
    $transcribeSonucu = json_decode($transcribeCiktisi, true);
    if($transcribeSonucu && $transcribeSonucu['success']) {
        $metin = strtolower($transcribeSonucu['transcription']);
        $guven = isset($transcribeSonucu['confidence']) ? $transcribeSonucu['confidence'] : 0;

        if ($agi) {
            $agi->verbose("STT sonuç: '{$metin}' (güven: " . round($guven * 100) . "%)");
        }

        // Düşük güven skorunda alternatif transkriptleri de logla
        if ($guven < 0.7 && isset($transcribeSonucu['alternatives']) && $agi) {
            foreach ($transcribeSonucu['alternatives'] as $alt) {
                $agi->verbose("  Alternatif: '{$alt['transcript']}' (" . round(($alt['confidence'] ?? 0) * 100) . "%)");
            }
        }

        if ($alternatifleriDondur && isset($transcribeSonucu['alternatives'])) {
            return [
                'metin' => $metin,
                'guven' => $guven,
                'alternatifler' => array_map(function($a) { return strtolower($a['transcript']); }, $transcribeSonucu['alternatives'])
            ];
        }

        return $metin;
    }
    else{
        if ($agi) {
            $agi->verbose("STT başarısız: " . ($transcribeSonucu['error'] ?? 'Bilinmeyen hata'));
        }
        return '';
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
    $evetHayir = trim(strtolower($evetHayir));

    // Tam eşleşme listesi
    $evetVaryasyonlar = ["evet","elbette","tabi","tabii","tabii olur","tabi olur","neden olmasın","tabiki","tabii ki","tabiiki","istiyorum","olur","peki","tamam","kabul","onaylıyorum","memnuniyetle","hay hay","he","hee","aynen","isterim"];
    $hayirVaryasyonlar = ["hayır","hayir","olmaz","istemiyorum","kapat","kapat la","kapat lan","hayır tabiki","hayır tabiiki","hayır tabi","hayır lan","yok","istemem","onaylamıyorum","iptal","vazgeçtim","gerek yok","red"];

    // 1. Tam eşleşme
    if(in_array($evetHayir,$evetVaryasyonlar))
        return 'evet';
    if(in_array($evetHayir,$hayirVaryasyonlar))
        return 'hayır';

    // 2. İçerik kontrolü (STT bazen fazladan kelime ekleyebilir)
    foreach ($evetVaryasyonlar as $varyasyon) {
        if (mb_stripos($evetHayir, $varyasyon) !== false) {
            return 'evet';
        }
    }
    foreach ($hayirVaryasyonlar as $varyasyon) {
        if (mb_stripos($evetHayir, $varyasyon) !== false) {
            return 'hayır';
        }
    }

    // 3. Fuzzy eşleşme - STT yakın ama hatalı kelime döndürdüğünde
    $enYakinEvet = 0;
    $enYakinHayir = 0;
    foreach ($evetVaryasyonlar as $varyasyon) {
        similar_text($evetHayir, $varyasyon, $yuzde);
        if ($yuzde > $enYakinEvet) $enYakinEvet = $yuzde;
    }
    foreach ($hayirVaryasyonlar as $varyasyon) {
        similar_text($evetHayir, $varyasyon, $yuzde);
        if ($yuzde > $enYakinHayir) $enYakinHayir = $yuzde;
    }

    // %70'den yüksek benzerlik varsa kabul et
    if ($enYakinEvet >= 70 && $enYakinEvet > $enYakinHayir)
        return 'evet';
    if ($enYakinHayir >= 70 && $enYakinHayir > $enYakinEvet)
        return 'hayır';

    return '';
}

/**
 * Fuzzy hizmet eşleştirme: STT çıktısı ile hizmet adı arasında benzerlik skoru hesaplar.
 * Hem similar_text hem de kelime bazlı eşleşmeyi birleştirir.
 */
function fuzzyHizmetEslestir($soylenen, $hizmetAdi)
{
    $soylenen = turkceNormalize($soylenen);
    $hizmetAdi = turkceNormalize($hizmetAdi);

    // 1. similar_text ile genel benzerlik
    similar_text($soylenen, $hizmetAdi, $yuzde1);

    // 2. Kelime bazlı eşleşme (STT "saç kesim" der, hizmet "saç kesimi" olabilir)
    $soylenenKelimeler = explode(' ', $soylenen);
    $hizmetKelimeler = explode(' ', $hizmetAdi);
    $eslesenKelime = 0;
    $toplamKelime = count($soylenenKelimeler);

    foreach ($soylenenKelimeler as $sk) {
        if (mb_strlen($sk) < 2) continue;
        foreach ($hizmetKelimeler as $hk) {
            // Kök eşleşmesi: kelimenin ilk %70'i aynıysa eşleşmiş say
            $minUzunluk = min(mb_strlen($sk), mb_strlen($hk));
            $kokUzunluk = max(2, (int)($minUzunluk * 0.7));
            if (mb_substr($sk, 0, $kokUzunluk) === mb_substr($hk, 0, $kokUzunluk)) {
                $eslesenKelime++;
                break;
            }
        }
    }
    $yuzde2 = $toplamKelime > 0 ? ($eslesenKelime / $toplamKelime) * 100 : 0;

    // 3. Levenshtein mesafesi (kısa kelimeler için etkili)
    $levMesafe = levenshtein($soylenen, $hizmetAdi);
    $maxUzunluk = max(mb_strlen($soylenen), mb_strlen($hizmetAdi));
    $yuzde3 = $maxUzunluk > 0 ? (1 - $levMesafe / $maxUzunluk) * 100 : 0;

    // Ağırlıklı ortalama: kelime bazlı > similar_text > levenshtein
    $sonSkor = ($yuzde2 * 0.45) + ($yuzde1 * 0.35) + ($yuzde3 * 0.20);

    return round($sonSkor);
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
    
    // TZ=Europe/Istanbul: node cocuk sureci sistem TZ'sini kullaniyordu; sunucu UTC ise
    // gece 00:00-03:00 arasi "yarin/bugun" bir gun erkene kayiyordu. Turkiye saatini zorla.
    $command = sprintf(
        'echo %s | TZ=Europe/Istanbul node /var/lib/asterisk/agi-bin/tarihParser.js 2>&1',
        escapeshellarg($text)
    );
    
    $output = shell_exec($command);
    $agi->verbose("📤 Komut: " . $command);
    $agi->verbose("📥 Ham çıktı: " . trim($output));
    if (function_exists('debugLog')) {
        debugLog('PARSER_INPUT_TEXT', $text);
        debugLog('PARSER_RAW_OUTPUT', trim($output));
    }

    $lines = explode("\n", trim($output));
    $lastLine = end($lines);
    $agi->verbose("📌 Son satır: " . $lastLine);

    if ($lastLine !== 'NULL' && strtotime($lastLine)) {
        $agi->verbose("✅ Geçerli tarih: " . $lastLine);
        if (function_exists('debugLog')) debugLog('PARSER_GECERLI_TARIH', $lastLine);
        return $lastLine;
    }

    $agi->verbose("❌ Geçersiz tarih veya NULL");
    if (function_exists('debugLog')) debugLog('PARSER_GECERSIZ', $lastLine);
    return null;
}

/**
 * Bir tarih/saat'i doğal Türkçe ile ifade eder:
 *   bugün → "bugün saat 15:30"
 *   yarın → "yarın saat 15:30"
 *   diğer → "29 Nisan Çarşamba günü saat 15:30"
 */
function tarihSaatiDogalIfade($ts, $turkceGun) {
    $bugunTs = strtotime(date('Y-m-d'));
    $hedefTs = strtotime(date('Y-m-d', $ts));
    $farkGun = (int) round(($hedefTs - $bugunTs) / 86400);
    $saat = date('H:i', $ts);

    if ($farkGun === 0) {
        return "bugün saat {$saat}";
    }
    if ($farkGun === 1) {
        return "yarın saat {$saat}";
    }

    $aylar = [
        1 => 'Ocak', 2 => 'Şubat', 3 => 'Mart', 4 => 'Nisan',
        5 => 'Mayıs', 6 => 'Haziran', 7 => 'Temmuz', 8 => 'Ağustos',
        9 => 'Eylül', 10 => 'Ekim', 11 => 'Kasım', 12 => 'Aralık',
    ];
    $gun = (int) date('d', $ts);
    $ayAdi = $aylar[(int) date('n', $ts)];
    return "{$gun} {$ayAdi} {$turkceGun} günü saat {$saat}";
}

function turkceNormalize($metin)
{
    if (!is_string($metin)) return '';
    $metin = mb_strtolower(trim($metin), 'UTF-8');
    $map = [
        'ç' => 'c', 'ğ' => 'g', 'ı' => 'i',
        'ö' => 'o', 'ş' => 's', 'ü' => 'u',
        'â' => 'a', 'î' => 'i', 'û' => 'u',
    ];
    $metin = strtr($metin, $map);
    $metin = preg_replace('/\s+/u', ' ', $metin);
    return trim($metin);
}

function fonetikNormalize($metin)
{
    $metin = trim(strtolower($metin));

    $aliases = [
        // HIFU
        'hayfu' => 'hifu', 'ay fu' => 'hifu', 'hi fu' => 'hifu',
        'ifu' => 'hifu', 'hif u' => 'hifu', 'hayfuu' => 'hifu',
        // LIFU
        'li fu' => 'lifu', 'layfu' => 'lifu', 'lif u' => 'lifu',
        'life' => 'lifu', 'lay fu' => 'lifu',
        // LIPOSONIX
        'lipo sonik' => 'liposonix', 'liposonik' => 'liposonix',
        'lipokomik' => 'liposonix',
        'lipo komik'=>'liposonix',
        'lipo soniks' => 'liposonix', 'lipo sonix' => 'liposonix',
        'liposoniks' => 'liposonix',
        // LIPOSUCTION
        'liposakşın' => 'liposuction', 'lipo sakşın' => 'liposuction',
        'liposükşın' => 'liposuction', 'lipo suction' => 'liposuction',
        'liposüction' => 'liposuction', 'lipo sakşin' => 'liposuction',
        // HYDRAFACIAL
        'hidrafacial' => 'hydrafacial', 'hidra facial' => 'hydrafacial',
        'hidra feyşıl' => 'hydrafacial', 'hayra fesıl' => 'hydrafacial',
        'hidrafeyşıl' => 'hydrafacial', 'hidra feşıl' => 'hydrafacial',
        // ARC SISTEM
        'ark sistem' => 'arc sistem', 'ar si sistem' => 'arc sistem',
        'arx sistem' => 'arc sistem', 'ars sistem' => 'arc sistem',
        // EDY SCULPT 360
        'edi skalpt' => 'edy sculpt', 'e d y sculpt' => 'edy sculpt',
        'edi sculpt' => 'edy sculpt', 'edi skalp' => 'edy sculpt',
        'ediy skalpt' => 'edy sculpt', 'edy skalpt' => 'edy sculpt',
        // G5
        'ci beş' => 'g5', 'ji beş' => 'g5', 'ji 5' => 'g5',
        'ci 5' => 'g5', 'g 5' => 'g5', 'ji five' => 'g5',
        // KARBON PEELING
        'karbon piling' => 'karbon peeling', 'karbon pilinğ' => 'karbon peeling',
        'karbon pilin' => 'karbon peeling',
        // POPOLIFT
        'popo lift' => 'popolift', 'popolif' => 'popolift',
        'popo lif' => 'popolift', 'popol ift' => 'popolift',
        // KIRPIK LIFTING
        'kirpik liftiğ' => 'kirpik lifting', 'kirpik liftin' => 'kirpik lifting',
        // DERMAPEN
        'derma pen' => 'dermapen',
        // PEELING genel
        'piling' => 'peeling', 'pilinğ' => 'peeling',
        // SCULPT genel
        'skalpt' => 'sculpt', 'skalp' => 'sculpt',
        // KOLAJEN IP
        'kolojen ip' => 'kolajen ip', 'kolajen i p' => 'kolajen ip',
    ];
    
    // 1. Tam eşleşme
    if (isset($aliases[$metin])) {
        return $aliases[$metin];
    }
    
    // 2. Kısmi eşleşme (uzun ifadeler için - uzundan kısaya sırala)
    $aliasKeys = array_keys($aliases);
    usort($aliasKeys, function($a, $b) { return mb_strlen($b) - mb_strlen($a); });
    
    foreach ($aliasKeys as $yanlis) {
        if (mb_stripos($metin, $yanlis) !== false) {
            $metin = str_ireplace($yanlis, $aliases[$yanlis], $metin);
        }
    }
    
    return $metin;
}

