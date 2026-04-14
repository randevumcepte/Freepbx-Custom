const fs = require("fs");
const path = require("path");
const { SpeechClient } = require("@google-cloud/speech");
const { Transform } = require("stream");

// Google Cloud Speech Client oluşturma
const client = new SpeechClient({
    keyFilename: "/var/lib/asterisk/agi-bin/sesli-yanit/gcp-key.json" // GCP JSON anahtar dosyasının yolu
});

// PCM veriyi Google Speech API'ye göndermek için dönüşüm
async function transcribeAudio(filePath) {
    const audioBytes = fs.readFileSync(filePath).toString("base64");

    const request = {
        audio: {
            content: audioBytes,
        },
        config: {
            encoding: "LINEAR16",
            sampleRateHertz: 8000,
            languageCode: "tr-TR",
            audioChannelCount: 1,
            model: "telephony",          // Telefon hattı için optimize edilmiş model
            useEnhanced: true,           // Gelişmiş model kullan (daha doğru sonuç)
            enableWordTimeOffsets: true,
            enableAutomaticPunctuation: true,
            enableWordConfidence: true,  // Her kelime için güven skoru al
            maxAlternatives: 3,          // Birden fazla alternatif al (post-processing için)
            speechContexts: [
                {
                    // Evet/Hayır ve sık kullanılan kısa yanıtlar
                    phrases: [
                        "evet","hayır","olur","olmaz","tamam","elbette","tabii","tabii ki",
                        "istiyorum","istemiyorum","onaylıyorum","iptal","lütfen","teşekkürler",
                        "tabi","neden olmasın","peki","kabul","red","memnuniyetle"
                    ],
                    boost: 15.0
                },
                {
                    // Tarih ve zaman ifadeleri
                    phrases: [
                        "bugün","yarın","öbür gün","gelecek hafta","önümüzdeki hafta","haftaya",
                        "pazartesi","salı","çarşamba","perşembe","cuma","cumartesi","pazar",
                        "ocak","şubat","mart","nisan","mayıs","haziran",
                        "temmuz","ağustos","eylül","ekim","kasım","aralık",
                        "sabah","öğle","öğleden sonra","akşam","akşamüstü",
                        "bir","iki","üç","dört","beş","altı","yedi","sekiz","dokuz","on",
                        "on bir","on iki","on üç","on dört","on beş","on altı","on yedi","on sekiz","on dokuz","yirmi",
                        "buçuk","saat","randevu"
                    ],
                    boost: 12.0
                },
                {
                    // Hizmet adları
                    phrases: ["Fön","Saç Bakımı","Saç Boyama","Gelin Başı","Topuz","Brezilya Fönü","Afrika Örgüsü","Keratin Bakım","Maşa","Nişan Saçı","Ombre","Sakal Tıraşı","Protez Tırnak","Batık Tırnak","Jel Tırnak Bakım","Lazer Epilasyon","Saç Kesimi","Çocuk Tıraşı","Damat Tıraşı","Kırık Fön","Sombre","Örgü","Perma","Röfle ve Gölge","Saç Botoksu","Saç Dip Boyama","Saç Kaynağı","Saç Renk Değişimi","Saç Simülasyonu","Saç Yıkama","Manikür","Pedikür","Bakım Manikürü","Jel Tırnak Full Set","Kalıcı Oje","Keratinli Manikür","Mantar Tedavisi","Spa Manikür","Spa Pedikür","Kaş Alma","Dudak Üstü","Profesyonel Makyaj","Kaş Kontürü","İpek Kirpik","Kirpik Lifting","Kirpik Perması","Kaş Boyama","Kirpik Boyama","Bb Glow","Brow Lift","Gelin Makyajı","Microblading","Pudralama","Dermapen","Dermaroller","Dudak Dolgusu","Hifu","Karboksi Terapi","Kimyasal Peeling","PRP Uygulaması","Saç Ekimi","Saç Mezoterapisi","Yüz Mezoterapisi","Ağda","Solaryum","Cilt Bakımı","Anti-Aging","Hydrafacial","Kavitasyon","Klasik Cilt Bakımı","Masaj","Klasik Masaj","Thai Masajı","Spor Masajı","Sıcak Taş Masajı","Derin Doku Masajı","Refleksoloji Masajı","Pilates","Yoga","Fitness","Hamam","Sauna","Jakuzi","Vacu Slim Line","Vacu Slimline","Vaku Slim Line"],
                    boost: 18.0
                }
            ]
        },
    };

    try {
        const [response] = await client.recognize(request);

        if (!response.results || response.results.length === 0) {
            console.log(JSON.stringify({ success: false, error: "Ses algılanamadı" }));
            return;
        }

        // En yüksek güvenli alternatifi seç
        let bestTranscript = "";
        let bestConfidence = 0;
        let allAlternatives = [];

        for (const result of response.results) {
            for (const alt of result.alternatives) {
                allAlternatives.push({
                    transcript: alt.transcript,
                    confidence: alt.confidence || 0
                });
                if ((alt.confidence || 0) > bestConfidence) {
                    bestConfidence = alt.confidence || 0;
                    bestTranscript = alt.transcript;
                }
            }
        }

        // Birincil transkript (en yüksek güvenli)
        if (!bestTranscript && allAlternatives.length > 0) {
            bestTranscript = allAlternatives[0].transcript;
        }

        // Kelime güven skorlarını logla (debug amaçlı)
        const firstResult = response.results[0];
        if (firstResult.alternatives[0].words) {
            const lowConfWords = firstResult.alternatives[0].words
                .filter(w => w.confidence < 0.7)
                .map(w => `${w.word}(${(w.confidence * 100).toFixed(0)}%)`);
            if (lowConfWords.length > 0) {
                console.error("Düşük güvenli kelimeler: " + lowConfWords.join(", "));
            }
        }

        console.log(JSON.stringify({
            success: true,
            transcription: bestTranscript,
            confidence: bestConfidence,
            alternatives: allAlternatives.slice(0, 3)
        }));
    } catch (error) {
        console.error("STT Hatası:", error.message);
        console.log(JSON.stringify({ success: false, error: error.message }));
    }
}

// Ses dosyası yolunu al
const filePath = process.argv[2];
transcribeAudio(filePath);
