<?php
/**
 * STT yardımcı fonksiyonları
 * sesliYanitOptimize.php tarafından kullanılır
 */

/**
 * Fonetik normalizasyon: STT'ın yanlış algıladığı İngilizce/teknik terimleri düzeltir.
 */
function fonetikNormalize($metin)
{
    if (!is_string($metin)) return $metin;
    $metin = trim(strtolower($metin));
    
    $aliases = [
        // HIFU
        'hayfu' => 'hifu', 'ay fu' => 'hifu', 'hi fu' => 'hifu',
        'ifu' => 'hifu', 'hif u' => 'hifu', 'hayfuu' => 'hifu',
        // LIFU
        'li fu' => 'lifu', 'layfu' => 'lifu', 'lif u' => 'lifu',
        'life' => 'lifu', 'lay fu' => 'lifu',
        // LIPOSONIX
        'lipo komik' => 'liposonix', 'lipokomik' => 'liposonix',
        'lipo konik' => 'liposonix', 'lipokonik' => 'liposonix',
        'lipo comics' => 'liposonix', 'ibo komiksin' => 'liposonix',
        'lipo sonik' => 'liposonix', 'liposonik' => 'liposonix',
        'lipo soniks' => 'liposonix', 'liposoniks' => 'liposonix',
        'lipo hongix' => 'liposonix', 'lipo komiks' => 'liposonix',
        // LIPOSUCTION
        'liposakşın' => 'liposuction', 'lipo sakşın' => 'liposuction',
        'liposükşın' => 'liposuction', 'lipo suction' => 'liposuction',
        'liposüction' => 'liposuction', 'lipo sakşin' => 'liposuction',
        // HYDRAFACIAL
        'hidrafacial' => 'hydrafacial', 'hidra facial' => 'hydrafacial',
        'hidra feyşıl' => 'hydrafacial', 'hayra fesıl' => 'hydrafacial',
        'hidrafeyşıl' => 'hydrafacial', 'hidra feşıl' => 'hydrafacial',
        // ARC SISTEM
        'ark sistem' => 'arc sistem', 'ar si sistem' => 'arc sistem',
        'arx sistem' => 'arc sistem', 'ars sistem' => 'arc sistem',
        // EDY SCULPT 360
        'edi skalpt' => 'edy sculpt', 'e d y sculpt' => 'edy sculpt',
        'edi sculpt' => 'edy sculpt', 'edi skalp' => 'edy sculpt',
        'ediy skalpt' => 'edy sculpt', 'edy skalpt' => 'edy sculpt',
        // G5
        'ci beş' => 'g5', 'ji beş' => 'g5', 'ji 5' => 'g5',
        'ci 5' => 'g5', 'g 5' => 'g5', 'ji five' => 'g5',
        // KARBON PEELING
        'karbon piling' => 'karbon peeling', 'karbon pilinğ' => 'karbon peeling',
        'karbon pilin' => 'karbon peeling',
        // POPOLIFT
        'popo lift' => 'popolift', 'popolif' => 'popolift',
        'popo lif' => 'popolift', 'popol ift' => 'popolift',
        // KIRPIK LIFTING
        'kirpik liftiğ' => 'kirpik lifting', 'kirpik liftin' => 'kirpik lifting',
        // DERMAPEN
        'derma pen' => 'dermapen',
        // PEELING genel
        'piling' => 'peeling', 'pilinğ' => 'peeling',
        // SCULPT genel
        'skalpt' => 'sculpt', 'skalp' => 'sculpt',
        // KOLAJEN IP
        'kolojen ip' => 'kolajen ip', 'kolajen i p' => 'kolajen ip',
    ];
    
    // 1. Tam eşleşme
    if (isset($aliases[$metin])) {
        return $aliases[$metin];
    }
    
    // 2. Kısmi eşleşme (uzundan kısaya sırala)
    $aliasKeys = array_keys($aliases);
    usort($aliasKeys, function($a, $b) { return mb_strlen($b) - mb_strlen($a); });
    
    foreach ($aliasKeys as $yanlis) {
        if (mb_stripos($metin, $yanlis) !== false) {
            $metin = str_ireplace($yanlis, $aliases[$yanlis], $metin);
        }
    }
    
    return $metin;
}

