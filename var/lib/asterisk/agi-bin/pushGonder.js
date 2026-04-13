// send_push.js
const apn = require("apn");
const fs = require("fs");

// Değiştirilecek yerler
const TEAM_ID = "24DZ882DDK"; // Apple Developer panelinden
const KEY_ID = "SVN64Y89VU"; // .p8 dosyasının ismindeki Key ID
const BUNDLE_ID = "com.webfirmam.randevumcepte"; // iOS uygulama bundle ID'si
const DEVICE_TOKEN = "KULLANICININ_DEVICE_TOKEN_IOS"; // Kullanıcıya ait APNs token

// .p8 dosyasının tam yolu
const PRIVATE_KEY_PATH = "/opt/apns-push/AuthKey_SVN64Y89VU.p8";

// JWT tabanlı sertifikayla provider oluştur
let apnProvider = new apn.Provider({
  token: {
    key: fs.readFileSync(PRIVATE_KEY_PATH),
    keyId: KEY_ID,
    teamId: TEAM_ID,
  },
  production: false, // true: App Store'a yüklenmiş uygulama; false: geliştirme aşamasında
});

// CLI'dan gelen numara (JSON POST simülasyonu gibi)
const input = process.argv[2];
if (!input) {
  console.error("Numara eksik: node send_push.js '{\"number\": \"1001\"}'");
  process.exit(1);
}
const data = JSON.parse(input);

// Bildirim oluştur
let notification = new apn.Notification({
  alert: `Yeni çağrı: ${data.number}`,
  sound: "default",
  topic: BUNDLE_ID,
});

apnProvider.send(notification, DEVICE_TOKEN).then(response => {
  console.log("Bildirim sonucu:", response.sent.length ? "Başarılı" : "Hata", response);
  apnProvider.shutdown();
});

