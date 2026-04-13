#!/usr/bin/php -q
<?php
require_once('phpagi.php');
$agi = new AGI();

$did = $argv[1] ?? '';
$caller = $argv[2] ?? '';

$agi->verbose("Gelen DID: $did, Arayan: $caller");

// DB bağlantısı
$mysqli = new mysqli("localhost", "freepbxuser", "a4a8bbc17f1844dafa72c1c97041f8f4", "asterisk");
if ($mysqli->connect_error) {
    $agi->verbose("DB bağlantı hatası: " . $mysqli->connect_error);
    exit(1);
}

// Context sorgusu
$stmt = $mysqli->prepare("SELECT context_name FROM did_contexts WHERE did = ?");
$stmt->bind_param("s", $did);
$stmt->execute();
$stmt->bind_result($context);
$stmt->fetch();
$stmt->close();

// AGI değişkenlerini setle
$agi->exec("Set", "ARANAN_TEL=$did");
$agi->exec("Set", "__FROM_DID=$did");

// Özel durum
if ($did == '902323691020') {

    $agi->exec("Set", "operatorKanali=10006");
    //$karsilamaMetni = hariciKarsilama($agi,$caller,$did);
    //$agi->exec("Set",'karsilamaMetni=$karsilamaMetni');

    $agi->exec("Set", "ROUTED_CONTEXT=operator-bagla-harici");
} else if ($context) {
    $agi->exec("Set", "ROUTED_CONTEXT=$context");
} else {
    $agi->exec("Set", "ROUTED_CONTEXT=from-trunk");
}

$mysqli->close();
function hariciKarsilama($agi, $callerid, $channel) {
    $url = 'https://app.randevumcepte.com.tr/api/v1/santralkarsilamametni';
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
    return  $response;
}


exit;
?>
