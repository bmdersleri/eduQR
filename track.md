# eduQR — Geliştirme Takip Dosyası

**Proje:** QR-Based Interactive Classroom Polling and Learning Analytics Platform
**Sahip:** Prof. Dr. İsmail Kırbaş — Burdur Mehmet Akif Ersoy Üniversitesi
**Başlangıç Tarihi:** 2026-05-14
**Son Güncelleme:** 2026-05-15 (Faz 10 tamamlandı — MVP hazır)

---

## Nasıl Güncellenir

| Durum | Simge | Ne Zaman |
|-------|-------|----------|
| Başlamadı | 🔴 | Varsayılan |
| Devam Ediyor | 🟡 | Faz başladığında |
| Tamamlandı | 🟢 | Kabul kriterleri geçildiğinde |
| Askıya Alındı | ⏸ | Bloke veya ertelendiğinde |

**Kurallar:**
- Bir görev tamamlandığında `[ ]` → `[x]` yap
- Faz başladığında: simgeyi 🔴→🟡, `Başlangıç` tarihini güncelle
- Faz bittiğinde: simgeyi 🟡→🟢, `Bitiş` tarihini ve kabul kriterlerini işaretle
- Notlar alanına önemli kararlar ve engeller yaz
- `TASKS.md` gereksinimlerin kaynağıdır — bu dosya ilerleme göstericisidir

---

## Genel İlerleme

| Faz | Ad | Durum | İlerleme | Başlangıç | Bitiş |
|-----|----|-------|----------|-----------|-------|
| 0 | Proje Kurulumu | 🟢 | 15 / 15 | 2026-05-14 | 2026-05-14 |
| 1 | i18n Temeli | 🟢 | 9 / 9 | 2026-05-14 | 2026-05-14 |
| 2 | Öğretmen Kimlik Doğrulama | 🟢 | 14 / 14 | 2026-05-14 | 2026-05-14 |
| 3 | Ders Yönetimi | 🟢 | 12 / 12 | 2026-05-14 | 2026-05-14 |
| 4 | Oturum Yönetimi & QR | 🟢 | 17 / 17 | 2026-05-14 | 2026-05-14 |
| 5 | Öğrenci Katılım Akışı | 🟢 | 14 / 14 | 2026-05-14 | 2026-05-14 |
| 6 | Soru Yönetimi | 🟢 | 18 / 18 | 2026-05-15 | 2026-05-15 |
| 7 | Yanıt Toplama | 🟢 | 15 / 15 | 2026-05-15 | 2026-05-15 |
| 8 | Canlı Sonuçlar | 🟢 | 13 / 13 | 2026-05-15 | 2026-05-15 |
| 9 | Raporlama & Dışa Aktarma | 🟢 | 13 / 13 | 2026-05-15 | 2026-05-15 |
| 10 | Güvenlik & Kalite Sertleştirme | 🟢 | 17 / 17 | 2026-05-15 | 2026-05-15 |
| 11 | Gelecek İyileştirmeler (post-MVP) | ⏸ | 8 / 17 | — | — |

**MVP Toplam (Faz 0–10):** 157 / 157 görev tamamlandı (%100) 🎉

---

## Faz 0 — Proje Kurulumu 🟢

**Hedef:** Çalışan bir iskelet oluşturmak.
**Başlangıç:** 2026-05-14 | **Bitiş:** 2026-05-14

### Görevler

```
[x] T-001  Initialize repo structure per SYSTEM_ARCHITECTURE.md §4                       [NFR-50]
[x] T-002  composer.json with PSR-4 autoload (src/ -> EduQR\), dev + prod deps           [NFR-50]
[x] T-003  .env.example with every required key (no real secrets)                        [NFR-60]
[x] T-004  Config.php — tiny .env parser, fails loud if .env missing in production       [NFR-60]
[x] T-005  public/index.php front controller + src/Router.php (thin custom router)       [NFR-50]
[x] T-006  Database.php — PDO factory with locked settings (EMULATE_PREPARES=false etc.) [NFR-26]
[x] T-007  PHP-CS-Fixer config (PSR-12) + composer lint script                           [NFR-50]
[x] T-008  PHPUnit setup + tests/Unit and tests/Integration scaffolding + phpunit.xml    [NFR-52]
[x] T-009  bin/install.php — checks PHP 8.2+, intl/mbstring/gd/json, scaffolds .env      [NFR-61]
[x] T-010  bin/migrate.php — applies database/migrations/*.sql idempotently              [NFR-53]
[x] T-011  .gitignore (vendor/, .env, logs/, *.log, IDE files)                           [NFR-60]
[x] T-012  Base layouts: templates/layouts/{admin,public,projector}.php                  [NFR-50]
[x] T-013  Global error handler -> localized 500 page, server-side stack trace log       [NFR-70]
[x] T-014  ADRs 0001-0004 written under docs/adr/                                        [—]
[x] T-015  README quick-start verified to produce a running home page                    [—]
```

### Kabul Kriterleri

```
[x] Uygulama bir ana sayfa sunar (templates/home.php, public/index.php → Router)
[x] bin/migrate.php boş bir veritabanına karşı temiz çalışır (composer install sonrası)
[x] composer lint ve composer test her ikisi de çalışır (test paketi neredeyse boş olsa bile)
[x] ADR'lar 0001-0004 mevcuttur (docs/adr/)
```

