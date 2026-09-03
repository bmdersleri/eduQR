# eduQR teknik referansı

Bu sayfa sık kullanılan teknik bilgilerin kısa dizinidir. Ayrıntılı ve bağlayıcı sözleşmeler için kök spesifikasyonlara gidin.

## Çalışma zamanı ve bağımlılıklar

| Alan | Değer |
| --- | --- |
| PHP | 8.2 veya üzeri |
| Veritabanı | MySQL 8.0+ / MariaDB 10.6+, InnoDB, `utf8mb4_unicode_ci` |
| Arka uç | Framework içermeyen PHP, özel Router |
| Ön yüz | Sunucu tarafında PHP, Bootstrap 5, Vanilla ES2022 |
| Canlı güncelleme | HTTP polling ve `ETag`/`304` yeniden doğrulaması |
| Grafik / QR | Chart.js / `endroid/qr-code` |
| Test / stil | PHPUnit 11 / PHP-CS-Fixer, PSR-12 |

## Domain sabitleri

| Konu | Değerler |
| --- | --- |
| Kullanıcı rolleri | `admin`, `instructor` |
| Oturum durumları | `draft`, `active`, `paused`, `closed` |
| Soru durumları | `draft`, `active`, `closed` |
| Soru türleri | `multiple_choice`, `open_text`, `yes_no`, `likert_5`, `fill_in_the_blank` |
| Tepkiler | `got_it`, `lost` |
| Kısa kod | 6 karakter; `A-H J-N P-Z 2-9` |
| Varsayılan diller | `en`, `tr`; fallback `en` |

## Web rotaları

| Alan | Rotalar |
| --- | --- |
| Genel | `/`, `/privacy`, `/login`, `/forgot-password`, `/reset-password/{token}` |
| Yönetim | `/admin/dashboard`, `/admin/courses`, `/admin/courses/new`, `/admin/courses/{id}`, `/admin/courses/{id}/edit`, `/admin/courses/{id}/analytics` |
| Oturum yönetimi | `/admin/courses/{id}/sessions/new`, `/admin/sessions/{id}`, `/admin/sessions/{id}/results`, `/admin/sessions/{id}/report` |
| Öğrenci | `/join/{short_code}`, `/join/{short_code}/wait`, `/play/{short_code}`, `/play/{short_code}/batch`, `/play/{short_code}/answered` |
| Projeksiyon | `/live/{short_code}`, `/live/{short_code}/results` |
| Denetim | `/admin/audit-logs` |

HTML rotaları isteğe bağlı `/en/` veya `/tr/` öneki kabul eder. API rotalarında locale öneki kullanılmaz.

## API rota grupları

Tüm JSON yolları `/api/v1` tabanındadır.

| Grup | Yöntem ve yol |
| --- | --- |
| Sistem | `GET /health`, `GET /locales` |
| Kimlik | `POST /auth/login`, `POST /auth/logout`, `GET /auth/me`, `POST /auth/password-reset/request`, `POST /auth/password-reset/confirm` |
| Dersler | `GET/POST /courses`, `GET/PATCH/DELETE /courses/{id}`, `POST /courses/{id}/restore`, `GET /courses/{id}/analytics` |
| Ders eğitmenleri | `GET/POST /courses/{id}/instructors`, `DELETE /courses/{id}/instructors/{userId}` |
| Oturumlar | `POST /courses/{id}/sessions`, `GET/PATCH/DELETE /sessions/{id}`, `POST /sessions/{id}/pause`, `/resume`, `/close`, `/anonymize` |
| Oturum yardımcıları | `GET /sessions/{id}/qr.png`, `/participants/count`, `/results`, `/reactions` |
| Sorular | `GET/POST /sessions/{id}/questions`, `PATCH/DELETE /questions/{id}`, `POST /questions/{id}/activate`, `/close` |
| Soru araçları | `POST /sessions/{id}/questions/reorder`, `/questions/import`, `POST/DELETE /questions/{id}/image`, `GET /questions/{id}/themes` |
| Soru bankası | `GET /courses/{id}/question-bank`, `POST /courses/{id}/question-bank/generate`, `POST /questions/{id}/bank`, `POST /sessions/{id}/questions/from-bank` |
| Genel öğrenci | `GET /public/sessions/{short_code}`, `POST /sessions/{short_code}/join`, `GET /sessions/{short_code}/active-question`, `/student-results` |
| Yanıt ve tepki | `POST /answers`, `POST /reactions`, `POST /answers/{id}/hide`, `/unhide` |
| Raporlar | `GET /sessions/{id}/report`, `/report.csv`, `/report.html`, `/report.pdf`, `/questions.gift.txt`, `/gradebook.csv` |
| Denetim | `GET /audit-logs` |

Başarı zarfı `{ "success": true, "data": ... }`; hata zarfı `{ "success": false, "error": { "code", "message", "field?" } }` biçimindedir. Zamanlar ISO-8601 UTC'dir. Tam istek, yanıt ve hata listesi [API_SPEC.md](../../API_SPEC.md) içindedir.

