<?php
/**
 * sesliYanitOrtak.php — Sesli akislar icin ORTAK yardimci fonksiyonlar.
 *
 * Bu fonksiyonlar sesliYanitOptimize.php icinden BIREBIR kopyalanmistir; boylece
 * outbound (kampanya) akislari da gelen-arama akisiyla ayni davranisi gosterir.
 * sesliYanitOptimize.php'ye DOKUNULMAZ (kendi kopyalarini kullanmaya devam eder);
 * bu dosya yalnizca yeni AGI'ler (orn. kampanyaGeriKazanimRandevu.php) tarafindan
 * require edilir.
 *
 * Baglantililik:
 *  - sesiMetneDonustur / parseDateWithChrono => `global $agi;` kullanir. Cagiran
 *    script mutlaka `$GLOBALS['agi'] = $agi;` set etmelidir.
 *  - transcribe2.js, /opt/aws-nodejs/polly.js, tarihParser.js ayni dizinde olmali.
 */

if (!function_exists('kayitAl')) {
    function kayitAl($agi, $userId, $dosyaAdi, $kayitSuresi)
    {
        $kayitDosyasi = "/var/spool/asterisk/monitor/" . $dosyaAdi;
        $agi->record_file($kayitDosyasi, "wav", "", $kayitSuresi, 0, false, 2000);
        return $kayitDosyasi . ".wav";
    }
}

if (!function_exists('sesiMetneDonustur')) {
    function sesiMetneDonustur($sesDosyasi, $alternatifleriDondur = false)
    {
        global $agi;

        $sesDosyasiYolu = $sesDosyasi;
        $transcribeKomutu = "node /var/lib/asterisk/agi-bin/transcribe2.js " . escapeshellarg($sesDosyasiYolu) . " 2>/dev/null";
        $transcribeCiktisi = shell_exec($transcribeKomutu);
        $transcribeSonucu = json_decode($transcribeCiktisi, true);
        if ($transcribeSonucu && $transcribeSonucu['success']) {
            $metin = strtolower($transcribeSonucu['transcription']);
            $guven = isset($transcribeSonucu['confidence']) ? $transcribeSonucu['confidence'] : 0;

            if ($agi) {
                $agi->verbose("STT sonuc: '{$metin}' (guven: " . round($guven * 100) . "%)");
            }

            if ($guven < 0.7 && isset($transcribeSonucu['alternatives']) && $agi) {
                foreach ($transcribeSonucu['alternatives'] as $alt) {
                    $agi->verbose("  Alternatif: '{$alt['transcript']}' (" . round(($alt['confidence'] ?? 0) * 100) . "%)");
                }
            }

            if ($alternatifleriDondur && isset($transcribeSonucu['alternatives'])) {
                return [
                    'metin' => $metin,
                    'guven' => $guven,
                    'alternatifler' => array_map(function ($a) { return strtolower($a['transcript']); }, $transcribeSonucu['alternatives'])
                ];
            }

            return $metin;
        } else {
            if ($agi) {
                $agi->verbose("STT basarisiz: " . ($transcribeSonucu['error'] ?? 'Bilinmeyen hata'));
            }
            return '';
        }
    }
}

if (!function_exists('anonsCal')) {
    function anonsCal($agi, $metin, $anonsTuru, $userId, $eskiSesDosyasi)
    {
        $fileName = "/var/spool/asterisk/monitor/" . $anonsTuru . "_" . $userId . "_" . date('YmdHis');
        if ($eskiSesDosyasi != '') {
            $fileName = $eskiSesDosyasi;
            $agi->verbose('Stt yeniden calismayacak ses kaydi var zaten');
        } else {
            shell_exec("node /opt/aws-nodejs/polly.js --mp3=" . $fileName . ".mp3 --text=" . escapeshellarg($metin) . " --wav=" . $fileName);
        }
        $agi->stream_file($fileName);
        return $fileName;
    }
}

if (!function_exists('evetHayirVaryasyon')) {
    function evetHayirVaryasyon($evetHayir)
    {
        $evetHayir = trim(strtolower($evetHayir));

        $evetVaryasyonlar = ["evet", "elbette", "tabi", "tabii", "tabii olur", "tabi olur", "neden olmasin", "tabiki", "tabii ki", "tabiiki", "istiyorum", "olur", "peki", "tamam", "kabul", "onayliyorum", "memnuniyetle", "hay hay", "he", "hee", "aynen", "isterim"];
        $hayirVaryasyonlar = ["hayir", "hayır", "olmaz", "istemiyorum", "kapat", "kapat la", "kapat lan", "hayir tabiki", "hayır tabiiki", "hayir tabi", "hayir lan", "yok", "istemem", "onaylamiyorum", "iptal", "vazgectim", "gerek yok", "red"];

        if (in_array($evetHayir, $evetVaryasyonlar))
            return 'evet';
        if (in_array($evetHayir, $hayirVaryasyonlar))
            return 'hayır';

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

        if ($enYakinEvet >= 70 && $enYakinEvet > $enYakinHayir)
            return 'evet';
        if ($enYakinHayir >= 70 && $enYakinHayir > $enYakinEvet)
            return 'hayır';

        return '';
    }
}

if (!function_exists('parseDateWithChrono')) {
    function parseDateWithChrono($text, $agi)
    {
        global $agi;

        if (empty(trim($text))) {
            $agi->verbose("Bos metin");
            return null;
        }

        $command = sprintf(
            'echo %s | node /var/lib/asterisk/agi-bin/tarihParser.js 2>&1',
            escapeshellarg($text)
        );

        $output = shell_exec($command);
        $agi->verbose("Komut: " . $command);
        $agi->verbose("Ham cikti: " . trim($output));

        $lines = explode("\n", trim($output));
        $lastLine = end($lines);
        $agi->verbose("Son satir: " . $lastLine);

        if ($lastLine !== 'NULL' && strtotime($lastLine)) {
            $agi->verbose("Gecerli tarih: " . $lastLine);
            return $lastLine;
        }

        $agi->verbose("Gecersiz tarih veya NULL");
        return null;
    }
}