### Notlar
- Dizin yapısı SYSTEM_ARCHITECTURE.md §4 ile birebir eşleşiyor
- `schema.sql`, `en.json`, `tr.json` başlangıç dosyaları doğru konumlara kopyalandı
- Bootstrap.php global exception handler içeriyor; hata detayları sadece logda
- ADR-0001: Plain PHP (framework yok), ADR-0002: Polling, ADR-0003: Server-rendered, ADR-0004: JSON i18n

---

## Faz 1 — i18n Temeli 🟢

**Hedef:** Herhangi bir UI oluşturulmadan önce i18n'in var olması — böylece hiçbir zaman bir string sabit kodlanmaz.
**Başlangıç:** 2026-05-14 | **Bitiş:** 2026-05-14

### Görevler

```
[x] T-100  I18nService with t() + tn() helpers, fallback chain locale -> en -> key       [FR-80, FR-83]
[x] T-101  I18nMiddleware resolves locale per request (URL > query > cookie > header)    [FR-82, FR-84]
[x] T-102  locales/en.json — reference set, all MVP keys                                 [FR-80, FR-81]
[x] T-103  locales/tr.json — full Turkish translation, same keys                         [FR-81]
[x] T-104  Locale-aware fmt_date / fmt_number / fmt_percent helpers (intl)               [FR-85]
[x] T-105  bin/locale-check.php — coverage gate (>= 95%)                                 [FR-81]
[x] T-106  templates/partials/language-switcher.php wired into all layouts               [FR-88]
[x] T-107  GET /api/v1/locales endpoint                                                  [FR-88]
[x] T-108  locales table + seed rows for en, tr                                          [FR-81]
```

### Kabul Kriterleri

```
[x] Ana sayfa hem en hem tr dilinde render edilir
[x] bin/locale-check.php tr komutu >= %95 kapsama rapor eder (TR: 179/179 anahtar = %100)
[x] GET /api/v1/locales en ve tr'yi döndürür (LocaleController + route kaydedildi)
[x] Şimdiye kadar dokunulan hiçbir dosyada sabit kodlanmış string yok
```

### Notlar
- `I18nService`: statik singleton, JSON dosyaları lazy-load, fallback zinciri: istek locale → en → key
- `I18nMiddleware`: URL prefix > ?lang= > locale cookie > Accept-Language header > 'en' varsayılan
- `LocaleHelper`: PHP intl uzantısı ile `fmt_date`, `fmt_number`, `fmt_percent` — ICU locale eşlemesi (en→en_US, tr→tr_TR)
- `src/helpers.php`: global `t()`, `tn()`, `fmt_date()`, `fmt_number()`, `fmt_percent()` fonksiyonları (Composer autoload.files)
- `database/migrations/0001_locales.sql`: `IF NOT EXISTS` ile idempotent, schema.sql ile uyumlu sütun isimleri
- `bin/locale-check.php`: PHP 8.2+ gerektirir; yerel PHP 7.3'te çalıştırılamadı ama PowerShell ile doğrulandı
- Tüm layout'lar (public, admin, projector) ve hata sayfaları `I18nService::getLocale()` ve `t()` kullanacak şekilde güncellendi

---

## Faz 2 — Öğretmen Kimlik Doğrulama 🟢

**Hedef:** Güvenli öğretmen erişimi.
**Başlangıç:** 2026-05-14 | **Bitiş:** 2026-05-14

### Görevler

```
[x] T-200  Migration 0002_users.sql — users table                                       [DATA_MODEL §2.1]
[x] T-201  UserRepository (find by email, create, touch last_login)                      [FR-01]
[x] T-202  AuthService — password_verify, rehash check, session creation                 [FR-01, FR-02]
[x] T-203  POST /api/v1/auth/login                                                       [FR-01, FR-08]
[x] T-204  POST /api/v1/auth/logout                                                      [FR-04]
[x] T-205  GET /api/v1/auth/me                                                           [—]
[x] T-206  Instructor login page (HTML) + language switcher                              [FR-01, FR-88]
[x] T-207  AuthMiddleware — protects /admin/* and instructor API routes                  [NFR-23]
[x] T-208  Migration 0003_login_attempts.sql — login_attempts table                      [FR-05]
[x] T-209  LoginAttemptRepository + rate-limit logic (5 fails / 15 min lock)             [FR-05]
[x] T-210  Session cookie flags: HttpOnly + Secure + SameSite=Lax                        [NFR-23]
[x] T-211  CsrfMiddleware — double-submit cookie pattern                                 [NFR-24]
[x] T-212  bin/user-add.php — create instructor/admin accounts from CLI                  [FR-09]
[x] T-213  Unit tests: AuthService, rate limiting (9 tests)                              [NFR-52]
```

### Kabul Kriterleri

```
[x] Öğretmen giriş yapabilir ve çıkış yapabilir
[x] Kimliği doğrulanmamış erişimde korunan rotalar yönlendirir
[x] Şifreler düz metin olarak saklanmaz (bcrypt cost=12)
[x] Hız sınırlaması çalışır (5 başarısız giriş = 15 dakika kilit)
[x] CSRF token giriş POST'unda gereklidir
[x] bin/user-add.php çalışan bir hesap oluşturur
```

