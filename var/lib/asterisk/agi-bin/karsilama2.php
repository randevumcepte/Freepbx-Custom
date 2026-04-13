#!/usr/bin/php -q
<?php
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', '/var/log/asterisk/agi_error.log');
set_time_limit(30);
require('phpagi.php');
$agi = new AGI();
$agi->answer();
$agi->verbose("karsilama.php başladı");
$callerId = $agi->request['agi_callerid'];
$agi->verbose("Caller ID: ". $callerId);

// Daha fazla bilgi ekleyin
$trunk = $agi->request['agi_channel'];
$agi->verbose("Kanal: ". $trunk);

exit;
?>
