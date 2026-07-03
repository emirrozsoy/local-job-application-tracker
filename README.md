# Local Job Application Tracker

PHP 8.x ile geliştirilmiş, lokal çalışan bir iş başvuru takip paneli.

Proje; sağlık, klinik, IVF ve benzeri uzmanlaşmış pozisyonlar için aday profili yönetimi, kurum/hedef takibi, SMTP ile CV ekli e-posta gönderimi, manuel WhatsApp takip linkleri ve başvuru durum yönetimi sağlar.

Bu repo public paylaşım için anonimleştirilmiştir. Gerçek CV, gerçek aday bilgileri, telefon/e-posta listeleri, API key, SMTP şifreleri, WhatsApp tokenları, QR görselleri ve gönderim logları repoya dahil değildir.

## Özellikler

- Aday profili ve ön yazı yönetimi
- CV dosya yolu ayarı
- Kurum/hedef ekleme ve takip
- Google Places ile hedef araştırma altyapısı
- Web sitesinden e-posta bulma denemesi
- SMTP ile CV ekli e-posta gönderimi
- Manuel WhatsApp ve Web WhatsApp açma linkleri
- İş ilanı takip listesi
- Başvuru durumları: bulundu, başvuruldu, takipte, görüşme, olumsuz
- Error log görüntüleme sayfası
- Panel üzerinden SMTP, WhatsApp gateway ve test gönderimi ayarları

## Teknolojiler

- PHP 8.x
- MySQL / MariaDB
- PDO
- cURL
- PHPMailer
- Docker Compose ile opsiyonel Evolution API

## Kurulum

1. Projeyi XAMPP `htdocs` altına koy:

   ```bash
   git clone <repo-url> job-application-tracker
   cd job-application-tracker
   ```

2. Composer bağımlılıklarını kur:

   ```bash
   composer install
   ```

3. MySQL tarafında demo veritabanını oluştur:

   ```sql
   CREATE DATABASE job_application_tracker_demo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

4. Gerekirse `config.php` içindeki DB bilgilerini kendi lokal ortamına göre düzenle.

5. Tarayıcıdan aç:

   ```text
   http://localhost/job-application-tracker/
   http://localhost/job-application-tracker/job_applications.php
   ```

## Dosyalar

- `job_applications.php`: ana iş başvuru takip paneli
- `basvuru.php`: kısa giriş dosyası
- `logs.php`: uygulama hata logları
- `src/Repositories`: PDO repository katmanı
- `src/Services`: Google Places, SMTP, WhatsApp gateway ve yardımcı servisler
- `docker/evolution-api`: opsiyonel WhatsApp gateway demo kurulumu

## Güvenlik

Public repo içinde şunlar olmamalıdır:

- Gerçek CV
- Gerçek aday bilgileri
- Gerçek kurum iletişim listeleri
- SMTP şifreleri
- Google API key
- WhatsApp gateway tokenları
- QR oturum görselleri
- Gönderim logları

Bu alanlar `.gitignore` ile dışarıda bırakılmıştır.

## Not

WhatsApp otomatik gönderimlerinde üçüncü parti gateway servisleri mesajları bazen `PENDING` durumunda bırakabilir. Bu yüzden proje güvenli varsayılan olarak manuel WhatsApp açma linkleri sunar. Resmi başvurular için SMTP/e-posta akışı birincil kanal olarak düşünülmüştür.