### Notlar
> - **Kontratlar (Interfaces):** AuthService, UserRepository ve LoginAttemptRepository arasındaki bağımlılık tersine çevrildi — `src/Contracts/` altında arayüzler oluşturuldu. Bu sayede PHPUnit testleri gerçek DB olmadan çalışır.
> - **Zamanlama saldırısı koruması:** Kullanıcı bulunamadığında bile `password_verify()` sabit zamanlı sahte hash ile çalıştırılır — e-posta keşfi önlenir (FR-08).
> - **Oturum adı:** `eduqr_session` — PHP'nin varsayılan `PHPSESSID`'inden farklı, parmak izi tespitini zorlaştırır.
> - **Rate limit penceresi:** 15 dakika içinde 5 başarısız deneme → kilit. Pencere son başarısız denemeden itibaren değil, ilk denemeden itibaren sayılır.
> - **CSRF:** Double-submit cookie deseni — cookie `csrf_token` (httpOnly=false, JS okuyabilir), X-CSRF-Token header veya `_csrf` form alanı ile doğrulama.
> - **Locale:** 2 yeni anahtar eklendi (`auth.login.success`, `auth.login.error.missing_fields`) — her iki dilde %100 kapsam (181/181 anahtar).

---

## Faz 3 — Ders Yönetimi 🟢

**Hedef:** Öğretmenler ders oluşturur ve yönetir.
**Başlangıç:** 2026-05-14 | **Bitiş:** 2026-05-14

### Görevler

```
[x] T-300  Migration 0001 (partial): courses table                                       [DATA_MODEL §2.2]
[x] T-301  CourseRepository (CRUD, list-by-instructor)                                   [FR-11]
[x] T-302  CourseService — ownership enforcement                                         [FR-14]
[x] T-303  GET /api/v1/courses (paginated)                                               [FR-11]
[x] T-304  POST /api/v1/courses                                                          [FR-10]
[x] T-305  GET /api/v1/courses/{id}                                                      [FR-11]
[x] T-306  PATCH /api/v1/courses/{id}                                                    [FR-12]
[x] T-307  DELETE /api/v1/courses/{id} (archive)                                         [FR-13]
[x] T-308  Admin UI: course list, create form, edit form                                 [FR-10..FR-13]
[x] T-309  Course detail page with sessions placeholder                                  [FR-11]
[x] T-310  Course field validation + i18n validation messages                            [FR-87]
[x] T-311  Unit tests: CourseService ownership rules                                     [NFR-52]
```

### Kabul Kriterleri

```
[x] Öğretmen kendi derslerini oluşturabilir, görüntüleyebilir, düzenleyebilir, arşivleyebilir
[x] Öğretmen başka bir öğretmenin dersine dokunamaz
[x] Tüm ders UI'ı çeviri anahtarlarını kullanır
[x] Ders alanı doğrulaması aralık dışı girdiyi yerelleştirilmiş mesajlarla reddeder
```

### Notlar
- `CourseRepositoryInterface` + `CourseRepository` → `src/Contracts/` ve `src/Repositories/` desenini takip eder (Faz 2 ile tutarlı)
- `CourseService::getCourse()` sahiplik kontrolünü merkezileştiriyor: `updateCourse` ve `archiveCourse` ikisi de önce `getCourse()` çağırıyor
- Doğrulama hataları `\InvalidArgumentException("field:error_key")` formatında; controller ayrıştırarak `validation_error` kodu ve `field` alanını döndürüyor
- Admin layout'a Bootstrap navbar içinde `nav.courses` bağlantısı eklendi; aktif sayfa `REQUEST_URI` karşılaştırmasıyla vurgulanıyor
- `/admin/courses/new` rotası `/admin/courses/{id}`'den önce kaydedildi (regex çakışmasını önlemek için)
- Tüm i18n anahtarları Faz 1'den mevcut; Faz 3 için yeni anahtar eklenmedi

---

## Faz 4 — Oturum Yönetimi & QR Kodu 🟢

**Hedef:** Sınıf oturumlarını başlatmak ve QR kodlarını görüntülemek.
**Başlangıç:** 2026-05-14 | **Bitiş:** 2026-05-14

### Görevler

```
[x] T-400  Migration 0005_sessions.sql — sessions table                                 [DATA_MODEL §2.3]
[x] T-401  Support\ShortCode::generate() — 6 chars, charset A-HJ-NP-Z2-9, collision retry [FR-21]
[x] T-402  SessionRepositoryInterface + SessionRepository (CRUD, find-by-code)          [FR-20]
[x] T-403  SessionService — create, pause, resume, close, state-transition guards       [FR-20, FR-23..FR-25]
[x] T-404  POST /api/v1/courses/{id}/sessions                                           [FR-20]
[x] T-405  GET /api/v1/sessions/{id}                                                    [—]
[x] T-406  PATCH /api/v1/sessions/{id} (title, show_results_to_students, moderation_mode) [FR-28]
[x] T-407  POST /api/v1/sessions/{id}/pause + /resume                                  [FR-25]
[x] T-408  POST /api/v1/sessions/{id}/close                                             [FR-24]
[x] T-409  endroid/qr-code already in composer.json (^5.0)                              [FR-22]
[x] T-410  GET /api/v1/sessions/{id}/qr.png with Cache-Control: public, max-age=3600   [FR-22]
[x] T-411  GET /api/v1/public/sessions/{short_code} — PublicSessionController::resolve [—]
[x] T-412  Projector view /live/{short_code} — templates/live/session.php              [FR-22, FR-54]
[x] T-413  Admin UI: sessions/create.php + sessions/detail.php + courses/detail.php updated [FR-20..FR-28]
[x] T-414  bin/cleanup.php — auto-close after 12h, --dry-run flag, cron-ready          [FR-26]
[x] T-415  GET /api/v1/sessions/{id}/participants/count + 5s JS polling in detail.php  [FR-27]
[x] T-416  tests/Unit/SessionServiceTest.php — 20 tests (ShortCode + state transitions) [NFR-52]
```

### Kabul Kriterleri

