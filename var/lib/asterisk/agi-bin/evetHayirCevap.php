#!/usr/bin/php
<?php
require "phpagi.php";

$agi = new AGI();

// Geçerli yanıt öbekleri
$evetCevaplar = [
    "evet","elbette","tabi","tabii","tabii olur","tabi olur",
    "neden olmasın","tabiki","tabii ki","tabiiki","istiyorum","olur"
    ,"isterim","çok isterim","çok istiyorum","tabii isterim"
];
$hayirCevaplar = [
    "hayır","hayir","olmaz","istemiyorum","siktir git","çek git",
    "siktir lan","siktir","defol","defol lan","kapat","kapat lan",
    "hayır tabiki","hayır lan"
];

while (true) {
    $evetHayirRecordId = uniqid();
    $evetHayirRecordFile = "/var/spool/asterisk/monitor/kampanyaEvetHayirInput".$evetHayirRecordId;
   
    // Kayıt al
    //$agi->record_file($evetHayirRecordFile, "wav", "", 2000, 0, false, 2);
    //
    $agi->exec("Record", "$evetHayirRecordFile.wav,2,,q");
    $evetHayirRecordedFile = $evetHayirRecordFile . ".wav";
    // Transcribe
    $evetHayirAl = "node /var/lib/asterisk/agi-bin/transcribe2.js " . escapeshellarg($evetHayirRecordedFile);
    $evetHayirSonuc = shell_exec($evetHayirAl);
    $result = json_decode($evetHayirSonuc, true);

    $cevap = trim(strtolower(str_replace(['?','!','.',','],['','','',''],$result['transcription'])));
    $agi->verbose("Evet Hayır dönen cevap: ".$cevap);

    // Geçerli cevap kontrolü
    if (in_array($cevap, $evetCevaplar)) {
        $agi->set_variable('EVETHAYIR','evet');
        break;
    } elseif (in_array($cevap, $hayirCevaplar)) {
        $agi->set_variable('EVETHAYIR','hayır');
        break;
    } else {
        // Tanınmayan cevap, kullanıcıya uyarı çal
        $anlayamadimId = uniqid();
	 shell_exec(
                        "node /opt/aws-nodejs/polly.js --mp3=/var/spool/asterisk/monitor/anlayamadim-$anlayamadimId.mp3 --text=".
                            escapeshellarg('Siz anlayamadım. Evet veya hayır diyebilirsiniz.') .
                            " --wav=/var/spool/asterisk/monitor/polly-$anlayamadimId"
                    );
                    $agi->stream_file("/var/spool/asterisk/monitor/polly-$anlayamadimId");
    }
}
?>

