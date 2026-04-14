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
        'sabah': 9, 'öğle': 12, 'öğleden sonra': 15, 'akşam': 19,
        'akşamüstü': 17, 'gece': 22, 'öğlen': 12
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
    }
};

/**
 * Metindeki yazıyla yazılmış sayıları rakama çevirir.
 * "on beş mart saat üç buçuk" -> "15 mart saat 3 buçuk"
 */
function wordNumbersToDigits(text) {
    // Önce iki kelimelik sayıları dönüştür (on bir, on iki, ...)
    const twoWordNumbers = Object.entries(tr.wordNumbers)
        .filter(([k]) => k.includes(' '))
        .sort((a, b) => b[0].length - a[0].length); // Uzundan kısaya

    for (const [word, num] of twoWordNumbers) {
        text = text.replace(new RegExp(word, 'g'), String(num));
    }

    // Sonra tek kelimelik sayıları dönüştür
    // "saat" kelimesinden sonra veya aylardan önce gelen sayı kelimelerini dönüştür
    const singleWordNumbers = Object.entries(tr.wordNumbers)
        .filter(([k]) => !k.includes(' '))
        .sort((a, b) => b[0].length - a[0].length);

    for (const [word, num] of singleWordNumbers) {
        // Kelime sınırı kontrolü ile değiştir (kısmi eşleşme olmasın)
        const regex = new RegExp(`(?<![a-zçğıöşü])${word}(?![a-zçğıöşü])`, 'g');
        text = text.replace(regex, String(num));
    }

    return text;
}

const readline = require('readline');
const rl = readline.createInterface({ input: process.stdin, output: process.stdout });

rl.question('', (text) => {
    try {
        let original = text.toLowerCase().trim();

        // DEBUG: Gelen metni yaz
        console.error("GELEN:", original);

        // Yazıyla yazılmış sayıları rakama çevir
        original = wordNumbersToDigits(original);
        console.error("SAYI DONUSUMU:", original);

        let gun = null;
        let ay = null;
        let gunAdi = null;
        let saat = null;
        let dakika = 0;
        let period = null;
        let relative = null;

        // 1. Gün + Ay (5 mart, 15 nisan)
        const gunAyMatch = original.match(/(\d{1,2})\s+(ocak|şubat|mart|nisan|mayıs|haziran|temmuz|ağustos|eylül|ekim|kasım|aralık)/);
        if (gunAyMatch) {
            gun = parseInt(gunAyMatch[1]);
            ay = tr.months[gunAyMatch[2]];
            console.error("Gun+Ay:", gun, ay);
        }

        // 2. Gün adı (çarşamba, cumaya, vs.)
        // Önce varyasyonları kontrol et (daha uzun ve spesifik)
        // Sonra ana gün adlarını kontrol et - uzundan kısaya sırala
        // ("cumartesi" "cuma"dan önce, "pazartesi" "pazar"dan önce kontrol edilmeli)
        const allDayEntries = [
            ...Object.entries(tr.dayVariants),
            ...Object.entries(tr.days)
        ].sort((a, b) => b[0].length - a[0].length);

        for (let [g, val] of allDayEntries) {
            if (original.includes(g)) {
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

        // 4. "buçuk" kontrolü (3 buçuk -> 3:30)
        const bucukMatch = original.match(/(\d{1,2})\s*buçuk/);
        if (bucukMatch && !saatDakikaMatch) {
            saat = parseInt(bucukMatch[1]);
            dakika = 30;
            console.error("Bucuk:", saat, ":30");
        }

        // 5. "saat X" formatı (saat 3, saat 15)
        if (saat === null) {
            const saatMatch = original.match(/saat\s*(\d{1,2})/);
            if (saatMatch) {
                saat = parseInt(saatMatch[1]);
                console.error("Saat (saat X):", saat);
            }
        }

        // 6. Son çare: metindeki son sayıyı saat olarak al
        //    Ama gün+ay zaten bulunduysa, gün sayısını hariç tut
        if (saat === null) {
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

        // 7. Zaman dilimi - "öğleden sonra" önce kontrol edilmeli (uzun ifade)
        if (original.includes('öğleden sonra')) {
            period = 'öğleden sonra';
        } else {
            for (let [p, val] of Object.entries(tr.periods)) {
                if (p !== 'öğleden sonra' && original.includes(p)) {
                    period = p;
                    break;
                }
            }
        }
        if (period) console.error("Zaman dilimi:", period);

        // 8. Relative (bağıl zaman)
        if (original.includes('öbür gün') || original.includes('öbürgün')) {
            relative = 'day_after_tomorrow';
        } else if (original.includes('yarın')) {
            relative = 'tomorrow';
        } else if (original.includes('bugün')) {
            relative = 'today';
        } else if (original.includes('gelecek') || original.includes('önümüzdeki') || original.includes('haftaya')) {
            relative = 'next';
        }
        if (relative) console.error("Relative:", relative);

        // 9. TARİH HESAPLA
        const now = new Date();
        let resultDate = null;

        if (gun && ay) {
            // Gün+Ay var
            let year = now.getFullYear();
            if (ay < now.getMonth() + 1 || (ay === now.getMonth() + 1 && gun < now.getDate())) {
                year++;
            }
            resultDate = new Date(year, ay - 1, gun);
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
        else if (relative === 'next' && gunAdi) {
            // Gelecek/önümüzdeki + gün adı
            resultDate = new Date(now);
            let currentDay = now.getDay();
            if (currentDay === 0) currentDay = 7;
            let dayDiff = gunAdi - currentDay;
            if (dayDiff <= 0) dayDiff += 7;
            dayDiff += 7; // "gelecek" = +7 gün ekstra
            resultDate.setDate(now.getDate() + dayDiff);
            console.error("Gelecek+Gun:", resultDate);
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
            let finalSaat = saat !== null ? saat : 9;
            let finalDakika = dakika;

            // Saat 8'den önceyse ve zaman dilimi belirtilmemişse öğleden sonra varsay
            if (finalSaat < 8) {
                if (period === null || period === 'öğleden sonra' || period === 'akşam' || period === 'akşamüstü') {
                    finalSaat = finalSaat + 12;
                    console.error("Saat 8'den once, ogleden sonraya alindi:", finalSaat);
                }
            } else if (finalSaat > 20) {
                finalSaat = 20;
                finalDakika = 0;
                console.error("Gece saati, 20:00'e alindi");
            }

            // Zaman dilimi varsa override et
            if (period) {
                if ((period === 'öğleden sonra' || period === 'akşam' || period === 'akşamüstü') && finalSaat < 12) {
                    finalSaat += 12;
                } else if (period === 'gece') {
                    if (finalSaat === 12) finalSaat = 0;
                    else if (finalSaat < 12) finalSaat += 12;
                }
            }

            // Çalışma saati sınırları
            if (finalSaat > 20) { finalSaat = 20; finalDakika = 0; }
            if (finalSaat < 8) { finalSaat = 9; finalDakika = 0; }

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
