#!/usr/bin/php -q
<?php
// [polly-simple]
set_time_limit(30);
require('phpagi.php');
$agi = new AGI();
$agi->answer();

$text= base64_encode($_GET['kampanyaMetin']);

$id= uniqid();

// Çağrının geldiği kanal bilgisi
$channel = $agi->request['agi_channel'];

// Kanal bilgisi üzerinden trunk bilgisini ayıklayabilirsiniz
$agi->verbose('Çağrının geldiği kanal: ' . $channel, 1);


$agi->verbose("okunacak text : ".$text);
shell_exec("node /opt/aws-nodejs/polly.js --mp3=/var/spool/asterisk/monitor/polly-$id.mp3 --text=".escapeshellarg(base64_decode($text))." --wav=/var/spool/asterisk/monitor/polly-$id");
return 'http://34.45.69.65/monitor/polly-'.$id'.wav';
//$agi->stream_file("/var/spool/asterisk/monitor/polly-$id");

/*$mp3file = "/tmp/polly-$id.mp3";
unlink($mp3file) or die("Couldn't delete file"); //deletes mp3 file
 
$wavfile = "/tmp/polly-$id.wav";
unlink($wavfile) or die("Couldn't delete file"); //deletes wav file
 */
 
?>
