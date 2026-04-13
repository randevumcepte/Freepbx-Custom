#!/usr/bin/php -q
<?php
// [polly-simple]
set_time_limit(30);
require('phpagi.php');
$agi = new AGI();
$agi->answer();

$text= $argv[1];

$id= uniqid();
// Arayan numarasını al
$agi->set_variable('UNIQUE_ID',$id);
$callerId = $agi->request['agi_callerid'];
$agi->verbose('Arayan numara: ' . $callerId, 1);

// Çağrının geldiği kanal bilgisi
$channel = $agi->request['agi_channel'];

// Kanal bilgisi üzerinden trunk bilgisini ayıklayabilirsiniz
$agi->verbose('Çağrının geldiği kanal: ' . $channel, 1);


$agi->verbose("okunacak text : ".$text);
shell_exec("node /opt/aws-nodejs/polly.js --mp3=/var/spool/asterisk/monitor/polly-$id.mp3 --text=".escapeshellarg(base64_decode($text))." --wav=/var/spool/asterisk/monitor/polly-$id");
//$agi->stream_file("/var/spool/asterisk/monitor/polly-$id");

/*$mp3file = "/tmp/polly-$id.mp3";
unlink($mp3file) or die("Couldn't delete file"); //deletes mp3 file
 
$wavfile = "/tmp/polly-$id.wav";
unlink($wavfile) or die("Couldn't delete file"); //deletes wav file
 */
exit();
?>
