#!/usr/bin/php -q
<?php
// [polly-simple]
//

ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/var/spool/asterisk/monitor/agi_error.log');
set_time_limit(30);
require('phpagi.php');
$agi = new AGI();
$agi->answer();
$agi->exec('Ringing');
$id= uniqid();
$agi->set_variable('UNIQUE_ID',$id);
$callerId = $agi->request['agi_callerid'];
$agi->verbose('Arayan numara: '. $callerId);
//$trunk = $agi->request['agi_channel'];
$trunk =$agi->request['agi_dnid'];
$karsilama_metni = karsilamametninical($agi,$callerId,$trunk);
error_log($karsilama_metni['karsilama_metni']);
$agi->verbose('Karşılama metni : '.$karsilama_metni['karsilama_metni']);

if($karsilama_metni['success']){
    $agi->set_variable('AGI_STATUS','success');
}
else {
$agi->set_variable('AGI_STATUS','failure');

}

$agi->exec('StopRinging');
$agi->set_variable('KARSILAMA_METNI',$karsilama_metni['karsilama_metni']);
$agi->set_variable('ANA_MENU',$karsilama_metni['ana_menu']);
$agi->set_variable('USER_ID',$karsilama_metni['user_id']);
$agi->set_variable('SALON_ID',$karsilama_metni['salon_id']);
$agi->set_variable('ISLETME_TRUNK',$karsilama_metni['trunk']);
$agi->set_variable('OPERATOR_KANALI',$karsilama_metni['operator_kanali']);
$hangupValue = $karsilama_metni['anonsCaldiktanSonraKapat'] ? 'true' : 'false';
$agi->verbose("HANG_UP değeri: " . $hangupValue);
$agi->set_variable('HANG_UP', $hangupValue);
$agi->set_variable('KATILIMCI_ID',$karsilama_metni['katilimciId']);
$agi->set_variable('KAMPANYA_ICERIK',$karsilama_metni['kampanyaIcerik']);
$agi->set_variable('YANITLA',$karsilama_metni['yanitla']);
$agi->verbose('KAMPANYA_ICERIK '.$karsilama_metni['kampanyaIcerik']);
//$agi->exec('Read', "input,/var/spool/asterisk/monitor/polly-$id,1,,1,10");

//$input = $agi->get_variable('input');
//$agi->verbose('Girdi: '.$input);

function karsilamametninical($agi, $callerid, $channel) {
    $url = 'https://app.eczella.com/api/v1/santralkarsilamametni';
    $data = ['callerid' => $callerid, 'channel' => $channel];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        $agi->verbose('curl hatası var');
    }
    curl_close($ch);
    return json_decode($response, true);
}
?>
;