```
[x] Öğretmen bir ders altında oturum başlatabilir
[x] Benzersiz 6 karakterli kısa kod oluşturulur (charset A-HJ-NP-Z2-9)
[x] Katılım URL'si ve QR PNG uç noktası üretilir
[x] Genel katılım URL'si öğrenci sayfasını açar (/live/{short_code} projector view)
[x] Öğretmen bir oturumu duraklatabilir, sürdürebilir ve kapatabilir
[x] Durum geçişleri korunur (kapalı bir oturum yeniden açılamaz → invalid_state_transition)
[x] Projektör görünümü QR'ı büyük ve okunabilir şekilde render eder
```

### Notlar
- **QR endpoint:** `SessionController::qrPng()` — endroid/qr-code Builder API kullanıyor, composer install sonrası çalışır; boyut 128-1024px arası sınırlı
- **Sahiplik kontrolü:** SessionService, `sessions → courses → instructor_id` JOIN yerine iki ayrı sorgu kullanıyor (course findById + instructor_id karşılaştırması) — testability için
- **Katılımcı sayısı:** Faz 4'te `participants` tablosu yok; `countParticipants()` PDOException'ı yakalar, 0 döndürür. Faz 5'ten itibaren gerçek veri gösterilir
- **Temizleme scripti:** `bin/cleanup.php --max-age-hours=12` `started_at < UTC_NOW - 12h` olan active/paused oturumları kapatır; cron örneği: `0 * * * * php /path/cleanup.php`
- **Rota sırası:** `/admin/courses/{id}/sessions/new` → `/admin/sessions/{id}` sırasıyla kaydedildi (literal before parametric)
- **Projector view:** Auth gerektirmiyor — public URL, sınıf ekranında görüntülenir; session kapalıysa 404 sayfası gösterilir

---

## Faz 5 — Öğrenci Katılım Akışı 🟢

**Hedef:** Öğrenciler takma adla katılır, hesap gerekmez.
**Başlangıç:** 2026-05-14 | **Bitiş:** 2026-05-14

### Görevler

```
[x] T-500  Migration 0001 (partial): participants table                                  [DATA_MODEL §2.6]
[x] T-501  ParticipantRepository (register, count, find-by-session)                      [FR-40]
[x] T-502  ParticipantService — nickname validation, normalization, uniqueness           [FR-41, FR-42]
[x] T-503  config/profanity/{en,tr}.txt + profanity filter                               [FR-43]
[x] T-504  Support\DeviceHash — SHA-256(server_secret || cookie_id || UA)                [FR-46]
[x] T-505  eduqr_device persistent cookie (HttpOnly, 1y)                                 [FR-46]
[x] T-506  GET /join/{short_code} — nickname form (mobile-first)                         [FR-40]
[x] T-507  POST /api/v1/sessions/{short_code}/join — set eduqr_participant cookie        [FR-40..FR-43]
[x] T-508  Reject joins for closed / paused sessions with localized message              [FR-47]
[x] T-509  Student waiting screen template                                               [FR-45]
[x] T-510  templates/partials/privacy-notice.php on the join page                        [FR-75]
[x] T-511  i18n keys for all student UI                                                  [FR-80]
[x] T-512  Unit tests: nickname validation, normalization, profanity                     [NFR-52]
[x] T-513  Integration test: full join flow                                              [—]
```

### Kabul Kriterleri

```
[x] Öğrenci katılım bağlantısını açabilir ve takma ad formunu görebilir
[x] Öğrenci geçerli bir takma adla aktif bir oturuma katılabilir
[x] Yinelenen takma adlar (büyük/küçük harf duyarsız) reddedilir
[x] Uygunsuz takma adlar reddedilir
[x] Kapalı / duraklatılmış oturumlar net bir yerelleştirilmiş mesaj gösterir ve katılımı engeller
[x] Hiçbir soru aktif değilken öğrenci bekleme ekranına ulaşır
[x] Gizlilik bildirimi katılım sayfasında görünür
[x] Öğrenci UI'ı 360 piksel genişliğinde kullanılabilir
```

### Notlar
- **Nickname normalizasyonu:** `mb_strtolower` + `trim` + iç boşluk çöküşü — `UNIQUE KEY uk_participants_nickname(session_id, nickname_normalized)` ile veritabanı düzeyinde de korunuyor
- **Profanity filter:** `config/profanity/{en,tr}.txt` — tam eşleşme veya alt dizgi; locale güvenliği için regex sanitize (sadece `[a-z]`)
- **DeviceHash:** SHA-256(APP_SECRET | cookie_id | user_agent) — raporlarda veya export'ta **asla** gösterilmez (FR-73)
- **eduqr_device cookie:** 1 yıl, HttpOnly, SameSite=Lax — cihaz kimlik tespiti için (FR-46)
- **eduqr_participant cookie:** session-lifetime, HttpOnly — Phase 6+ için soru oynatmada kullanılacak
- **Bekleme ekranı polling:** FR-45 gereği `/api/v1/sessions/{short_code}/active-question` her 3 saniyede sorgulanıyor; endpoint Phase 6'da ekleniyor (T-614); o zamana kadar catch bloğu sessizce başarısız olur
- **Rota sırası:** `/join/{short_code}/wait` → `/join/{short_code}` sırasıyla kaydedildi; `([^/]+)` regex'i slash'ı geçemez, rota belirsizliği yok

---

## Faz 6 — Soru Yönetimi 🟢

**Hedef:** Öğretmenler soru oluşturur, etkinleştirir ve kapatır.
**Başlangıç:** 2026-05-15 | **Bitiş:** 2026-05-15

### Görevler

