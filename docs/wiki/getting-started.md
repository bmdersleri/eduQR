# eduQR'yi yerelde çalıştırma

Bu öğretici sonunda Docker ile çalışan bir eduQR kurulumu, uygulanmış veritabanı şeması ve ilk yönetici hesabınız olur.

## Gereksinimler

- Git
- Docker Engine ve Docker Compose
- Kullanılabilir `8080` portu

Ana makinede PHP veya MySQL kurulu olması gerekmez. Yerel sırları `.env.docker` içinde tutun ve bu dosyayı sürüm kontrolüne eklemeyin.

## 1. Depoyu ve yerel yapılandırmayı hazırlayın

```bash
git clone https://github.com/bmdersleri/eduQR.git
cd eduQR
cp .env.docker.example .env.docker
```

`.env.docker` içinde `DB_PASS` ile `MYSQL_PASSWORD` değerlerini aynı yapın; ayrıca `MYSQL_ROOT_PASSWORD` ve `APP_SECRET` için yerel, benzersiz değerler belirleyin.

## 2. Uygulamayı başlatın

```bash
docker compose up -d
```

Bu komut PHP/Apache ve MySQL konteynerlerini başlatır, veritabanının hazır olmasını bekler ve bekleyen migrasyonları uygular. Tarayıcıda `http://localhost:8080` adresini açın. Sağlık denetimi `http://localhost:8080/api/v1/health` adresinde başarılı bir JSON yanıtı vermelidir.

## 3. İlk hesabı oluşturun

```bash
docker compose exec app php bin/user-add.php \
  --email=you@example.org \
  --name="Your Name" \
  --role=admin \
  --lang=tr
```

Komut parolayı etkileşimli ister. Parola 10–128 karakter olmalı ve küçük harf, büyük harf, rakam, sembol gruplarından en az üçünü içermelidir.

## 4. Oturum açıp ilk sınıf akışını deneyin

1. `http://localhost:8080/login` adresinde oluşturduğunuz hesapla oturum açın.
2. Bir ders oluşturun.
3. Ders altında yeni bir oturum başlatın.
4. Üretilen `/live/{short_code}` görünümünü yeni sekmede açın.
5. Gizli pencerede `/join/{short_code}` adresine gidip bir öğrenci takma adıyla katılın.
6. Eğitmen ekranında bir soru oluşturup etkinleştirin ve öğrenci ekranından yanıtlayın.

## 5. Kurulumu doğrulayın

```bash
docker compose exec app composer test
docker compose exec app composer lint
docker compose exec app php bin/locale-check.php tr --min=95
```

Testler ve lint sıfır çıkış koduyla bitmeli; Türkçe anahtar kapsamı en az `%95` olmalıdır.

## Sık karşılaşılan sorunlar

| Belirti | Kontrol |
| --- | --- |
| Uygulama konteyneri başlamıyor | `docker compose logs app` ile migrasyon veya yapılandırma hatasını inceleyin. |
| Veritabanına bağlanılamıyor | `DB_*` ve `MYSQL_*` adlarının ve parolalarının eşleştiğini doğrulayın. |
| Güvenli çerezler yerel HTTP'de çalışmıyor | Yalnız yerel geliştirmede `COOKIE_SECURE=false` deneyin; üretimde daima `true` kullanın. |
| QR üretilemiyor | Uygulama imajında GD uzantısının yüklendiğini doğrulayın. |
| `8080` portu kullanımda | `docker-compose.yml` port eşlemesini boş bir yerel porta alın ve `APP_URL` değerini güncelleyin. |

## Sonraki adımlar

- Bir dersi uçtan uca yürütmek için [kullanım rehberini](user-guide.md) izleyin.
- Kod değişikliği yapacaksanız [geliştirici rehberini](developer-guide.md) okuyun.
- cPanel kurulumu için [deploy/cpanel-notes.md](../../deploy/cpanel-notes.md), Ubuntu/Nginx için `php bin/wizard.php` kullanın.

