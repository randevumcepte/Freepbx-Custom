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
    periods: {
        'sabah': 9, 'öğle': 12, 'öğleden sonra': 15, 'akşam': 19, 'gece': 22
    }
};

const readline = require('readline');
const rl = readline.createInterface({ input: process.stdin, output: process.stdout });

rl.question('', (text) => {
    try {
        let original = text.toLowerCase().trim();
        
        // DEBUG: Gelen metni yaz
        console.error("🔍 GELEN:", original);
        
        let gun = null;
        let ay = null;
        let gunAdi = null;
        let saat = null;
        let period = null;
        let relative = null;

        // 1. Gün + Ay (5 mart)
        const gunAyMatch = original.match(/(\d{1,2})\s+(ocak|şubat|mart|nisan|mayıs|haziran|temmuz|ağustos|eylül|ekim|kasım|aralık)/);
        if (gunAyMatch) {
            gun = parseInt(gunAyMatch[1]);
            ay = tr.months[gunAyMatch[2]];
            console.error("✅ Gün+Ay:", gun, ay);
        }

        // 2. Gün adı (çarşamba)
        for (let [g, val] of Object.entries(tr.days)) {
            if (original.includes(g)) {
                gunAdi = val;
                console.error("✅ Gün adı:", g, "->", gunAdi);
                break;
            }
        }

        // 3. Saat (son sayı)
        const numbers = original.match(/\d{1,2}/g);
        if (numbers && numbers.length > 0) {
            saat = parseInt(numbers[numbers.length - 1]);
            console.error("✅ Saat:", saat);
        }

        // 4. Zaman dilimi
        for (let [p, val] of Object.entries(tr.periods)) {
            if (original.includes(p)) {
                period = p;
                console.error("✅ Zaman dilimi:", period);
                break;
            }
        }

        // 5. Relative
        if (original.includes('gelecek') || original.includes('önümüzdeki') || original.includes('haftaya')) {
            relative = 'next';
        }
        if (original.includes('yarın')) relative = 'tomorrow';
        if (original.includes('bugün')) relative = 'today';
        if (relative) console.error("✅ Relative:", relative);

        // 6. TARİH HESAPLA
        const now = new Date();
        let resultDate = null;

        if (gun && ay) {
            // Gün+Ay var
            let year = now.getFullYear();
            if (ay < now.getMonth() + 1 || (ay === now.getMonth() + 1 && gun < now.getDate())) {
                year++;
            }
            resultDate = new Date(year, ay - 1, gun);
            console.error("✅ Gün+Ay tarih:", resultDate);
        }
        else if (relative && gunAdi) {
            // Relative + gün adı
            resultDate = new Date(now);
            if (relative === 'tomorrow') {
                resultDate.setDate(now.getDate() + 1);
            } else if (relative === 'next') {
                resultDate.setDate(now.getDate() + 7);
            }
            
            let currentDay = resultDate.getDay();
            if (currentDay === 0) currentDay = 7;
            let dayDiff = gunAdi - currentDay;
            if (dayDiff < 0) dayDiff += 7;
            resultDate.setDate(resultDate.getDate() + dayDiff);
            console.error("✅ Relative+Gün:", resultDate);
        }
        else if (gunAdi) {
            // Sadece gün adı
            resultDate = new Date(now);
            let currentDay = now.getDay();
            if (currentDay === 0) currentDay = 7;
            let dayDiff = gunAdi - currentDay;
            if (dayDiff <= 0) dayDiff += 7;
            resultDate.setDate(now.getDate() + dayDiff);
            console.error("✅ Sadece gün:", resultDate);
        }
        else if (period || saat) {
            // Sadece zaman
            resultDate = new Date(now);
            console.error("✅ Sadece zaman (bugün):", resultDate);
        }
        else {
            console.log('NULL');
            rl.close();
            return;
        }

        // 7. SAATİ AYARLA (ÇALIŞMA SAATLERİNE GÖRE DÜZELT)
        if (resultDate && !isNaN(resultDate.getTime())) {
            let finalSaat = saat !== null ? saat : 9;
            
            // 🚨 EĞER SAAT 8'DEN ÖNCEYSE VEYA 20'DEN SONRAYSA DÜZELT
            if (finalSaat < 8) {
                // Sabah 8'den önce söylenen saat (örn: 2, 3, 4...)
                if (period === null) {
                    // Zaman dilimi belirtilmemişse, öğleden sonra varsay
                    finalSaat = finalSaat + 12;
                    console.error("⏰ Saat 8'den önce, öğleden sonraya alındı:", finalSaat);
                }
            } else if (finalSaat > 20) {
                // Gece saatleri (21, 22, 23...) çalışma saatine düşür
                finalSaat = 20;
                console.error("⏰ Gece saati, 20:00'e alındı");
            }
            
            // Zaman dilimi varsa override et
            if (period) {
                if (period === 'öğleden sonra' || period === 'akşam') {
                    if (finalSaat < 12) finalSaat += 12;
                } else if (period === 'gece') {
                    if (finalSaat === 12) finalSaat = 0;
                    else if (finalSaat < 12) finalSaat += 12;
                }
            }
            
            // 20:00'den sonraysa 20:00 yap
            if (finalSaat > 20) finalSaat = 20;
            // 08:00'den önceyse 09:00 yap (varsayılan)
            if (finalSaat < 8) finalSaat = 9;
            
            resultDate.setHours(finalSaat, 0, 0, 0);
            
            const y = resultDate.getFullYear();
            const m = String(resultDate.getMonth() + 1).padStart(2, '0');
            const d = String(resultDate.getDate()).padStart(2, '0');
            const h = String(resultDate.getHours()).padStart(2, '0');
            
            console.log(`${y}-${m}-${d} ${h}:00:00`);
        } else {
            console.log('NULL');
        }

    } catch (e) {
        console.error("❌ HATA:", e.message);
        console.log('NULL');
    }
    rl.close();
});