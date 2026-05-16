<?php
header('Content-Type: application/json');
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

$dsn = 'mysql:host=127.0.0.1;dbname=asteriskcdrdb;charset=utf8';
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

// -------------------------------------------------------
// Parametreler
// -------------------------------------------------------
$tarih1 = $_GET['tarih1'] ?? null;
$tarih2 = $_GET['tarih2'] ?? null;
$did    = $_GET['did']    ?? null;

if ($tarih1) $tarih1 = date('Y-m-d 00:00:00', strtotime($tarih1));
if ($tarih2) $tarih2 = date('Y-m-d 23:59:59', strtotime($tarih2));

$dahiliArray   = $_GET['dahililer']    ?? [];
$operatorKanali = $_GET['operatorKanali'] ?? null;
$trunk         = $_GET['trunk']        ?? null;

// -------------------------------------------------------
// Sayfalama parametreleri
// -------------------------------------------------------
$limit  = isset($_GET['limit'])  ? (int)$_GET['limit']  : 50;   // sayfa başına kayıt
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;    // kaçıncı kayıttan başla

// Güvenlik: makul sınırlar
if ($limit  < 1)   $limit  = 50;
if ($limit  > 500) $limit  = 500;
if ($offset < 0)   $offset = 0;

// linkedid bazli dedupe sonrasi yetecek kadar fetch et (her cagri 2-3 leg uretebilir)
$fetchLimit = $limit * 3;

// -------------------------------------------------------
// WHERE koşulları
// -------------------------------------------------------
$params = [];
$whereClauses = [];

// GÜVENLİK: Salon kimliği (did/dahili/trunk) verilmemisse bos don.
// Aksi halde tum CDR tablosu (tum isletmelerin kayitlari) sizar.
if (empty($did) && empty($dahiliArray) && empty($trunk)) {
    echo json_encode([
        'data'     => [],
        'total'    => 0,
        'limit'    => $limit,
        'offset'   => $offset,
        'has_more' => false,
    ], JSON_PRETTY_PRINT);
    exit;
}

// Sabit numara (did) ve/veya dahili listesi ile salon kapsamına filtrele.
// - Gelen aramalar: cdr.did = sabit numara
// - Giden aramalar: outbound_cnum / cnum = sabit numara (trunk outbound CID)
// - Ext-local (kuyruktan dahiliye düşen cevap kayıtları): did boş olur,
//   bu yüzden dahili channel/dstchannel REGEXP eşleşmesi de ekleniyor.
//   Aksi halde dahilinin cevap verdiği görüşmeler ve ses kayıtları listede çıkmıyor.
if (!empty($did) && !empty($dahiliArray)) {
    $regex = implode('|', array_map('preg_quote', $dahiliArray));
    $whereClauses[] = "(
        did = :did
        OR outbound_cnum = :did
        OR cnum = :did
        OR channel    REGEXP 'PJSIP/($regex)-'
        OR dstchannel REGEXP 'PJSIP/($regex)-'
    )";
    $params[':did'] = $did;
} else if (!empty($did)) {
    $whereClauses[] = "(did = :did OR outbound_cnum = :did OR cnum = :did)";
    $params[':did'] = $did;
} else if (!empty($dahiliArray)) {
    $regex = implode('|', array_map('preg_quote', $dahiliArray));
    $whereClauses[] = "(
        channel    REGEXP 'PJSIP/($regex)-'
        OR dstchannel REGEXP 'PJSIP/($regex)-'
    )";
}

if (!empty($trunk)) {
    $whereClauses[] = "(channel LIKE :trunk OR dstchannel LIKE :trunk)";
    $params[':trunk'] = "%$trunk%";
}

if (!empty($operatorKanali)) {
    $whereClauses[] = "dst = :operatorKanali";
    $params[':operatorKanali'] = $operatorKanali;
}

if ($tarih1 && $tarih2) {
    $whereClauses[] = "calldate BETWEEN :t1 AND :t2";
    $params[':t1'] = $tarih1;
    $params[':t2'] = $tarih2;
}

// -------------------------------------------------------
// Toplam kayıt sayısını al (Flutter'da _hasMore kontrolü için)
// -------------------------------------------------------
$countSql = "SELECT COUNT(*) FROM cdr";
if (!empty($whereClauses)) {
    $countSql .= " WHERE " . implode(' AND ', $whereClauses);
}