## Polling aralıkları

| Değişken | Varsayılan | Tüketici |
| --- | ---: | --- |
| `POLL_INTERVAL_INSTRUCTOR_MS` | 2000 ms | Eğitmen sonuç ekranı |
| `POLL_INTERVAL_INSTRUCTOR_SESSION_MS` | 5000 ms | Eğitmen oturum ayrıntısı |
| `POLL_INTERVAL_STUDENT_MS` | 3000 ms | Öğrenci bekleme/yanıtlandı ekranı |
| `POLL_INTERVAL_PROJECTOR_MS` | 3000 ms | Projeksiyon sonuç ekranı |

## Veri modeli

| Tablo | Amaç |
| --- | --- |
| `users` | Eğitmen ve yönetici hesapları |
| `courses`, `course_instructors` | Ders sahipliği ve yardımcı eğitmen erişimi |
| `sessions` | Sınıf oturumu, kısa kod, görünürlük ve yaşam döngüsü |
| `questions`, `options` | Oturum soruları ve seçenekleri |
| `question_bank_items` | Derse bağlı yeniden kullanılabilir soru şablonları |
| `participants` | Oturuma bağlı anonim öğrenci katılımı |
| `answers`, `question_reactions` | Yanıtlar ve kavrama tepkileri |
| `audit_logs`, `login_attempts`, `password_resets` | Güvenlik ve operasyon kayıtları |
| `locales`, `schema_migrations` | Dil metadatası ve migrasyon geçmişi |

Sık kullanılan sorguların indeksleri kısa kod çözme, aktif soru bulma, yanıt ekleme, sonuç toplama, takma ad benzersizliği ve tek yanıt/tepki kurallarını destekler. Migrasyonlar asıl kaynaktır; `database/schema.sql` kümülatif görünümü taşır.

## Çerezler

| Ad | Amaç | Temel özellik |
| --- | --- | --- |
| `eduqr_session` | Eğitmen PHP oturumu | HttpOnly, Secure, SameSite=Lax, 12 saat kayar |
| `eduqr_participant` | Öğrenci oturum bağı | HttpOnly, Secure, SameSite=Lax |
| `eduqr_device` | Geri dönüş/tekrar katılım karması | HttpOnly, Secure, SameSite=Lax, 1 yıl |
| `eduqr_locale` | Dil tercihi | Secure, SameSite=Lax, 1 yıl |
| `eduqr_csrf` | Double-submit CSRF | Secure, SameSite=Strict |

## Yapılandırma grupları

`.env.example` şu grupları tanımlar: `APP_*`, `DB_*`, `SESSION_*`, `COOKIE_*`, `POLL_INTERVAL_*`, saklama süreleri, takma ad sınırları, login oran sınırı, log/yedek yolları, posta ve demo hesabı. `.env` ile `.env.docker` sürüm kontrolüne girmez. Üretimde `APP_ENV=production`, `APP_DEBUG=false`, `COOKIE_SECURE=true` ve HTTPS zorunludur.

## CLI araçları

| Komut | Amaç |
| --- | --- |
| `php bin/install.php` | İlk kurulum gereksinimleri ve `.env` hazırlığı |
| `php bin/wizard.php` | Ubuntu/Nginx için altı adımlı etkileşimli kurulum |
| `php bin/migrate.php [--env=path]` | Bekleyen migrasyonları idempotent uygular |
| `php bin/seed.php demo` | `.env` kaynaklı demo hesabını oluşturur/günceller |
| `php bin/user-add.php --email=... --name=...` | Eğitmen veya yönetici oluşturur |
| `php bin/locale-check.php tr [--min=N]` | Locale anahtar kapsamını denetler |
| `php bin/smoke.php [--url=...] [--verbose]` | HTTP smoke testlerini çalıştırır |
| `php bin/cleanup.php [--max-age-hours=12] [--dry-run]` | Oturum kapatma, silme ve anonimleştirme bakımı |
| `php bin/backup.php [--keep-days=30] [--dry-run]` | Sıkıştırılmış `mysqldump` yedeği üretir |
| `php bin/rotate-secret.php [--apply]` | Yeni `APP_SECRET` üretir; `--apply` `.env` dosyasını değiştirir |
| `bash bin/verify-migrations.sh` | Migrasyonları geçici MySQL 8.4 üzerinde doğrular |

## İlgili bağlayıcı belgeler

- [API_SPEC.md](../../API_SPEC.md)
- [DATA_MODEL.md](../../DATA_MODEL.md)
- [SYSTEM_ARCHITECTURE.md](../../SYSTEM_ARCHITECTURE.md)
- [SECURITY_PRIVACY.md](../../SECURITY_PRIVACY.md)
- [I18N_SPEC.md](../../I18N_SPEC.md)
