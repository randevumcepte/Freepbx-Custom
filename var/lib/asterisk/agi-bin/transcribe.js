const fs = require("fs");
const path = require("path");
const { Transform } = require("stream");
const { SpeechClient } = require("@google-cloud/speech");

// Google Cloud Speech Client oluşturma
const client = new SpeechClient({
    keyFilename: "/var/lib/asterisk/agi-bin/sesli-yanit/gcp-key.json" // GCP JSON anahtar dosyasının yolu
});

// PCM veriyi Google Speech API'ye göndermek için dönüştürme
async function transcribeAudio(filePath) {
    const audioBytes = fs.readFileSync(filePath).toString("base64");

    const request = {
        audio: { content: audioBytes },
        config: {
		model: "phone_call",
		enableAutomaticPunctuation: true,
           encoding: "LINEAR16",
	/*	encoding:"FLAC",*/
            sampleRateHertz: 8000,
            languageCode: "tr-TR", // Türkçe dil kodu
	   speechContexts: [
                {
                    phrases: ["Önümüzdeki","hafta","saat","gün","ay","Fön","Saç Bakımı","Saç Boyama","Gelin Başı (Tesettür)","Gelin Başı","Topuz","Brezilya Fönü","Afrika Örgüsü","Keratin Bakım","Maşa","Nişan Saçı","Ombre","Sakal Tıraşı","Protez Tırnak","Batık Tırnak","Jel Tırnak Bakım","Lazer Epilasyon","Ayak Tırnak Şekillendirme","El Tırnak Şekillendirme","Saç Kesimi","Çocuk Tıraşı","Damat Tıraşı","Kırık Fön","Sombre","Örgü","Perma","Röfle ve Gölge","Saç Botoksu","Saç Dip Boyama","Saç Kaynağı","Saç Renk Değişimi","Saç Simülasyonu","Saç Yıkama","Manikür","Pedikür","Bakım Manikürü","Haftalık Oje","Jel Ayak Tek Parmak","Jel El Tek Parmak","Jel French Bakımı","Jel French Full Set","Jel Set Çıkarma","Jel Tırnak Full Set","Kalıcı Oje Ayak","Kalıcı Oje Çıkarma","Kalıcı Oje El","Kalıcı Oje İlave Hizmet","Kalıcı Oje Tırnak Aksesuar Tek Parmak","Kalıcı Oje Tırnak Dizayn Tek Parmak","Kalıcı Oje Tırnak Dizayn Tüm El","Keratinli Manikür","Mantar Tedavisi","Naturel Bakım Ayak","Naturel Bakım El","Smothing Jel","Spa Manikür","Spa Pedikür","Sütlü Manikür","Tırnak Şekillendirme Bakım","Kaş Alma","Dudak Üstü","Profesyonel Makyaj","Kaş Kontürü","İpek Kirpik","3D İpek Kirpik","Oje Uygulama","Kirpik Lifting","Kirpik Perması","Kaş Boyama","Kirpik Boyama","Bb Glow","Brow Lift","Dudak Renklendirme","Dudak Silme","Gece Makyajı","Gelin Makyajı","Gündelik Makyaj","Haftalık Kirpik","İpek Kirpik Bakımı","Kalıcı Ben","Kalıcı Dip Liner","Kalıcı Dudak","Kalıcı Eyeliner","Kalıcı Kaş","Kalıcı Kaş Bakımı","Kalıcı Kaş Dizaynı","Kalıcı Kaş Hattı Çizme","Kalıcı Yarım Kaş Dizaynı","Kaş Gölgesi","Kaş Laminasyonu","Kaş Lifting","Kaş Silme","Kaş Vitamini","Kaynak Kirpik Bakım","Kaynak Kirpik Çıkarma","Kaynak Kirpik Full Set","Microblading","Pudralama","Bölgesel Dolgu","Cazmara Maske","Çatlak Onarımı","Dermapen","Dermaroller","Dudak Dolgusu","Elektrostimülasyon","Endopeel","Gıdı Toparlama","Göğüs Toparlama","Göz Altı Morluğu","Hifu","İğneli Epilasyon (Vücut)","İğneli Epilasyon (Yüz)","Karboksi Terapi","Kimyasal Peeling","Leke Tedavisi","Lipoliz","Medikal Masaj","Örümcek Ağı","Pasif Jimnastik","Pop-Up","PRP Uygulaması","Rasping","Saç Ekimi","Saç Mezoterapisi","Soğuk Lipoliz","Somon DNA Aşısı","TCA","Terleme Botoksu","Varis Tedavisi (Nd Yag Damar Tedavisi)","Yüz Mezoterapisi","Cazmara Maske","Ağda (Bölgesel)","Ağda (Tüm Vücut)","Solaryum","Cilt Bakımı","Akupresür Masajı","Anti-Aging","Antioksidan Bakım","Aqua peel","Aquashine","Arındırıcı ve Yenileyici Akne Bakımı","Ayak Detoksu","Bal-Süt Bakımı","Botoks Bakımı","Bölgesel İncelme","Cilt ve Vücut Bakımı","Cilt Yenileme / Dermaterapi","Deluxe Bakım","Deniz Tuzu ile Peeling","Dudak Bakımı","Gençleştirici Bakım","Göğüs Bakımı","Göz Bakımı","Hassas Cilt Bakımı","Havyar Bakımı","Holistik Bakımlar","Hydrafacial","Kahve Bakımı","Kahve Peelingi","Kavitasyon","Klasik Cilt Bakımı","Lazer Epilasyon (Bacak)","Lazer Epilasyon (Bikini Bölgesi)","Lazer Epilasyon (Boyun)","Lazer Epilasyon (Çene)","Lazer Epilasyon (Dudak Üstü)","Lazer Epilasyon (Ense)","Lazer Epilasyon (Göğüs)","Lazer Epilasyon (Kol)","Lazer Epilasyon (Koltuk Altı)","Lazer Epilasyon (Sırt)","Lazer Epilasyon (Tüm Vücut)","Lazer Epilasyon (Yüz)","Q switch lazer","Yarım bacak","Yarım kol","Masaj","Aloe Vera Masajı","Anti-Selülit Masajı","Anti-Stress Masajı","Aroma Terapi Masajı","Avurveda Abhyanga Masajı","Avurveda Shiroabhyanga Masajı","Avurveda Shirodara Masajı","Ayak Reflex Masajı","Bölgesel Masaj","Çikolata Masajı","Derin Asya Masajı","Derin Doku Masajı","Dört El Masajı","G5 Masajı","Geleneksel Bali Masajı","Geleneksel Thai Masajı","Hint Baş Masajı","İsveç Masajı","Jumbaram 4 El Masajı","Kese & Köpük Masajı","Klasik Masaj","Kleopatra Masajı","Kleopatra Masajı (Deniz Yosunu İle)","Köpük Masajı","Lenf Drenaj Masajı","Lomi Lomi Masajı","Mandara 4 El Masajı","Manuel Terapi Thai Masajı","Nefertiti Masajı","Osmanlı Masajı","Refleksoloji Masajı","Sıcak Taş Masajı","Spor Masajı","Thai Masajı","Geçici Dövme","Dövme Silme","Kalıcı Dövme","Kulak Delimi","Piercing","Cardio","Fitness","Kick Box","Pilates","Yoga","Kilo Kontrolü","Sağlıklı Beslenme","Vücut Analizi","Buhar Odası","Gelin Hamamı","Hamam","Jakuzi","Sauna","Tuz Odası","Yüzme Havuzu","Koltuk Altı Peeling","Lazer Epilasyon (Göbek)","Lazer Epilasyon (Genital)","Yosun Peeling","Lazer Epilasyon (Popo Üstü)"], // Öncelikli kelimeler
                    boost: 20.0 // Kelimeye öncelik verme (10-20 arası iyi çalışır)
                }
            ]
        },
    };

    try {
        const [response] = await client.recognize(request);
        const transcription = response.results
            .map(result => result.alternatives[0].transcript)
            .join("\n");
        console.log(JSON.stringify({ success: true, transcription })); // Başarılı çıktı
    } catch (error) {
        console.error("Detailed Error:", error); // Tüm hata nesnesini logla
        console.error(JSON.stringify({ success: false, error: error.message })); // Hata mesajını JSON formatında döndür
    }
}

// Ses dosyası yolunu al
const filePath = process.argv[2];
transcribeAudio(filePath);