/**
 * Fuzzy hizmet eşleştirme: STT çıktısı ile hizmet adı arasında benzerlik skoru hesaplar.
 */
function fuzzyHizmetEslestir($soylenen, $hizmetAdi)
{
    $soylenen = trim(strtolower($soylenen));
    $hizmetAdi = trim(strtolower($hizmetAdi));

    // 1. similar_text ile genel benzerlik
    similar_text($soylenen, $hizmetAdi, $yuzde1);

    // 2. Kelime bazlı eşleşme
    $soylenenKelimeler = explode(' ', $soylenen);
    $hizmetKelimeler = explode(' ', $hizmetAdi);
    $eslesenKelime = 0;
    $toplamKelime = count($soylenenKelimeler);

    foreach ($soylenenKelimeler as $sk) {
        if (mb_strlen($sk) < 2) continue;
        foreach ($hizmetKelimeler as $hk) {
            $minUzunluk = min(mb_strlen($sk), mb_strlen($hk));
            $kokUzunluk = max(2, (int)($minUzunluk * 0.7));
            if (mb_substr($sk, 0, $kokUzunluk) === mb_substr($hk, 0, $kokUzunluk)) {
                $eslesenKelime++;
                break;
            }
        }
    }
    $yuzde2 = $toplamKelime > 0 ? ($eslesenKelime / $toplamKelime) * 100 : 0;

    // 3. Levenshtein mesafesi
    $levMesafe = levenshtein($soylenen, $hizmetAdi);
    $maxUzunluk = max(mb_strlen($soylenen), mb_strlen($hizmetAdi));
    $yuzde3 = $maxUzunluk > 0 ? (1 - $levMesafe / $maxUzunluk) * 100 : 0;

    // Ağırlıklı ortalama
    $sonSkor = ($yuzde2 * 0.45) + ($yuzde1 * 0.35) + ($yuzde3 * 0.20);

    return round($sonSkor);
}

/**
 * Gelişmiş evet/hayır algılama: tam eşleşme + substring + fuzzy
 */
function evetHayirVaryasyonGelismis($evetHayir)
{
    if (!is_string($evetHayir)) return '';
    
    $evetHayir = trim(strtolower($evetHayir));
    if ($evetHayir === '') return '';

    $evetVaryasyonlar = ["evet","elbette","tabi","tabii","tabii olur","tabi olur","neden olmas\u0131n","tabiki","tabii ki","tabiiki","istiyorum","olur","peki","tamam","kabul","onayl\u0131yorum","memnuniyetle","hay hay","he","hee","aynen","isterim"];
    $hayirVaryasyonlar = ["hay\u0131r","hayir","olmaz","istemiyorum","siktir git","\u00e7ek git","siktir lan","siktir","defol","defol ulen","defol lan","kapat","kapat la","kapat lan","hay\u0131r tabiki","hay\u0131r tabiiki","hay\u0131r tabi","hay\u0131r lan","yok","istemem","iptal","vazge\u00e7tim","gerek yok"];

    // 1. Tam eşleşme
    if(in_array($evetHayir,$evetVaryasyonlar)) return 'evet';
    if(in_array($evetHayir,$hayirVaryasyonlar)) return 'hay\u0131r';

    // 2. \u0130\u00e7erik kontrol\u00fc
    foreach ($evetVaryasyonlar as $v) {
        if (mb_stripos($evetHayir, $v) !== false) return 'evet';
    }
    foreach ($hayirVaryasyonlar as $v) {
        if (mb_stripos($evetHayir, $v) !== false) return 'hay\u0131r';
    }

    // 3. Fuzzy eşleşme
    $enYakinEvet = 0;
    $enYakinHayir = 0;
    foreach ($evetVaryasyonlar as $v) {
        similar_text($evetHayir, $v, $y);
        if ($y > $enYakinEvet) $enYakinEvet = $y;
    }
    foreach ($hayirVaryasyonlar as $v) {
        similar_text($evetHayir, $v, $y);
        if ($y > $enYakinHayir) $enYakinHayir = $y;
    }

    if ($enYakinEvet >= 70 && $enYakinEvet > $enYakinHayir) return 'evet';
    if ($enYakinHayir >= 70 && $enYakinHayir > $enYakinEvet) return 'hay\u0131r';

    return '';
}