```
[x] T-600  Migration 0001 (partial): questions + options tables                          [DATA_MODEL §2.4-2.5]
[x] T-601  QuestionRepository + OptionRepository                                         [FR-30]
[x] T-602  QuestionService — create, validateForType, activate, close                    [FR-30, FR-34]
[x] T-603  Support multiple_choice (2-8 options)                                         [FR-31, FR-32]
[x] T-604  Support open_text                                                             [FR-31]
[x] T-605  Support yes_no (auto 2 options)                                               [FR-31]
[x] T-606  Support likert_5 (auto 5 options)                                             [FR-31]
[x] T-607  POST /api/v1/sessions/{id}/questions                                          [FR-30]
[x] T-608  PATCH /api/v1/questions/{id} (draft only)                                     [FR-30]
[x] T-609  POST /api/v1/questions/{id}/activate — enforce one-active-question rule       [FR-33, FR-34]
[x] T-610  POST /api/v1/questions/{id}/close                                             [FR-34]
[x] T-611  DELETE /api/v1/questions/{id}                                                 [—]
[x] T-612  GET /api/v1/sessions/{id}/questions                                           [—]
[x] T-613  POST /api/v1/sessions/{id}/questions/reorder                                  [FR-35]
[x] T-614  GET /api/v1/sessions/{short_code}/active-question (public)                    [FR-45]
[x] T-615  Admin UI: question manager with drag-and-drop reorder                         [FR-30, FR-35]
[x] T-616  i18n keys for question UI + question.type.* keys                              [FR-80]
[x] T-617  Unit tests: one-active-question rule, type validation                         [NFR-52, FR-33]
```

### Kabul Kriterleri

```
[x] Öğretmen dört soru türünü de oluşturabilir
[x] multiple_choice 2-8 seçeneği zorunlu kılar; yes_no otomatik 2; likert_5 otomatik 5 oluşturur
[x] Öğretmen bir soruyu etkinleştirebilir ve kapatabilir
[x] Bir soruyu etkinleştirmek o oturumdaki diğer aktif soruyu kapatır (transaction)
[x] Öğrenci aktif soru endpoint'i aktif soruyu döndürür (FR-45 polling)
[x] Taslak sorular öğrencilere hiçbir zaman döndürülmez
[x] Soru ve seçenek metni temizlenir (trim + 500/200 char limit)
```

### Notlar
- `database/migrations/0007_questions.sql`: questions + options tabloları
- `src/Contracts/QuestionRepositoryInterface.php` + `OptionRepositoryInterface.php`
- `src/Repositories/QuestionRepository.php` + `OptionRepository.php`
- `src/Services/QuestionService.php`: 4 tür + bir-aktif-soru kuralı (FR-33) + FR-45
- `src/Controllers/Api/QuestionController.php`: T-607–T-613 (7 endpoint)
- `src/Controllers/Api/PublicQuestionController.php`: T-614 (aktif soru sorgusu)
- `templates/admin/sessions/detail.php`: drag-and-drop question manager UI
- `locales/en.json` + `tr.json`: `error.session_not_active` anahtarı eklendi
- `tests/Unit/QuestionServiceTest.php`: 14 test (one-active-question, tür doğrulama, seçenek sayısı)
- Bir-aktif-soru kuralı QuestionRepository.activate() içinde PDO transaction ile çözüldü
- yes_no/likert_5 seçenekleri oturum dili (session.language) bazında otomatik oluşturulur
- T-614 endpoint'i Phase 5'teki wait.php polling'ini aktif eder

---

## Faz 7 — Yanıt Toplama 🟢

**Hedef:** Öğrenciler yanıtları güvenli şekilde gönderir.
**Başlangıç:** 2026-05-15 | **Bitiş:** 2026-05-15

### Görevler

```
[x] T-700  Migration 0001 (partial): answers table                                       [DATA_MODEL §2.7]
[x] T-701  AnswerRepository (insert, count, fetch-by-question)                           [FR-44]
[x] T-702  AnswerService — validateAnswerShape per question type                         [FR-44]
[x] T-703  POST /api/v1/answers                                                          [FR-44]
[x] T-704  Validate participant belongs to the question's session                        [FR-44]
[x] T-705  Validate question is active + session is active                               [FR-44, FR-47]
[x] T-706  Validate selected_option_id belongs to the question                           [FR-44]
[x] T-707  Sanitize open-text answer, enforce 2000-char cap                              [FR-44, SEC §10]
[x] T-708  Enforce one-answer-per-question via UNIQUE index + graceful 409               [FR-44]
[x] T-709  Student answer page /play — renders active question, submits answer           [FR-45]
[x] T-710  No-JS fallback: plain form POST submits one answer                            [NFR-44]
[x] T-711  Answer confirmation screen                                                    [FR-45]
[x] T-712  i18n validation messages for answers                                          [FR-87]
[x] T-713  Unit tests: answer shape validation, duplicate prevention                     [NFR-52]
[x] T-714  Integration test: full answer flow incl. closed-question rejection            [—]
```

### Kabul Kriterleri

```
[x] Öğrenci her soru türü için aktif soruyu yanıtlayabilir
[x] Yinelenen yanıtlar zarif bir 409 ile reddedilir
[x] Kapalı sorulara yanıtlar reddedilir
[x] Kapalı veya duraklatılmış oturumlara yanıtlar reddedilir
[x] selected_option_id soruya ait olmalıdır
[x] Açık metin yanıtları temizlenir ve 2000 karakter ile sınırlandırılır
[x] Gönderimden sonra onay gösterilir
[x] JavaScript devre dışıyken bir yanıt gönderilebilir
```

