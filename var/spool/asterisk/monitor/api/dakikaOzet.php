<?php
// =====================================================================
// dakikaOzet.php — Salon bazli GIDEN (dis hat) konusma dakikasi ozeti.
//
// Amac: e-santral musterilerinin harcadigi dakikayi tek SUM(billsec) ile
// donmek. freepbxapi.php satir-satir/sayfali dondugu icin aylik binlerce
// cagriyi toplamaya uygun degil; burada sunucu tarafinda toplayip tek
// deger donuyoruz.
//
// Olcum: dstchannel = 'SIP/<numara>-out-...' olan leg = trunk uzerinden disari
// giden cevaplanan bacak. Bu bacagin billsec'i = operatorun faturaladigi gercek
// konusma suresi. outbound_cnum KULLANILMAZ (dahili/route kendi CID'ini yazip
// cagrilarin cogunu kaciriyordu); trunk numarasi her zaman dstchannel'da olur.
// Cagri basina TEK trunk leg'i dustugu icin dedup gerekmez; dogrudan SUM alinir.
//
// Cagri (GET):
//   dakikaOzet.php?did=<trunk_no>&tarih1=YYYY-MM-DD&tarih2=YYYY-MM-DD&dahili=<no>
//     did     : ZORUNLU. Salonun trunk/sabit numarasi (SabitNumaralar.numara).
//     tarih1  : opsiyonel baslangic (dahil, 00:00:00)
//     tarih2  : opsiyonel bitis     (dahil, 23:59:59)
//     dahili  : OPSIYONEL. Verilirse SADECE bu dahilinin baslattigi giden
//               cagrilar sayilir (personel bazli gorunum). Ayni CDR satirinda
//               channel = PJSIP/<dahili>-... oldugundan tek satirdan cikar.
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

// Giden cagri kimligi: TRUNK KANALI (dstchannel = SIP/<numara>-out-...).
// outbound_cnum GUVENILMEZ: dahili/route kendi CID'ini yaziyor, cagrilarin cogu
// numarayla eslesmiyordu (299 cagridan yalnizca 27'si). dstchannel'da ise trunk
// numarasi her zaman var ve her salonun numarasi benzersiz oldugundan
// multi-tenant'ta da salonu temiz ayirir. Ayni satirda channel=PJSIP/<dahili>-
// oldugu icin personel bazli olcum de tek satirdan cikar (EXISTS gerekmez).
$where  = "t.dstchannel LIKE :trunkpat AND t.disposition = 'ANSWERED'";
$params = [':trunkpat' => '%' . $did . '-%'];

if ($tarih1 && $tarih2) {
    $where .= " AND t.calldate BETWEEN :t1 AND :t2";
    $params[':t1'] = $tarih1;
    $params[':t2'] = $tarih2;
}

