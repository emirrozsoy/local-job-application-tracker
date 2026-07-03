PHP 8.x ile lokal çalışan bir iş başvuru takip paneli geliştirdim.

Proje; sağlık, klinik ve IVF gibi daha niş pozisyonlara başvuru sürecini tek panelden yönetmek için tasarlandı. Aday profili, ön yazı, CV yolu, kurum/hedef listesi, SMTP ile e-posta gönderimi, manuel WhatsApp takip linkleri ve başvuru durum takibi aynı ekranda yönetilebiliyor.

Kullandığım teknolojiler:

- PHP 8.x
- MySQL / PDO
- cURL
- PHPMailer / SMTP
- Opsiyonel Docker Compose + Evolution API

Bu projede benim için en öğretici kısım sadece “mail gönderme” otomasyonu değildi. Asıl değer; başvuruldu, takipte, görüşme, olumsuz gibi durumları kaybetmeden izleyebilmek ve lokal çalışan küçük bir CRM mantığı kurabilmekti.

GitHub reposunu kişisel verilerden arındırılmış demo sürüm olarak paylaştım. Gerçek CV, telefon, e-posta, API key, WhatsApp tokenı veya gönderim logları repoda yer almıyor.

Repo: [GitHub linkini buraya ekle]

Geri bildirimlere açığım.

