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

if (!function_exists('seslendirmeMetni')) {
    /** BUYUK harf kelimeleri/markalari bas-harfi-buyuk forma cevir (TTS harf harf okumasin).
     *  sesli-asistan.php seslendirmeMetni() + gttscache.php + tts.js capsFix ile AYNI mantik. */
    function seslendirmeMetni($s)
    {
        $trKucuk = function ($x) { return mb_strtolower(str_replace(['I', 'İ'], ['ı', 'i'], $x), 'UTF-8'); };
        return preg_replace_callback('/[A-ZÇĞİÖŞÜ]{2,}/u', function ($m) use ($trKucuk) {
            $w = $m[0];
            return mb_substr($w, 0, 1, 'UTF-8') . $trKucuk(mb_substr($w, 1, null, 'UTF-8'));
        }, (string) $s);
    }
}

if (!function_exists('googleAnonsCal')) {
    /**
     * Cache'li Google erkek (tr-TR-Wavenet-E) seslendirme + AGI icinden inline cal.
     * gttscache.php ile AYNI mantik (capsFix + /api/v1/seslendir + md5 cache + Polly
     * fallback); tek fark dialplan Background yerine dogrudan stream_file ile calar.
     * Sabit cumleler (orn. "Sizi anlayamadim...") bir kez uretilir, sonra cache'ten.
     */
    function googleAnonsCal($agi, $metin)
    {
        $ses  = 'tr-TR-Wavenet-E';
        $text = seslendirmeMetni((string) $metin);
        $id   = md5($ses . '|' . $text);
        $base = '/var/spool/asterisk/monitor/gtts-' . $id;
        $wav  = $base . '.wav';
        $mp3  = $base . '.mp3';

        // Cache HIT -> dogrudan cal
        if (is_file($wav) && filesize($wav) > 0) { $agi->stream_file($base); return $base; }

        if ($text !== '') {
            $ch = curl_init('https://app.randevumcepte.com.tr/api/v1/seslendir');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['metin' => $text, 'ses' => $ses]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            $resp = curl_exec($ch);
            curl_close($ch);
            $j = json_decode($resp, true);
            if (is_array($j) && !empty($j['basarili']) && !empty($j['url'])) {
                $dl = curl_init($j['url']);
                curl_setopt($dl, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($dl, CURLOPT_TIMEOUT, 15);
                $data = curl_exec($dl);
                curl_close($dl);
                if ($data !== false && $data !== '') {
                    @file_put_contents($mp3, $data);
                    shell_exec('ffmpeg -y -i ' . escapeshellarg($mp3) . ' -ar 8000 -ac 1 -acodec pcm_s16le ' . escapeshellarg($wav) . ' 2>/dev/null');
                    @unlink($mp3);
                }
            }
        }

        // Google basarisizsa Polly fallback (cagride sessizlik olmasin)
        if (!is_file($wav) || filesize($wav) === 0) {
            shell_exec('node /opt/aws-nodejs/polly.js --mp3=' . escapeshellarg($mp3) . ' --text=' . escapeshellarg($text) . ' --wav=' . escapeshellarg($base));
        }
        $agi->stream_file($base);
        return $base;
    }
}
