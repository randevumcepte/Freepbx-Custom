#!/usr/bin/php -q
<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'vendor/autoload.php';
require 'phpagi.php';
use WebSocket\Client;


$agi = new AGI();
$wsUrl = "ws://127.0.0.1:9090";

try {
    $client = new Client($wsUrl);
    $agi->verbose("WebSocket Bağlantısı Açıldı", 1);
} catch (Exception $e) {
    $agi->verbose("WebSocket Bağlantısı Hatası: " . $e->getMessage(), 1);
    exit(1);
}

// **Gerçek zamanlı sesi al ve WebSocket'e gönder**
$stdin = fopen("php://stdin", "r");
stream_set_blocking($stdin, false);

while (!feof($stdin)) {
	 $agi->verbose("Yanıtlama başladı", 1);
    $audioChunk = fread($stdin, 1024);
    if ($audioChunk !== false && strlen($audioChunk) > 0) {
	    try {
		    
            $client->send(json_encode([
                "event" => "audio_chunk",
                "audio" => base64_encode($audioChunk)
            ]));
        } catch (Exception $e) {
            $agi->verbose("WebSocket Gönderim Hatası: " . $e->getMessage(), 1);
            break;
        }
    }
}

try {
	 $agi->verbose("yanıtlama biti", 1);
    $client->send(json_encode(["event" => "end_audio"]));
    $response = $client->receive();
    $decodedResponse = json_decode($response, true);
    
    if (isset($decodedResponse["response"])) {
        $agi->verbose("Dialogflow Yanıtı: " . $decodedResponse["response"], 1);
        $agi->exec("Playback", "custom/" . $decodedResponse["response"]);
    }
} catch (Exception $e) {
    $agi->verbose("WebSocket Yanıt Alma Hatası: " . $e->getMessage(), 1);
}

$client->close();
$agi->verbose("WebSocket Bağlantısı Kapatıldı", 1);
?>
