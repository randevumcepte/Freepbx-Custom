#!/usr/bin/env node
// /var/lib/asterisk/agi-bin/tarihParser.js

const tr = {
    months: {
        'ocak': 1, 'şubat': 2, 'mart': 3, 'nisan': 4,
        'mayıs': 5, 'haziran': 6, 'temmuz': 7, 'ağustos': 8,
        'eylül': 9, 'ekim': 10, 'kasım': 11, 'aralık': 12
    },
    days: {
        'pazartesi': 1, 'salı': 2, 'çarşamba': 3, 'perşembe': 4,
        'cuma': 5, 'cumartesi': 6, 'pazar': 7
    },
    // STT'nin yanlış algılayabileceği gün adı varyasyonları
    dayVariants: {
        'pazartesiye': 1, 'pazartesine': 1, 'pazartes': 1,
        'salıya': 2, 'salıda': 2, 'salına': 2,
        'çarşambaya': 3, 'çarşambada': 3, 'çarşambayı': 3,
        'perşembeye': 4, 'perşembede': 4, 'perşembeyi': 4,
        'cumaya': 5, 'cumada': 5, 'cumayı': 5,
        'cumartesiye': 6, 'cumartesine': 6, 'cumartes': 6,
        'pazara': 7, 'pazarda': 7, 'pazarı': 7
    },
    periods: {
        'sabah': 9,
        'öğle': 12, 'öğlen': 12,
        'öğleden sonra': 15,
        'ikindi': 16,
        'akşamüstü': 17, 'akşam üstü': 17, 'akşamüzeri': 17, 'akşam üzeri': 17,
        'akşam': 19,
        'gece': 22, 'gece yarısı': 0
    },
    // Yazıyla söylenen sayılar (STT bazen yazıyla döndürür)
    wordNumbers: {
        'bir': 1, 'iki': 2, 'üç': 3, 'dört': 4, 'beş': 5,
        'altı': 6, 'yedi': 7, 'sekiz': 8, 'dokuz': 9, 'on': 10,
        'on bir': 11, 'on iki': 12, 'on üç': 13, 'on dört': 14,
        'on beş': 15, 'on altı': 16, 'on yedi': 17, 'on sekiz': 18,
        'on dokuz': 19, 'yirmi': 20, 'yirmi bir': 21, 'yirmi iki': 22,
        'yirmi üç': 23, 'yirmi dört': 24, 'yirmi beş': 25,
        'yirmi altı': 26, 'yirmi yedi': 27, 'yirmi sekiz': 28,
        'yirmi dokuz': 29, 'otuz': 30, 'otuz bir': 31
    },
    // Türkçe isim çekimli sayı formları ("üçü", "dörde", "beşi", "altıyı" ...)
    suffixedWordNumbers: {
        'biri': 1, 'bire': 1, 'birde': 1,
        'ikiyi': 2, 'ikiye': 2, 'ikide': 2,
        'üçü': 3, 'üçe': 3, 'üçte': 3,
        'dördü': 4, 'dörde': 4, 'dörtte': 4,
        'beşi': 5, 'beşe': 5, 'beşte': 5,
        'altıyı': 6, 'altıya': 6, 'altıda': 6,
        'yediyi': 7, 'yediye': 7, 'yedide': 7,
        'sekizi': 8, 'sekize': 8, 'sekizde': 8,
        'dokuzu': 9, 'dokuza': 9, 'dokuzda': 9,
        'onu': 10, 'ona': 10, 'onda': 10,
        'on biri': 11, 'on bire': 11,
        'on ikiyi': 12, 'on ikiye': 12,
        'on üçü': 13, 'on üçe': 13,
        'on dördü': 14, 'on dörde': 14,
        'on beşi': 15, 'on beşe': 15,
        'on altıyı': 16, 'on altıya': 16,
        'on yediyi': 17, 'on yediye': 17,
        'on sekizi': 18, 'on sekize': 18,
        'on dokuzu': 19, 'on dokuza': 19,
        'yirmiyi': 20, 'yirmiye': 20, 'yirmide': 20,
        'yirmi biri': 21, 'yirmi bire': 21,
        'yirmi ikiyi': 22, 'yirmi ikiye': 22,
        'yirmi üçü': 23, 'yirmi üçe': 23
    }
};