// =====================================================================
// DEBUG modu: ?debug=1 -> sapma teshisi. Farkli eslesme/tarih senaryolarini
// yan yana dondurur ki "ana sistem" degeriyle hangisi tutuyor gorulsun.
// (Bu blok normal ciktidan once calisip exit eder.)
// =====================================================================
if (!empty($_GET['debug'])) {
    $last10 = substr(preg_replace('/\D/', '', (string)$did), -10);
    $out = ['did' => $did, 'last10' => $last10];

    try {
        // 1) Mevcut mantik: outbound_cnum = did TAM eslesme, TARIH PENCERELI.
        if ($tarih1 && $tarih2) {
            $s = $pdo->prepare("SELECT COUNT(*) a, COALESCE(SUM(billsec),0) b FROM cdr
                                WHERE outbound_cnum = :did AND disposition='ANSWERED'
                                  AND calldate BETWEEN :t1 AND :t2");
            $s->execute([':did'=>$did, ':t1'=>$tarih1, ':t2'=>$tarih2]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            $out['A_pencereli_tam_eslesme'] = ['tarih1'=>$tarih1,'tarih2'=>$tarih2,'adet'=>(int)$r['a'],'dakika'=>round($r['b']/60,1),'saniye'=>(int)$r['b']];
        } else {
            $out['A_pencereli_tam_eslesme'] = 'tarih verilmedi (tum zaman)';
        }

        // 2) TUM ZAMAN, outbound_cnum = did TAM eslesme.
        $s = $pdo->prepare("SELECT COUNT(*) a, COALESCE(SUM(billsec),0) b FROM cdr
                            WHERE outbound_cnum = :did AND disposition='ANSWERED'");
        $s->execute([':did'=>$did]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        $out['B_tumzaman_tam_eslesme'] = ['adet'=>(int)$r['a'],'dakika'=>round($r['b']/60,1),'saniye'=>(int)$r['b']];

        // 3) TUM ZAMAN, outbound_cnum SON 10 HANE ile biter (format toleransli).
        $s = $pdo->prepare("SELECT COUNT(*) a, COALESCE(SUM(billsec),0) b FROM cdr
                            WHERE outbound_cnum LIKE :p AND disposition='ANSWERED'");
        $s->execute([':p'=>'%'.$last10]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        $out['C_tumzaman_son10_like'] = ['pattern'=>'%'.$last10,'adet'=>(int)$r['a'],'dakika'=>round($r['b']/60,1),'saniye'=>(int)$r['b']];

        // 4) CDR'da bu hatta ait GORULEN outbound_cnum formatlari (ilk 20).
        $s = $pdo->prepare("SELECT outbound_cnum, COUNT(*) a, COALESCE(SUM(billsec),0) b
                            FROM cdr
                            WHERE outbound_cnum LIKE :p AND disposition='ANSWERED'
                            GROUP BY outbound_cnum ORDER BY a DESC LIMIT 20");
        $s->execute([':p'=>'%'.$last10]);
        $out['D_gorulen_outbound_cnum_formatlari'] = array_map(function($x){
            return ['outbound_cnum'=>$x['outbound_cnum'],'adet'=>(int)$x['a'],'dakika'=>round($x['b']/60,1)];
        }, $s->fetchAll(PDO::FETCH_ASSOC));

        // 5) Tarih araligi (son10 like uzerinden).
        $s = $pdo->prepare("SELECT MIN(calldate) mn, MAX(calldate) mx FROM cdr
                            WHERE outbound_cnum LIKE :p AND disposition='ANSWERED'");
        $s->execute([':p'=>'%'.$last10]);
        $out['E_tarih_araligi'] = $s->fetch(PDO::FETCH_ASSOC);

        // 6) Bilinen 27 cagrinin HAM ornekleri — trunk kanal adini gormek icin.
        $s = $pdo->prepare("SELECT calldate, src, dst, channel, dstchannel, dcontext, lastapp, billsec
                            FROM cdr
                            WHERE outbound_cnum = :did AND disposition='ANSWERED'
                            ORDER BY calldate DESC LIMIT 5");
        $s->execute([':did'=>$did]);
        $out['F_ornek_satirlar'] = $s->fetchAll(PDO::FETCH_ASSOC);

        // 7) Bilinen cagrilardan trunk kanal onekleri (PJSIP/<trunk>-...).
        $s = $pdo->prepare("SELECT SUBSTRING_INDEX(dstchannel,'-',1) tch, COUNT(*) a, COALESCE(SUM(billsec),0) b
                            FROM cdr
                            WHERE outbound_cnum = :did AND disposition='ANSWERED' AND dstchannel <> ''
                            GROUP BY tch ORDER BY a DESC");
        $s->execute([':did'=>$did]);
        $prefixler = $s->fetchAll(PDO::FETCH_ASSOC);
        $out['G_trunk_kanal_onekleri'] = $prefixler;

        // 8) Her trunk onegi icin TUM ZAMAN toplam (dstchannel bazli = hat bazli).
        //    Ana sistemdeki 492 dk ile hangisi tutuyor buradan gorulur.
        $out['H_trunk_kanal_bazli_tumzaman'] = [];
        foreach ($prefixler as $p) {
            $tch = $p['tch'];
            if ($tch === '' || strpos($tch, '/') === false) continue; // SIP/ veya PJSIP/
            $s = $pdo->prepare("SELECT COUNT(*) a, COALESCE(SUM(billsec),0) b FROM cdr
                                WHERE dstchannel LIKE :pref AND disposition='ANSWERED'");
            $s->execute([':pref'=>$tch.'-%']);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            $out['H_trunk_kanal_bazli_tumzaman'][] = [
                'trunk_kanal' => $tch,
                'adet'        => (int)$r['a'],
                'dakika'      => round($r['b']/60,1),
                'saniye'      => (int)$r['b'],
            ];
        }

        // 9) Hattın numarasini iceren HER dstchannel (trunk adi degisse de yakalar),
        //    cevaplanan tum zaman. En genis "hat bazli" olcum.
        $s = $pdo->prepare("SELECT COUNT(*) a, COALESCE(SUM(billsec),0) b FROM cdr
                            WHERE dstchannel LIKE :p AND disposition='ANSWERED'");
        $s->execute([':p'=>'%'.$last10.'%']);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        $out['I_dstchannel_numara_iceren_tumzaman'] = ['adet'=>(int)$r['a'],'dakika'=>round($r['b']/60,1),'saniye'=>(int)$r['b']];

        // 10) Dahili 34 (ornek satirlardaki arayan) TUM giden Dial cagrilari - baska
        //     trunk/route uzerinden de cikmis mi? channel=PJSIP/34-... + Dial + disaridan.
        $s = $pdo->prepare("SELECT COUNT(*) a, COALESCE(SUM(billsec),0) b FROM cdr
                            WHERE channel LIKE 'PJSIP/34-%' AND lastapp='Dial'
                              AND disposition='ANSWERED' AND LENGTH(dst) >= 7");
        $s->execute();
        $r = $s->fetch(PDO::FETCH_ASSOC);
        $out['J_dahili34_tum_giden_dial'] = ['adet'=>(int)$r['a'],'dakika'=>round($r['b']/60,1),'saniye'=>(int)$r['b']];

    } catch (PDOException $e) {
        $out['error'] = $e->getMessage();
    }

    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// Personel bazli: cagriyi baslatan dahili leg'i ayni satirda (channel).
// Dahili genelde PJSIP, trunk SIP oldugundan (PJSIP|SIP)/<dahili>- guvenli.
if ($dahili !== null) {
    $where .= " AND t.channel REGEXP :dahiliRegex";
    $params[':dahiliRegex'] = '(PJSIP|SIP)/' . $dahili . '-';
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