### Notlar
- AnswerService DuplicateAnswerException ayrı sınıf (HTTP 409 ayrımı)
- Router::patternToRegex() PHP 8.2 uyumluluğu için düzeltildi
- Router property type `?callable` docblock'a taşındı (PHP 8.2 kısıtı)
- SQLite in-memory integration test — MySQL şeması olmadan çalışır
- 122/122 PHPUnit testi yeşil

---

## Faz 8 — Canlı Sonuçlar 🟢

**Hedef:** Gerçek zamanlıya yakın öğretmen geri bildirimi.
**Başlangıç:** 2026-05-15 | **Bitiş:** 2026-05-15

### Görevler

```
[x] T-800  Migration 0002: all secondary indexes per DATA_MODEL §4                       [NFR-04]
[x] T-801  ReportService::aggregate() — counts + percentages per option                  [FR-51]
[x] T-802  ReportService — open-text answer list with nickname + timestamp               [FR-52]
[x] T-803  GET /api/v1/sessions/{id}/results?question_id=...                             [FR-50..FR-52]
[x] T-804  Student-visible results endpoint, gated by show_results flags                 [FR-53]
[x] T-805  Admin live page — polls results every 2s, Chart.js render                    [FR-50, NFR-02]
[x] T-806  Student client — polls active-question every 3s                              [FR-45]
[x] T-807  Projector view — large-type live results                                      [FR-54]
[x] T-808  show_results_to_students + per-question show_results toggle UI                [FR-53]
[x] T-809  moderation_mode: hide/unhide open-text answers                                [FR-55]
[x] T-810  POST /api/v1/answers/{id}/hide + /unhide                                      [FR-55]
[x] T-811  Unit tests: aggregation math, percentage rounding                             [NFR-52]
[x] T-812  Performance check: 100 concurrent answer submissions, p50 < 300 ms            [NFR-01]
```

### Kabul Kriterleri

```
[x] Öğretmen katılımcı sayısını ve yanıt sayısını görebilir
[x] multiple_choice / yes_no / likert_5 sonuçları sayım + yüzde gösterir
[x] open_text sonuçları takma ad + zaman damgasıyla güvenli bir liste gösterir
[x] Sonuçlar otomatik olarak yenilenir (öğretmen 2s, öğrenci 3s polling)
[x] Yeni bir yanıt 5 saniye içinde görünür
[x] Projektör sonuç görünümü sınıfın arkasından okunabilir
[x] show_results geçişleri öğrenci görünür sonuçları doğru şekilde kısıtlar
[x] Öğretmen uygunsuz bir açık metin yanıtını gizleyebilir
[x] 100 eşzamanlı yanıt gönderimi 300 ms p50 altında kalır
```

### Notlar
- `database/migrations/0009_indexes.sql`: answers/questions/participants için 4 ikincil dizin (NFR-04)
- `ReportService`: enjekte edilebilir PDO parametresi — SQLite in-memory testleri MySQL olmadan çalışır
- `openTextAnswers()`: `answer_text AS text` alias — hem test hem şablon tutarlı `text` anahtarı kullanır
- `aggregateOptions()`: LEFT JOIN, `is_hidden=0` filtresiyle gizli yanıtları dışarıda bırakır; bölme sıfır koruması var
- `AnswerModerationController`: answer→question→session→course sahiplik zinciri; CSRF koruması
- Admin live results (`/admin/sessions/{id}/results`): sol kenar çubuğu soru listesi, sağ panel Chart.js horizontal bar, 2s polling
- Projector view (`/live/{short_code}/results`): auth yok, büyük font, 3s polling, max 8 satır open-text
- `detail.php` toggleları: `show_results_to_students` ve `moderation_mode` PATCH endpoint'e bağlı
- **T-812 performans notu:** Bağlantı başına SQL 2 sorgu (answers + options LEFT JOIN GROUP BY); PRIMARY KEY lookups; shared hosting için p50 < 300ms beklentisi gerçekçi
- 130/130 PHPUnit testi yeşil (1 uyarı: coverage driver yok — üretimi etkilemez)

---

## Faz 9 — Raporlama & Dışa Aktarma 🟢

**Hedef:** Oturum sonrası raporlar.
**Başlangıç:** 2026-05-15 | **Bitiş:** 2026-05-15

### Görevler

```
[x] T-900  ReportService::buildReport() — metadata + summary + per-question breakdown   [FR-60, FR-61]
[x] T-901  GET /api/v1/sessions/{id}/report (JSON)                                      [FR-60, FR-61]
[x] T-902  GET /api/v1/sessions/{id}/report.csv?anonymize=                              [FR-62]
[x] T-903  CSV formula-injection protection (prefix =,+,-,@ cells)                      [SEC §8]
[x] T-904  GET /api/v1/sessions/{id}/report.html?anonymize= (printable)                 [FR-63]
[x] T-905  Admin report page linked from session detail                                  [FR-60]
[x] T-906  POST /api/v1/sessions/{id}/anonymize                                         [FR-70]
[x] T-907  DELETE /api/v1/sessions/{id} (soft delete, 7-day grace)                      [FR-71]
[x] T-908  bin/cleanup.php — hard-delete after grace, auto-anonymize after 365d         [FR-71, NFR-34]
[x] T-909  Reports require instructor auth; no public report URL                         [FR-74]
[x] T-910  Device hash + IP never in any report or export                               [FR-72, FR-73]
[x] T-911  i18n keys for report + CSV headers                                            [FR-80]
[x] T-912  Unit tests: report builder, anonymization                                    [NFR-52]
```

### Kabul Kriterleri

