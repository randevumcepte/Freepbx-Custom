#!/usr/bin/php -q
<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', '/var/log/asterisk/php_errors.log');
require('phpagi.php');
$agi = new AGI();

// AGI log atıyor mu kontrol et
$agi->verbose("Randevu AGI ÇALIŞTI!");

// AGI parametrelerini al
$randevu_id = isset($argv[1]) ? $argv[1] : 'YOK';
$deger = isset($argv[2]) ? $argv[2] : 'YOK';

$agi->verbose("Randevu ID: " . $randevu_id);
$agi->verbose("Ekstra Değer: " . $deger);

file_put_contents("/var/log/asterisk/agi_debug.log", "AGI ÇALIŞTI: Randevu ID = " . $randevu_id . "\n", FILE_APPEND);

// Burada randevu bilgilerini işle...

?>
