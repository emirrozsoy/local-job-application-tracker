İş arayan arkadaşlarımın ihtiyacından yola çıkarak PHP 8.x ile lokal çalışan bir iş başvuru takip ve otomasyon paneli geliştirdim.

Proje şu an sağlık, klinik ve IVF gibi alanlara odaklanıyor; fakat arama terimleri, API ayarları ve mesaj şablonları değiştirilerek farklı sektörlerdeki başvuru süreçlerine de uyarlanabilecek şekilde tasarlandı.

Panel üzerinden:

- Aday profili ve ön yazı yönetilebiliyor
- CV yolu tanımlanabiliyor
- Google Places altyapısıyla kurum/hedef araştırması yapılabiliyor
- SMTP üzerinden CV ekli e-posta gönderimi yapılabiliyor
- WhatsApp için hazır mesaj linkleri oluşturulabiliyor
- Başvurular “bulundu, başvuruldu, takipte, görüşme, olumsuz” gibi durumlarla takip edilebiliyor
- Hata logları ve gönderim durumları panelden izlenebiliyor

Kullandığım teknolojiler:

- PHP 8.x
- MySQL / PDO
- cURL
- PHPMailer / SMTP
- Opsiyonel Docker Compose + Evolution API

Bu projede benim için en değerli taraf sadece otomatik mail veya WhatsApp mesajı göndermek değil; iş arama sürecini dağınık notlardan çıkarıp takip edilebilir küçük bir CRM mantığına dönüştürmek oldu.

GitHub reposunu kişisel verilerden arındırılmış demo sürüm olarak paylaştım. Gerçek CV, telefon, e-posta, API key, WhatsApp tokenı veya gönderim logları repoda yer almıyor.

GitHub: https://github.com/emirrozsoy/local-job-application-tracker

Geri bildirimlere açığım.