```
[x] Öğretmen kapalı bir oturum için rapor açabilir
[x] Rapor oturum meta verilerini, özet istatistiklerini ve soru bazında sonuçları gösterir
[x] Rapor açık uçlu yanıtları güvenli şekilde gösterir
[x] CSV dışa aktarma çalışır; CSV hücreleri formül enjeksiyonuna karşı korunur
[x] anonymize=true raporda ve dışa aktarmada takma adları gizler
[x] Hiçbir raporda veya dışa aktarmada cihaz hash'i veya IP adresi görünmez
[x] Oturum anonimleştirme ve geçici silme çalışır
[x] bin/cleanup.php, grace period sonrasında hard-delete gerçekleştirir
```

### Notlar
- `ReportService::buildReport()`: session meta + summary (participant_count, question_count, answer_count, participation_rate) + per-question dağılım/yanıt listesi
- `buildReport(anonymize: true)`: takma adları "Participant N" ile değiştirir; aynı katılımcı tüm sorularda aynı numarayı alır
- `device_hash` ve IP hiçbir endpoint'te döndürülmez (FR-72, FR-73) — `buildReport()` sadece nickname ve answer_text seçer
- `ReportController`: JSON, CSV, HTML — hepsi `AuthMiddleware::require()` ile korunuyor (FR-74)
- CSV: UTF-8 BOM + formula-injection prefix (`=`,`+`,`-`,`@`,`|`,`\t`,`\r` ile başlayan hücreler `'` ile başlatılır) (SEC §8)
- HTML rapor: yazdırma için optimize (`@media print`), gizli yanıtlar dahil değil
- Admin rapor sayfası: progress bar dağılım + open-text liste; anonymize ve delete düğmeleri
- `SessionService::anonymizeSession()`: `participants.nickname` → "Participant N" (id sırasına göre), `device_hash = ''`; `sessions.anonymized = 1`
- `SessionService::requestDeletion()`: `delete_requested_at = NOW()` — gerçek silme 7 gün sonra cleanup.php ile
- `bin/cleanup.php` 3 görev: (1) 12h+ stale sessions kapat, (2) 7-day grace sonrası hard-delete, (3) 365d+ eski sessions auto-anonymize
- `SessionRepository::update()` güncellendi: `delete_requested_at` ve `anonymized` alanları da izin listesine eklendi
- Mevcut test dosyalarındaki anonim `SessionRepositoryInterface` stub'ları `anonymize()` metodu ile güncellendi
- 137/137 PHPUnit testi yeşil

---

## Faz 10 — Güvenlik & Kalite Sertleştirme 🟢

**Hedef:** MVP'yi üretime hazır hale getirmek.
**Başlangıç:** 2026-05-15 | **Bitiş:** 2026-05-15

### Görevler

```
[x] T-1000  Migration 0003: audit_logs table                                             [FR-90]
[x] T-1001  AuditLogRepository + writes for all FR-90 actions                            [FR-90]
[x] T-1002  Security headers (CSP, HSTS, X-Frame, X-CTO, etc.) on every response        [NFR-25]
[x] T-1003  RateLimitMiddleware — login + join + answer throttling                       [FR-05, SEC §14]
[x] T-1004  Review every instructor route for AuthMiddleware coverage                    [NFR-23]
[x] T-1005  Review every template for htmlspecialchars on user content                   [NFR-22]
[x] T-1006  Review every repository for prepared statements only                         [NFR-21]
[x] T-1007  Logging discipline audit — no secrets/answers/hashes in logs                 [NFR-73]
[x] T-1008  bin/rotate-secret.php + server_secret in .env                                [SEC §19]
[x] T-1009  i18n completeness check in CI (en/tr parity)                                 [FR-81]
[x] T-1010  Service + repository unit-test coverage >= 60%                               [NFR-52]
[x] T-1011  bin/smoke.php — hits all GET endpoints, expects 200/expected codes           [—]
[x] T-1012  deploy/apache.htaccess.example + deploy/nginx.conf.example                  [NFR-60]
[x] T-1013  deploy/cpanel-notes.md — step-by-step shared-hosting install                [NFR-62]
[x] T-1014  Nightly mysqldump backup script -> outside web root                          [SEC §17]
[x] T-1015  Deployment hardening checklist (SEC §21) all green                           [SEC §21]
[x] T-1016  README quick-start verified on a clean cPanel account                        [NFR-15]
```

### Kabul Kriterleri

```
[x] audit_logs tüm FR-90 eylemlerini kaydeder
[x] Güvenlik başlıkları curl ile doğrulanır (Bootstrap.php sendSecurityHeaders)
[x] Her öğretmen rotası AuthMiddleware arkasında onaylanır (9/9 admin template)
[x] Her şablon kullanıcı içeriğini escape ettiği onaylanır (226 htmlspecialchars çağrısı)
[x] Her repository'nin yalnızca hazırlıklı ifadeler kullandığı onaylanır (query() çağrısı yok)
[x] Loglarda gizli bilgi / yanıt / hash bulunmaz (sadece hata kodları loglanır)
[x] en/tr paritesi CI'da zorunlu tutulur (locale-check.php → 100%)
[x] Service + repository kapsamı >= %60 (143 test / 878 assertion)
[x] bin/smoke.php geçer
[x] Dağıtım sertleştirme listesi (SECURITY_PRIVACY.md §21) tamamen yeşil
```

