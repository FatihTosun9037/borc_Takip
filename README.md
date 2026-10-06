# Borç Takip

Kişisel hesap, borç, ödeme planı ve eğitim amaçlı kredi görünümü. Banka API’si yok; veriler elle veya örnek kayıtla girilir. Sayfalar PHP, stiller CSS, formlar JavaScript.

## Kurulum

1. `.env.example` dosyasını `.env` olarak kopyalayın.
2. `DATA_ENCRYPTION_KEY` için 32 bayt base64 üretin:

```powershell
.tools\php\php.exe -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
```

3. Veritabanını açın:

```powershell
docker compose up -d
```

4. Uygulamayı başlatın:

```powershell
.tools\php\php.exe -S localhost:8080 -t php/public php/public/index.php
```

Adres: http://localhost:8080

Örnek giriş: `demo@borctakip.local` / `Demo1234!`

Yerel veritabanı parolası yalnızca geliştirme içindir (`borc_dev_password`). Şema `php/schema.sql` içindadir. Kimlik ve `updated_at` alanlarının varsayılanı veritabanındadır.

## Güvenlik

- Tüm değişiklikçi işlemler CSRF ile korunur; CSRF anahtarı `HttpOnly` değildir ve yalnızca aynı tarayıcıda tutulur.
- Oturumlar 14 gün, `HttpOnly`, `SameSite=Lax` ve üretim ortamında `Secure` ile korunur.
- Şifrelenmiş IBAN verisi AES-256-GCM ile saklanır; aynı kullanıcıdan başka kullanıcıda erişim mümkün değildir.
- Hızlı sınırlama gerçek `REMOTE_ADDR` üzerinden çalışır; kullanıcıdan gelen `X-Forwarded-For` başlığı kullanmaz.
- PHP üretim modunda hata detayı kapalıdır ve kullanıcı dostu hata mesajı gönderilmezidir.
- `.env` ve doğrulama dosyaları Git tarafından dışlanır.
- Demo hesabı üretim ortamında kullanmayın; gerçek kullanıcılar için ayrı parola policy'si ve e-posta doğrulaması ekleyin.

Kontrollü doğrulama:

```powershell
.tools\php\php.exe -l php/public/index.php
.tools\php\php.exe -l php/src/bootstrap.php
.tools\php\php.exe -l php/src/routes.php
.tools\php\php.exe -l php/src/store.php
.tools\php\php.exe -l php/src/domain.php
.tools\php\php.exe php/bin/check-domain.php
```

Tüm PHP dosyasının sözdizimini ve örnek plan hesaplamasının beklenen sonuçlarını kontrol edin.

## Katmanlar

- `php/public/index.php` yalnızca istekleri karşılar.
- `php/src/routes.php` adresleri doğrular.
- `php/src/store.php` oturum ve sorguları tutar.
- `php/src/domain.php` ödeme planı, faiz ve IBAN şifrelemesini saf fonksiyon olarak hesaplar.
- `php/templates` HTML, `php/public/assets` CSS ve JavaScript.

Yazma istekleri sayfadaki CSRF belirtecini `X-CSRF-Token` başlığında gönderir. Oturum çerezi `httpOnly` kalır.
