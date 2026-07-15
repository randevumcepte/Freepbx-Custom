#!/usr/bin/php
<?php
require "phpagi.php";
require_once __DIR__ . "/sesliYanitOrtak.php"; // evetHayirVaryasyon (fuzzy: birebir + substring + %70 similar_text)

$agi = new AGI();
$maxDeneme = 3; // Sonsuz dongu yerine 3 deneme; sonra kibar varsayilan.

for ($deneme = 1; $deneme <= $maxDeneme; $deneme++) {
    $evetHayirRecordId = uniqid();
    $evetHayirRecordFile = "/var/spool/asterisk/monitor/kampanyaEvetHayirInput" . $evetHayirRecordId;

    // Kayit: sessizlik 2sn, MAKS 5sn. (Eskiden maxduration BOSTU -> sessiz/gurultulu hatta takilabiliyordu.)
    $agi->exec("Record", "$evetHayirRecordFile.wav,2,5,q");
    $evetHayirRecordedFile = $evetHayirRecordFile . ".wav";

    // Transcribe
    $evetHayirAl = "node /var/lib/asterisk/agi-bin/transcribe2.js " . escapeshellarg($evetHayirRecordedFile);
    $evetHayirSonuc = shell_exec($evetHayirAl);
    $result = json_decode($evetHayirSonuc, true);

    // STT basarisiz/bos olabilir -> guard (eskiden $result['transcription'] undefined index veriyordu).
    $ham = (is_array($result) && isset($result['transcription'])) ? $result['transcription'] : '';
    $cevap = trim(strtolower(str_replace(['?', '!', '.', ','], '', $ham)));
    $agi->verbose("Evet/Hayir ham cevap: '" . $cevap . "' (deneme $deneme/$maxDeneme)");

    // Fuzzy eslestirme (birebir yerine): "he evet", "olur tabii", "tabi ki" gibileri de yakalar.
    $sonuc = evetHayirVaryasyon($cevap);
    if ($sonuc === 'evet') { $agi->set_variable('EVETHAYIR', 'evet'); exit(0); }
    if ($sonuc === 'hayır') { $agi->set_variable('EVETHAYIR', 'hayır'); exit(0); }

    // Taninmadi: son deneme degilse uyar ve tekrar sor.
    if ($deneme < $maxDeneme) {
        $anlayamadimId = uniqid();
        shell_exec(
            "node /opt/aws-nodejs/polly.js --mp3=/var/spool/asterisk/monitor/anlayamadim-$anlayamadimId.mp3 --text=" .
            escapeshellarg('Sizi anlayamadım. Evet veya hayır diyebilirsiniz.') .
            " --wav=/var/spool/asterisk/monitor/polly-$anlayamadimId"
        );
        $agi->stream_file("/var/spool/asterisk/monitor/polly-$anlayamadimId");
    }
}

// 3 denemede net cevap yok -> kibar varsayilan HAYIR (kampanyada musteriyi zorlamayiz; sonsuz dongu YOK).
$agi->verbose("Net evet/hayir alinamadi (3 deneme), varsayilan: hayir");
$agi->set_variable('EVETHAYIR', 'hayır');
exit(0);
?>