/**
 * Metindeki yazıyla yazılmış sayıları rakama çevirir.
 * "on beş mart saat üç buçuk" -> "15 mart saat 3 buçuk"
 */
function wordNumbersToDigits(text) {
    // 1. Önce ekli/çekimli formları dönüştür ("on üçü", "dörde", ...)
    //    Bunları normal formlardan ÖNCE işle, yoksa "on" parçası 10'a döner, "üçü" kalır.
    const suffixedEntries = Object.entries(tr.suffixedWordNumbers)
        .sort((a, b) => b[0].length - a[0].length); // Uzundan kısaya
    for (const [word, num] of suffixedEntries) {
        const regex = new RegExp(`(?<![a-zçğıöşü])${word}(?![a-zçğıöşü])`, 'g');
        text = text.replace(regex, String(num));
    }

    // 2. Sonra iki kelimelik sayıları dönüştür (on bir, on iki, ...)
    const twoWordNumbers = Object.entries(tr.wordNumbers)
        .filter(([k]) => k.includes(' '))
        .sort((a, b) => b[0].length - a[0].length);

    for (const [word, num] of twoWordNumbers) {
        text = text.replace(new RegExp(word, 'g'), String(num));
    }

    // 3. Sonra tek kelimelik sayıları dönüştür
    const singleWordNumbers = Object.entries(tr.wordNumbers)
        .filter(([k]) => !k.includes(' '))
        .sort((a, b) => b[0].length - a[0].length);

    for (const [word, num] of singleWordNumbers) {
        const regex = new RegExp(`(?<![a-zçğıöşü])${word}(?![a-zçğıöşü])`, 'g');
        text = text.replace(regex, String(num));
    }

    // 4a. Apostroflu ekleri her zaman temizle ("3'ü" → "3", "15'e" → "15")
    text = text.replace(/(\d{1,2})['\u2019][iıuüeay]+/g, '$1');

    // 4b. Apostrofsuz ekleri sadece saat kelimesi / sayı öncesinde temizle
    //     ("4e 10 kala" → "4 10 kala", "3 ü çeyrek" → "3 çeyrek")
    //     Keyword listesi hem Türkçe hem ASCII içersin.
    text = text.replace(
        /(\d{1,2})\s*[iıuüeay]+(?=\s+(?:\d|çeyrek|ceyrek|geçe|gece|geçiyor|geciyor|var|kala|buçuk|bucuk))/gi,
        '$1'
    );

    // 4c. Birleşik saat+dakika desenini böl (STT "210 geçe" yazıyor "2 10 geçe" yerine)
    text = text.replace(
        /(?<!\d)(\d{1,2})(\d{2})\s+(geçe|gece|geçiyor|geciyor|geçiyorum|geciyorum|var|kala)/gi,
        '$1 $2 $3'
    );

    return text;
}

const readline = require('readline');
const rl = readline.createInterface({ input: process.stdin, output: process.stdout });

// Türkçe karakterleri ASCII'ye çevir (STT bazen ı/ş/ç/ğ vs. koymuyor)
function asciiize(s) {
    return s
        .replace(/ç/g, 'c').replace(/Ç/g, 'c')
        .replace(/ğ/g, 'g').replace(/Ğ/g, 'g')
        .replace(/ı/g, 'i').replace(/I/g, 'i').replace(/İ/g, 'i')
        .replace(/ö/g, 'o').replace(/Ö/g, 'o')
        .replace(/ş/g, 's').replace(/Ş/g, 's')
        .replace(/ü/g, 'u').replace(/Ü/g, 'u')
        .replace(/â/g, 'a').replace(/î/g, 'i').replace(/û/g, 'u');
}

// wordNumbers ve suffixedWordNumbers için ASCII varyantlarını ekle
for (const tablo of ['wordNumbers', 'suffixedWordNumbers']) {
    const yeni = {};
    for (const [k, v] of Object.entries(tr[tablo])) {
        yeni[k] = v;
        const a = asciiize(k);
        if (a !== k && !(a in yeni)) yeni[a] = v;
    }
    tr[tablo] = yeni;
}

rl.question('', (text) => {
    try {
        let original = text.toLowerCase().trim();

        // DEBUG: Gelen metni yaz
        console.error("GELEN:", original);

        // Yazıyla yazılmış sayıları rakama çevir
        original = wordNumbersToDigits(original);
        console.error("SAYI DONUSUMU:", original);

        // Keyword arama için ASCII-normalize edilmiş sürüm
        const asciiOrig = asciiize(original);
        console.error("ASCII:", asciiOrig);

        let gun = null;
        let ay = null;
        let gunAdi = null;
        let saat = null;
        let dakika = 0;
        let period = null;
        let relative = null;

        // 1. Gün + Ay (5 mart, 15 nisan) — ASCII sürüm üzerinden
        const aylarAscii = { 'ocak':1,'subat':2,'mart':3,'nisan':4,'mayis':5,'haziran':6,'temmuz':7,'agustos':8,'eylul':9,'ekim':10,'kasim':11,'aralik':12 };
        const gunAyMatch = asciiOrig.match(/(\d{1,2})\s+(ocak|subat|mart|nisan|mayis|haziran|temmuz|agustos|eylul|ekim|kasim|aralik)/);
        if (gunAyMatch) {
            gun = parseInt(gunAyMatch[1]);
            ay = aylarAscii[gunAyMatch[2]];
            console.error("Gun+Ay:", gun, ay);
        }

        // 2. Gün adı (çarşamba, cumaya, vs.) — ASCII sürüm üzerinden
        const allDayEntries = [
            ...Object.entries(tr.dayVariants),
            ...Object.entries(tr.days)
        ]
        .map(([k, v]) => [asciiize(k), v])
        .sort((a, b) => b[0].length - a[0].length);

        for (let [g, val] of allDayEntries) {
            if (asciiOrig.includes(g)) {
                gunAdi = val;
                console.error("Gun adi:", g, "->", gunAdi);
                break;
            }
        }

        // 3. Saat:dakika formatı (15:30, 3:00)
        const saatDakikaMatch = original.match(/(\d{1,2}):(\d{2})/);
        if (saatDakikaMatch) {
            saat = parseInt(saatDakikaMatch[1]);
            dakika = parseInt(saatDakikaMatch[2]);
            console.error("Saat:Dakika:", saat, dakika);
        }

        // 3b. Saat.dakika veya Saat,dakika formatı (STT "üç buçuk"u "3.30" yazabiliyor)
        //     "3.30" → 3:30, "3,45" → 3:45, "3,5" veya "3.5" → 3:30 (yarım)
        if (saat === null) {
            const saatDakikaNoktaVirgul = original.match(/(\d{1,2})[.,](\d{1,2})(?!\d)/);
            if (saatDakikaNoktaVirgul) {
                saat = parseInt(saatDakikaNoktaVirgul[1]);
                const dpStr = saatDakikaNoktaVirgul[2];
                const dp = parseInt(dpStr);
                if (dpStr.length === 1 && dp === 5) {
                    dakika = 30; // "3,5" = 3.5 = 3:30
                } else if (dp >= 0 && dp < 60) {
                    dakika = dp;
                } else {
                    dakika = 0;
                }
                console.error("Saat.Dakika (nokta/virgül):", saat, dakika);
            }
        }

        // 4a. "X çeyrek geçe/geçiyor" → X:15  (ASCII: ceyrek, gece/geciyor — "gece" night ile çakışma riski var ama context gerekir)
        if (saat === null) {
            let m = asciiOrig.match(/(\d{1,2})\s+ceyrek\s*(?:gece|geciyor|geciyorum)/)
                 || asciiOrig.match(/ceyrek\s*(?:gece|geciyor|geciyorum)\s+(\d{1,2})/);
            if (m) {
                saat = parseInt(m[1]);
                dakika = 15;
                console.error("Çeyrek geçe:", saat, ":15");
            }
        }

        // 4b. "X çeyrek var/kala" → (X-1):45
        if (saat === null) {
            let m = asciiOrig.match(/(\d{1,2})\s+ceyrek\s*(?:var|kala)/)
                 || asciiOrig.match(/ceyrek\s*(?:var|kala)\s+(\d{1,2})/);
            if (m) {
                let h = parseInt(m[1]);
                saat = h - 1;
                if (saat < 0) saat = 23;
                dakika = 45;
                console.error("Çeyrek var/kala:", saat, ":45");
            }
        }

        // 4c. "X N geçe/geçiyor" → X:N
        if (saat === null) {
            const m = asciiOrig.match(/(\d{1,2})\s+(\d{1,2})\s*(?:gece|geciyor|geciyorum)/);
            if (m) {
                saat = parseInt(m[1]);
                dakika = parseInt(m[2]);
                console.error("N geçe:", saat, ":", dakika);
            }
        }

        // 4d. "X N var/kala" → (X-1):(60-N)
        if (saat === null) {
            const m = asciiOrig.match(/(\d{1,2})\s+(\d{1,2})\s*(?:var|kala)/);
            if (m) {
                let h = parseInt(m[1]);
                let mm = parseInt(m[2]);
                if (mm > 0 && mm < 60) {
                    saat = h - 1;
                    if (saat < 0) saat = 23;
                    dakika = 60 - mm;
                    console.error("N var/kala:", saat, ":", dakika);
                }
            }
        }

        // 5. "buçuk" / "yarım" kontrolü (3 buçuk -> 3:30, saat 4 yarım -> 4:30)
        const bucukMatch = asciiOrig.match(/(\d{1,2})\s*(?:bucuk|yarim)/);
        if (bucukMatch && saat === null) {
            saat = parseInt(bucukMatch[1]);
            dakika = 30;
            console.error("Bucuk:", saat, ":30");
        }

        // 6. "saat X" formatı (saat 3, saat 15)
        if (saat === null) {
            const saatMatch = original.match(/saat\s*(\d{1,2})/);
            if (saatMatch) {
                saat = parseInt(saatMatch[1]);
                console.error("Saat (saat X):", saat);
            }
        }

        // Bağıl SÜRE ("N gün/hafta/ay sonra", "gelecek/önümüzdeki ay") — eskiden HİÇ işlenmiyordu,
        // sessizce BUGÜN üretiyordu. Erken tespit: son-sayı adımı offset sayısını saat sanmasın.
        let relOffset = null;
        {
            let mD;
            if ((mD = asciiOrig.match(/(\d{1,3})\s*gun\s*sonra/)))       relOffset = { days: parseInt(mD[1]) };
            else if ((mD = asciiOrig.match(/(\d{1,3})\s*hafta\s*sonra/))) relOffset = { days: parseInt(mD[1]) * 7 };
            else if ((mD = asciiOrig.match(/(\d{1,3})\s*ay\s*sonra/)))    relOffset = { months: parseInt(mD[1]) };
            else if (/\b(gelecek|onumuzdeki)\s+ay\b/.test(asciiOrig))     relOffset = { months: 1 };
        }

        // 7. Son çare: metindeki son sayıyı saat olarak al (bağıl süre YOKSA)
        //    Ama gün+ay zaten bulunduysa, gün sayısını hariç tut
        if (saat === null && !relOffset) {
            const numbers = original.match(/\d{1,2}/g);
            if (numbers && numbers.length > 0) {
                // Eğer gün+ay varsa, ilk sayı gün'dür, son sayıyı al
                if (gun && ay && numbers.length > 1) {
                    saat = parseInt(numbers[numbers.length - 1]);
                } else if (!gun || !ay) {
                    // Gün+ay yoksa son sayı saattir
                    saat = parseInt(numbers[numbers.length - 1]);
                }
                if (saat !== null) console.error("Saat (son sayi):", saat);
            }
        }

        // 7. Zaman dilimi — ASCII sürüm üzerinden. Uzundan kısaya sırala, substring çakışmasını önle.
        //    (ör: "öğleden sonra" → "ogleden sonra"; "öğle" → "ogle")
        const periodAsciiPairs = Object.entries(tr.periods)
            .map(([k, v]) => [asciiize(k), k])
            .sort((a, b) => b[0].length - a[0].length);
        for (const [asciiKey, origKey] of periodAsciiPairs) {
            if (asciiOrig.includes(asciiKey)) {
                period = origKey; // İçeride orijinal Türkçe anahtar kullanılıyor (tr.periods[period])
                break;
            }
        }
        if (period) console.error("Zaman dilimi:", period);
        // "geçe" (dakika deyimi) asciiize ile "gece" olup gece(22) sanılıyordu → +12 kayması.
        // Orijinal metinde gerçek "gece" yoksa (yani aslında "geçe" ise) period'u iptal et.
        if ((period === 'gece' || period === 'gece yarısı') && !/gece/.test(original)) {
            console.error("'gece' period iptal (aslında 'geçe'):", original);
            period = null;
        }

        // 8. Relative (bağıl zaman) — ASCII sürüm üzerinden (STT "yarın"ı "yarin" yazabiliyor)
        if (relOffset) {
            relative = 'offset';
        } else if (asciiOrig.includes('obur gun') || asciiOrig.includes('oburgun')) {
            relative = 'day_after_tomorrow';
        } else if (asciiOrig.includes('yarin')) {
            relative = 'tomorrow';
        } else if (asciiOrig.includes('bugun')) {
            relative = 'today';
        } else if (asciiOrig.includes('gelecek') || asciiOrig.includes('onumuzdeki') || asciiOrig.includes('haftaya')) {
            relative = 'next';
        } else if (gunAdi && /\bbu\b/.test(asciiOrig)) {
            relative = 'this'; // "bu salı" → bu haftanın o günü (bugün olabilir)
        }
        if (relative) console.error("Relative:", relative, relOffset ? JSON.stringify(relOffset) : '');

        // 9. TARİH HESAPLA
        const now = new Date();
        let resultDate = null;

        if (relative === 'offset' && relOffset) {
            resultDate = new Date(now);
            if (relOffset.days)   resultDate.setDate(now.getDate() + relOffset.days);
            if (relOffset.months) resultDate.setMonth(now.getMonth() + relOffset.months);
            console.error("Offset tarih:", resultDate);
        }
        else if (gun && ay) {
            // Gün+Ay var
            let year = now.getFullYear();
            if (ay < now.getMonth() + 1 || (ay === now.getMonth() + 1 && gun < now.getDate())) {
                year++;
            }
            resultDate = new Date(year, ay - 1, gun);
            // Geçersiz gün/ay ("31 nisan", "30 şubat") JS'te sonraki aya taşar — bunu NULL yap.
            if (resultDate.getMonth() !== ay - 1 || resultDate.getDate() !== gun) {
                console.error("Gecersiz gun/ay kombinasyonu:", gun, ay, "→ NULL");
                console.log('NULL'); rl.close(); return;
            }
            console.error("Gun+Ay tarih:", resultDate);
        }
        else if (relative === 'day_after_tomorrow') {
            resultDate = new Date(now);
            resultDate.setDate(now.getDate() + 2);
            console.error("Obur gun:", resultDate);
        }
        else if (relative === 'tomorrow' && gunAdi) {
            // "yarın çarşamba" gibi - yarın zaten o gün mü kontrol et
            resultDate = new Date(now);
            resultDate.setDate(now.getDate() + 1);
            console.error("Yarin+Gun:", resultDate);
        }
        else if (relative === 'tomorrow') {
            resultDate = new Date(now);
            resultDate.setDate(now.getDate() + 1);
            console.error("Yarin:", resultDate);
        }
        else if (relative === 'today') {
            resultDate = new Date(now);
            console.error("Bugun:", resultDate);
        }
        else if (relative === 'this' && gunAdi) {
            // "bu <gün>" → bu haftanın o günü (bugün ise bugün)
            resultDate = new Date(now);
            let currentDay = now.getDay();
            if (currentDay === 0) currentDay = 7;
            let dayDiff = ((gunAdi - currentDay) % 7 + 7) % 7; // 0 = bugün
            resultDate.setDate(now.getDate() + dayDiff);
            console.error("Bu gun:", resultDate);
        }
        else if (relative === 'next' && gunAdi) {
            // "gelecek/önümüzdeki <gün>" → GELECEK haftanın o günü (Pazartesi bazlı hafta).
            // Eski kod bugün o güne denk gelince +14 veriyordu; artık her zaman doğru +7'lik hafta.
            resultDate = new Date(now);
            let currentDay = now.getDay();
            if (currentDay === 0) currentDay = 7;
            let toNextMon = (8 - currentDay) % 7;
            if (toNextMon === 0) toNextMon = 7;          // bugün Pazartesi → gelecek Pazartesi +7
            let dayDiff = toNextMon + (gunAdi - 1);
            resultDate.setDate(now.getDate() + dayDiff);
            console.error("Gelecek hafta gun:", resultDate);
        }
        else if (relative === 'next') {
            // Sadece "haftaya" / "gelecek hafta" (gün adı yok)
            resultDate = new Date(now);
            resultDate.setDate(now.getDate() + 7);
            console.error("Gelecek hafta:", resultDate);
        }
        else if (gunAdi) {
            // Sadece gün adı (bu haftanın ilerisi)
            resultDate = new Date(now);
            let currentDay = now.getDay();
            if (currentDay === 0) currentDay = 7;
            let dayDiff = gunAdi - currentDay;
            if (dayDiff <= 0) dayDiff += 7;
            resultDate.setDate(now.getDate() + dayDiff);
            console.error("Sadece gun:", resultDate);
        }
        else if (period || saat !== null) {
            // Sadece zaman belirtilmiş (bugün varsay)
            resultDate = new Date(now);
            console.error("Sadece zaman (bugun):", resultDate);
        }
        else {
            console.log('NULL');
            rl.close();
            return;
        }

        // 10. SAATİ AYARLA
        if (resultDate && !isNaN(resultDate.getTime())) {
            let finalSaat, finalDakika;

            if (saat !== null) {
                finalSaat = saat;
                finalDakika = dakika;

                // Period PM kategorileri
                const pmPeriodleri = ['öğleden sonra', 'ikindi', 'akşam', 'akşamüstü', 'akşam üstü', 'akşamüzeri', 'akşam üzeri'];

                if (pmPeriodleri.includes(period)) {
                    if (finalSaat < 12) finalSaat += 12;
                } else if (period === 'gece' || period === 'gece yarısı') {
                    if (finalSaat === 12) finalSaat = 0;
                    else if (finalSaat < 12) finalSaat += 12;
                } else if (!period && finalSaat < 8) {
                    // Period yok ve saat 8'den küçük → öğleden sonra varsay
                    finalSaat += 12;
                    console.error("Saat 8'den once, ogleden sonraya alindi:", finalSaat);
                }
            } else {
                // Saat belirtilmemiş → period'un default saatini kullan (yoksa 9)
                if (period && tr.periods[period] !== undefined) {
                    finalSaat = tr.periods[period];
                    console.error("Saat yok, period default kullanildi:", period, "->", finalSaat);
                } else {
                    finalSaat = 9;
                }
                finalDakika = 0;
            }

            // Saat aralık dışıysa NULL — setHours(30) ertesi güne geçirebilir, gün kayması olmasın
            if (finalSaat < 0 || finalSaat > 23 || finalDakika < 0 || finalDakika > 59) {
                console.error("Saat aralık dışı:", finalSaat, ":", finalDakika, "→ NULL");
                console.log('NULL');
                rl.close();
                return;
            }

            resultDate.setHours(finalSaat, finalDakika, 0, 0);

            const y = resultDate.getFullYear();
            const m = String(resultDate.getMonth() + 1).padStart(2, '0');
            const d = String(resultDate.getDate()).padStart(2, '0');
            const h = String(resultDate.getHours()).padStart(2, '0');
            const min = String(resultDate.getMinutes()).padStart(2, '0');

            console.log(`${y}-${m}-${d} ${h}:${min}:00`);
        } else {
            console.log('NULL');
        }

    } catch (e) {
        console.error("HATA:", e.message);
        console.log('NULL');
    }
    rl.close();
});