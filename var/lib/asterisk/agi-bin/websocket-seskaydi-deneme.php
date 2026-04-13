#!/usr/bin/php -q
<?php
error_reporting(E_ALL);
set_time_limit(0);
ob_implicit_flush();

require('phpagi.php');

$agi = new AGI();
$wsServer = "127.0.0.1"; // WebSocket sunucu adresi
$wsPort = 9092;

// WebSocket bağlantısını aç
$ws = fsockopen($wsServer, $wsPort, $errno, $errstr, 30);
if (!$ws) {
    $agi->verbose("WebSocket bağlantısı başarısız: $errstr ($errno)");
    exit(1);
}

// WebSocket handshake
$headers = "GET / HTTP/1.1\r\n"
    . "Host: $wsServer:$wsPort\r\n"
    . "Upgrade: websocket\r\n"
    . "Connection: Upgrade\r\n"
    . "Sec-WebSocket-Key: " . base64_encode(random_bytes(16)) . "\r\n"
    . "Sec-WebSocket-Version: 13\r\n\r\n";

fwrite($ws, $headers);
$response = fread($ws, 1500);
$agi->verbose("WebSocket handshake tamamlandı.");

// EAGI ile ses verisini al ve WebSocket'e gönder
$stdin = fopen("php://stdin", "r");

if (!$stdin) {
	$agi->verbose("STDIN  açılamadı!");
    error_log("STDIN açılamadı!");
    exit;
}

while (!feof($stdin)) {
    $audioData = fread($stdin, 4096);
    if (!$audioData) break;

    // WebSocket çerçevesi oluştur ve gönder
    $frame = chr(129) . chr(strlen($audioData)) . $audioData;
    fwrite($ws, $frame);
}

fclose($ws);
$agi->hangup();
?>