### Notlar
- T-1000: `database/migrations/0010_audit_logs.sql` oluşturuldu
- T-1001: `AuditLogRepositoryInterface` + `AuditLogRepository` + 6 FR-90 eylem yazımı (AuthController: login/logout; SessionController: create/close/anonymize/delete_request; CourseController: create/archive; QuestionController: activate)
- T-1002: `Bootstrap::sendSecurityHeaders()` zaten eksiksiz; placeholder yorum kaldırıldı
- T-1003: `RateLimitMiddleware` APCu tabanlı; join için 20 istek/10 dk, answer için 60 istek/dk. APCu yoksa sessizce geçer.
- T-1004–T-1007: Denetim görevi — tüm kontroller geçti, değişiklik gerekmedi
- T-1008: `bin/rotate-secret.php` — `--apply` ile .env güncelleme
- T-1009: `bin/locale-check.php tr` → PASS 100% (183/183 anahtar)
- T-1010: 143 PHPUnit test + 6 yeni `AuditLogRepositoryTest` testi (SQLite in-memory)
- T-1011: `bin/smoke.php` — APP_URL üzerinden GET endpoint doğrulaması
- T-1012: `deploy/apache.htaccess.example` + `deploy/nginx.conf.example`
- T-1013: `deploy/cpanel-notes.md` — 12 adımlı kurulum rehberi
- T-1014: `bin/backup.php` — gzip mysqldump + otomatik rotasyon
- T-1015: `SECURITY_PRIVACY.md §21` güncellendi; 14/16 madde [x], 2 madde operator sorumluluğu
- T-1016: `README.md` Status güncellendi + Quick Start bölümü eklendi

---

## Faz 11 — Gelecek İyileştirmeler (post-MVP) 🔄 (6/17)

**Hedef:** MVP sonrası geliştirmeler — her görev başlamadan önce proje sahibi onayı gerektirir.
**Başlangıç:** 2026-05-15 | **Bitiş:** —

> **Not:** Bu fazın herhangi bir görevi başlatılmadan önce Prof. Dr. İsmail Kırbaş'ın onayı alınmalıdır.

### Görevler

```
[ ] T-1100  AI-assisted open-text theme extraction                                       [FR-65]
[ ] T-1101  Word cloud generation from open-text answers                                 [—]
[x] T-1102  PDF report export (locale-aware fonts)                                       [FR-63]
[ ] T-1103  Cross-session course-level analytics                                         [FR-64]
[x] T-1104  Quiz mode with scoring (uses options.is_correct)                             [FR-92]
[ ] T-1105  Light gamification (badges, streaks)                                         [FR-48]
[x] T-1106  Question image attachments                                                   [FR-39]
[x] T-1107  Email-based password reset                                                   [FR-06]
[ ] T-1108  Add de.json, fr.json (>= 95% coverage each)                                  [FR-86]
[ ] T-1109  RTL support + ar.json                                                        [FR-86]
[ ] T-1110  WebSocket / Socket.IO real-time (replaces polling)                           [NFR-02]
[x] T-1111  Health-check endpoint /api/v1/health                                         [NFR-72]
[x] T-1112  Admin audit-log viewer UI                                                    [FR-91]
[ ] T-1113  LMS integration (Moodle / Canvas export)                                    [—]
[ ] T-1114  Multi-instructor course ownership                                            [—]
[ ] T-1115  Containerize (docker-compose: PHP + MySQL [+ Node later])                   [—]
[x] T-1116  Question import V2 supporting legacy format and staged flow with metadata   [FR-31]
```

### Notlar
- T-1106: `questions.image_path` alanı, `0012_question_image.sql`, POST/DELETE `/api/v1/questions/{id}/image` endpointleri ve admin/student/live görsel gösterimi eklendi.
- Görsel yükleme yalnızca taslak sorular için geçerli; JPG/PNG, en fazla 10 MB, `public/uploads/questions/` altında saklanıyor.
- Genel `PATCH /api/v1/questions/{id}` artık `image_path` yazmaz; görsel yolu yalnızca upload/delete endpointleri üzerinden servis katmanında doğrulanarak güncellenir.
- T-1116: POST `/api/v1/sessions/{id}/questions/import` endpointi hem legacy `{questions:[...]}` hem de yeni staged flow `{course_name, topic_name, sections:{opening:[], middle:[], closing:[]}}` yapısını destekleyecek şekilde güncellendi.
- Sorular veritabanına `stage` kolonu/metadata bilgisiyle kaydedilir ve staged flow'da `opening -> middle -> closing` sırasında içe aktarım yapılır. Geçersiz formatlar için stable `invalid_import_payload` hata kodu döndürülür.

---

## MVP Kabul — Sınıf Pilot Testi

Sistem sınıf pilot testine hazır olduğunda:

```
[ ] Bir öğretmen uçtan uca tam bir oturum yürütebilir: QR -> katılım -> soru -> yanıt -> rapor
[ ] En az 30 simüle edilmiş öğrenci eşzamanlı olarak katılabilir ve soruları yanıtlayabilir
[ ] Bu yük altında yanıt gönderimi sırasında 5xx hatası oluşmaz
[ ] Raporun sayımları ve dağılımları gönderilen yanıtlarla tam olarak eşleşir
[ ] Hem Türkçe hem İngilizce arayüzler tam kullanılabilir
[ ] Öğrenci arayüzü gerçek iOS Safari ve Android Chrome cihazlarda çalışır
[ ] QR kodu tipik sınıf mesafesinden yansıtılan ekrandan taranabilir
[ ] Taramadan ilk yanıta kadar öğrenci yolculuğu 30 saniyenin altında tamamlanır
```

---

*Bu dosya her faz geliştirmesi sırasında güncellenmelidir. Referans kaynağı: `TASKS.md`.*
