<?php
// =====================================================================
// dakikaOzet.php — Salon bazli GIDEN (dis hat) konusma dakikasi ozeti.
//
// Amac: e-santral musterilerinin harcadigi dakikayi tek SUM(billsec) ile
// donmek. freepbxapi.php satir-satir/sayfali dondugu icin aylik binlerce
// cagriyi toplamaya uygun degil; burada sunucu tarafinda toplayip tek
// deger donuyoruz.
//
// Olcum: outbound_cnum = <trunk/did> olan leg = trunk uzerinden disari
// giden bacak. Bu bacagin billsec'i = operatorun faturaladigi gercek
// konusma suresi. Cagri basina TEK trunk leg'i dustugu icin dedup
// gerekmez; dogrudan SUM alinir.
//
// Cagri (GET):
//   dakikaOzet.php?did=<trunk_no>&tarih1=YYYY-MM-DD&tarih2=YYYY-MM-DD&dahili=<no>
//     did     : ZORUNLU. Salonun trunk/sabit numarasi (SabitNumaralar.numara).
//     tarih1  : opsiyonel baslangic (dahil, 00:00:00)
//     tarih2  : opsiyonel bitis     (dahil, 23:59:59)
//     dahili  : OPSIYONEL. Verilirse SADECE bu dahilinin baslattigi giden
//               cagrilarin trunk-leg billsec'i toplanir (personel bazli gorunum).
//               Trunk leg'inin src'si genelde trunk callerid'i oldugundan
//               dahili, ayni linkedid altindaki from-internal bacaktan
//               (channel = PJSIP/<dahili>-...) EXISTS ile eslesir.
//
// Yanit:
//   { "did":"...", "giden_cevaplanan_adet":N, "toplam_billsec":S,
//     "toplam_dakika":M, "tarih1":..., "tarih2":... }
//
// GUVENLIK: did yoksa bos doner (tum kiracilarin CDR'i sizmasin).
// =====================================================================

header('Content-Type: application/json');
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

$dsn  = 'mysql:host=127.0.0.1;dbname=asteriskcdrdb;charset=utf8';
$user = 'freepbxuser';
$pass = 'a4a8bbc17f1844dafa72c1c97041f8f4';

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

$did    = $_GET['did']    ?? null;
$tarih1 = $_GET['tarih1'] ?? null;
$tarih2 = $_GET['tarih2'] ?? null;
$dahili = $_GET['dahili'] ?? null;

// dahili yalnizca rakamsal olabilir (REGEXP'e gomulecek).
if ($dahili !== null && !preg_match('/^\d+$/', (string)$dahili)) $dahili = null;

if ($tarih1) $tarih1 = date('Y-m-d 00:00:00', strtotime($tarih1));
if ($tarih2) $tarih2 = date('Y-m-d 23:59:59', strtotime($tarih2));

// GUVENLIK: did zorunlu.
if (empty($did) || !preg_match('/^\d+$/', (string)$did)) {
    echo json_encode([
        'did'                   => $did,
        'giden_cevaplanan_adet' => 0,
        'toplam_billsec'        => 0,
        'toplam_dakika'         => 0,
        'tarih1'                => $tarih1,
        'tarih2'                => $tarih2,
        'error'                 => 'gecerli did (trunk) gerekli',
    ], JSON_PRETTY_PRINT);
    exit;
}

// t = trunk-out leg'i (billsec = gercek konusma). Alias, dahili EXISTS'i icin.
$where  = "t.outbound_cnum = :did AND t.disposition = 'ANSWERED'";
$params = [':did' => $did];

if ($tarih1 && $tarih2) {
    $where .= " AND t.calldate BETWEEN :t1 AND :t2";
    $params[':t1'] = $tarih1;
    $params[':t2'] = $tarih2;
}

// Personel bazli: trunk leg'i, ayni cagrida (linkedid) o dahilinin
// from-internal bacagi olan cagrilara daraltilir.
if ($dahili !== null) {
    $where .= " AND EXISTS (
        SELECT 1 FROM cdr l
        WHERE COALESCE(NULLIF(l.linkedid,''), l.uniqueid)
            = COALESCE(NULLIF(t.linkedid,''), t.uniqueid)
          AND l.channel REGEXP :dahiliRegex
    )";
    $params[':dahiliRegex'] = 'PJSIP/(' . $dahili . ')-';
}

try {
    $sql = "SELECT COUNT(*) AS adet, COALESCE(SUM(t.billsec),0) AS toplam_billsec
            FROM cdr t
            WHERE $where";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);

    $billsec = (int)($r['toplam_billsec'] ?? 0);

    echo json_encode([
        'did'                   => $did,
        'dahili'                => $dahili,
        'giden_cevaplanan_adet' => (int)($r['adet'] ?? 0),
        'toplam_billsec'        => $billsec,
        'toplam_dakika'         => round($billsec / 60, 1),
        'tarih1'                => $tarih1,
        'tarih2'                => $tarih2,
    ], JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