try {
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $totalCount = (int)$countStmt->fetchColumn();
} catch (PDOException $e) {
    $totalCount = 0;
}

// -------------------------------------------------------
// Ana sorgu — LIMIT ve OFFSET eklendi
// -------------------------------------------------------
$sql = "SELECT
            calldate,
            clid,
            src,
            dst,
            dcontext,
            channel,
            dstchannel,
            lastapp,
            lastdata,
            duration,
            billsec,
            disposition,
            amaflags,
            accountcode,
            uniqueid,
            userfield,
            did,
            recordingfile,
            cnum,
            cnam,
            outbound_cnum,
            outbound_cnam,
            dst_cnam,
            linkedid,
            peeraccount,
            sequence
        FROM cdr";

if (!empty($whereClauses)) {
    $sql .= " WHERE " . implode(' AND ', $whereClauses);
}

$sql .= " ORDER BY calldate DESC";
$sql .= " LIMIT :limit OFFSET :offset";   // <-- EKLENEN KISIM

// PDO'da integer bind için bindValue kullanmak gerekir
try {
    $stmt = $pdo->prepare($sql);

    // Diğer parametreleri bağla
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val);
    }

    // LIMIT ve OFFSET integer olarak bağlanmalı
    // Dedupe sonrasi yeterli kayit kalmasi icin fetchLimit kullaniyoruz.
    $stmt->bindValue(':limit',  $fetchLimit,  PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

    $stmt->execute();
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // linkedid bazinda dedupe: ayni cagrinin birden fazla leg'i (operator-bagla + ext-local)
    // beraber dondugu icin, ses kaydi olan / ext-local olan / en bilgilendirici satiri secelim.
    // Tercih sirasi: recordingfile dolu > dcontext=ext-local > digerleri (ilk gelen).
    $deduped = [];
    foreach ($data as $row) {
        $lid = $row['linkedid'] ?? '';
        if ($lid === '') { $deduped[] = $row; continue; }

        if (!isset($deduped[$lid])) {
            $deduped[$lid] = $row;
            continue;
        }

        $cur = $deduped[$lid];
        $curScore = (!empty($cur['recordingfile']) ? 2 : 0) + (($cur['dcontext'] ?? '') === 'ext-local' ? 1 : 0);
        $newScore = (!empty($row['recordingfile']) ? 2 : 0) + (($row['dcontext'] ?? '') === 'ext-local' ? 1 : 0);
        if ($newScore > $curScore) {
            $deduped[$lid] = $row;
        }
    }
    $data = array_values($deduped);

    // SQL'den fetchLimit (limit*3) cektik; istemciye sadece limit kadar gonder.
    // limit'ten fazla deduped sonuc varsa has_more=true demektir.
    $hasMoreAfterDedupe = count($data) > $limit;
    if ($hasMoreAfterDedupe) {
        $data = array_slice($data, 0, $limit);
    }

    // Ses kayıt yolu ekle
    foreach ($data as &$row) {
        if (!empty($row['recordingfile'])) {
            $year  = date('Y', strtotime($row['calldate']));
            $month = date('m', strtotime($row['calldate']));
            $day   = date('d', strtotime($row['calldate']));
            $row['recording_path'] = "https://santral.randevumcepte.com.tr/monitor/$year/$month/$day/{$row['recordingfile']}";
        } else {
            $row['recording_path'] = null;
        }
    }

    // Flutter'ın ihtiyaç duyduğu meta bilgiler
    // has_more: dedupe sonrasi limit'i astiysa true; aksi halde SQL total'a gore karar ver.
    // (totalCount dedupe'tan onceki ham CDR sayisidir; kesin sayfalama sayisi degildir.)
    $hasMore = $hasMoreAfterDedupe || (($offset + $fetchLimit) < $totalCount);

    echo json_encode([
        'data'       => $data,
        'total'      => $totalCount,
        'limit'      => $limit,
        'offset'     => $offset,
        'has_more'   => $hasMore,
    ], JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    echo json_encode([
        'error'  => $e->getMessage(),
        'sql'    => $sql,
        'params' => $params,
    ], JSON_PRETTY_PRINT);
}
