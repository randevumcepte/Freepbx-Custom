#!/usr/bin/php -q
<?php
 

ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', '/var/spool/asterisk/monitor/agi_error.log');
set_time_limit(30);
require('phpagi.php');
$agi = new AGI();
$agi->answer();
//$agi->exec('Ringing');
$id= uniqid();
$agi->set_variable('UNIQUE_ID_ALACAK',$id);
 
$alacak_varmi = alacakbul($agi,$argv[1],$argv[2]);
 
$agi->verbose('Karşılama metni : '.$alacak_varmi['message']);

if($alacak_varmi['success']){
    $calinacak=$alacak_varmi['message'];
    $agi->set_variable('ALACAK_VAR',$alacak_varmi['borc_var']); 
    shell_exec("node /opt/aws-nodejs/polly.js --mp3=/var/spool/asterisk/monitor/polly-$id.mp3 --text='$calinacak' --wav=/var/spool/asterisk/monitor/polly-$id");
}
else {
    shell_exec("node /opt/aws-nodejs/polly.js --mp3=/var/spool/asterisk/monitor/polly-$id.mp3 --text='Sistemde geçici bir arıza mevcut. Sizi operatöre aktarıyorum.'");
}

//$agi->exec('StopRinging');
 

//$agi->exec('Read', "input,/var/spool/asterisk/monitor/polly-$id,1,,1,10");

//$input = $agi->get_variable('input');
//$agi->verbose('Girdi: '.$input);

function alacakbul($agi, $salonId, $userId) {
    $url = 'https://app.randevumcepte.com.tr/api/v1/alacakVarmi';
    $data = ['salon_id' => $salonId, 'user_id' => $userId];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        $agi->verbose('Curl hatası var');
    }
    curl_close($ch);
    return json_decode($response, true);
}
?>
;
