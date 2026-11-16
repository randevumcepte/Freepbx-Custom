#!/usr/bin/php
<?php
/**
 * Mobil softphone iOS VoIP push (AGI).
 *
 * [softphone-push] context'inden, dahili Dial edilmeden ONCE cagrilir:
 *   AGI(softphoneVoipPush.php,${EXTEN})
 *
 *  1. Laravel'den dahilinin iOS VoIP token'larini alir (santral/hedefler).
 *  2. Token yoksa hemen cikar -> dahili normal Dial edilir (gecikme yok).
 *  3. Varsa softphoneVoipPush.js ile APNs'e VoIP push yollar (CallKit acilir).
 *  4. Uygulama santrale register olup "hazir" (ya da "red") diyene kadar
 *     en fazla SP_BEKLE_SN bekler (santral/hazir-mi).
 *  5. Kanal degiskenleri: SP_TEK = SIP|PJSIP, SP_SONUC = yok|hazir|red|zamanasimi|hata
 *
 * Arayan hattan duserse Asterisk AGI'ye SIGHUP yollar, script olur (bekleme biter).
 * Anahtar: /etc/asterisk/softphone_anahtar (Laravel .env SOFTPHONE_SANTRAL_ANAHTAR ile ayni).
 */
require 'phpagi.php';

const SP_API = 'https://app.randevumcepte.com.tr/api/v1/softphone';
const SP_BEKLE_SN = 9;
const SP_POLL_MS = 700;

$agi = new AGI();
$dahili = preg_replace('/[^0-9]/', '', isset($argv[1]) ? $argv[1] : '');
$numara = isset($agi->request['agi_callerid']) ? $agi->request['agi_callerid'] : '';
$ad = isset($agi->request['agi_calleridname']) ? $agi->request['agi_calleridname'] : '';
if ($numara === 'unknown') $numara = '';
if ($ad === 'unknown' || $ad === $numara) $ad = '';

// Dahilinin surucusu: chan_sip peer'i varsa SIP, yoksa PJSIP.
$sipDurum = $agi->get_full_variable('${SIPPEER(' . $dahili . ',status)}');
$tek = (!empty($sipDurum['data']) && stripos($sipDurum['data'], 'error') === false) ? 'SIP' : 'PJSIP';
$agi->set_variable('SP_TEK', $tek);

function sonuc($agi, $s, $not = '')
{
    $agi->set_variable('SP_SONUC', $s);
    $agi->verbose("softphone push: $s $not", 1);
    exit(0);
}

function spPost($yol, $veri, $zamanAsimi = 3)
{
    $ch = curl_init(SP_API . $yol);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($veri),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => $zamanAsimi,
    ]);
    $yanit = curl_exec($ch);
    $kod = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $kod == 200 ? json_decode($yanit, true) : null;
}

$anahtar = trim((string) @file_get_contents('/etc/asterisk/softphone_anahtar'));
if ($dahili === '' || $anahtar === '') {
    sonuc($agi, 'hata', 'dahili/anahtar yok');
}

$h = spPost('/santral/hedefler', ['anahtar' => $anahtar, 'dahili' => $dahili]);
$hedefler = ($h && !empty($h['hedefler'])) ? $h['hedefler'] : [];
if (!$hedefler) {
    sonuc($agi, 'yok', "dahili $dahili icin iOS token yok");
}

// UUID v4 (CallKit cagri kimligi; uygulama "hazir"i bununla bildirir).
$b = random_bytes(16);
$b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
$b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
$uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
$agi->set_variable('SP_UUID', $uuid);

$girdi = json_encode([
    'hedefler' => $hedefler,
    'veri' => ['uuid' => $uuid, 'numara' => $numara, 'arayan' => $ad],
]);
$node = trim((string) shell_exec('command -v node 2>/dev/null')) ?: '/usr/bin/node';
$proc = proc_open(
    escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/softphoneVoipPush.js'),
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $borular
);
if (!is_resource($proc)) {
    sonuc($agi, 'hata', 'node calismadi');
}
fwrite($borular[0], $girdi);
fclose($borular[0]);
$cikti = stream_get_contents($borular[1]);
$hata = stream_get_contents($borular[2]);
fclose($borular[1]);
fclose($borular[2]);
proc_close($proc);

$sonuclar = json_decode($cikti, true) ?: [];
$gonderildi = 0;
foreach ($sonuclar as $r) {
    $agi->verbose("softphone push $dahili: {$r['durum']} {$r['ortam']} {$r['status']} {$r['neden']}", 1);
    if ($r['durum'] === 'ok') {
        $gonderildi++;
    } elseif ($r['durum'] === 'gecersiz') {
        spPost('/santral/token-sil', ['anahtar' => $anahtar, 'token' => $r['token']], 2);
    }
}
if ($hata) {
    $agi->verbose('softphone push node stderr: ' . substr($hata, 0, 300), 1);
}
if ($gonderildi === 0) {
    sonuc($agi, 'hata', 'push gonderilemedi');
}

// Uygulama register olup "hazir" diyene kadar bekle.
$bitis = microtime(true) + SP_BEKLE_SN;
while (microtime(true) < $bitis) {
    usleep(SP_POLL_MS * 1000);
    $d = spPost('/santral/hazir-mi', ['anahtar' => $anahtar, 'uuid' => $uuid], 2);
    if ($d && $d['durum'] === 'hazir') {
        // REGISTER'in Asterisk'e islenmesi icin kisa pay.
        usleep(300000);
        sonuc($agi, 'hazir', $uuid);
    }
    if ($d && $d['durum'] === 'red') {
        sonuc($agi, 'red', $uuid);
    }
}
sonuc($agi, 'zamanasimi', $uuid);
