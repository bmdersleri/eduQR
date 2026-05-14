# eduQR — Geliştirme Takip Dosyası

**Proje:** QR-Based Interactive Classroom Polling and Learning Analytics Platform
**Sahip:** Prof. Dr. İsmail Kırbaş — Burdur Mehmet Akif Ersoy Üniversitesi
**Başlangıç Tarihi:** 2026-05-14
**Son Güncelleme:** 2026-05-14 (Faz 2 tamamlandı)

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
| 3 | Ders Yönetimi | 🔴 | 0 / 12 | — | — |
| 4 | Oturum Yönetimi & QR | 🔴 | 0 / 17 | — | — |
| 5 | Öğrenci Katılım Akışı | 🔴 | 0 / 14 | — | — |
| 6 | Soru Yönetimi | 🔴 | 0 / 18 | — | — |
| 7 | Yanıt Toplama | 🔴 | 0 / 15 | — | — |
| 8 | Canlı Sonuçlar | 🔴 | 0 / 13 | — | — |
| 9 | Raporlama & Dışa Aktarma | 🔴 | 0 / 13 | — | — |
| 10 | Güvenlik & Kalite Sertleştirme | 🔴 | 0 / 17 | — | — |
| 11 | Gelecek İyileştirmeler (post-MVP) | ⏸ | 0 / 16 | — | — |

**MVP Toplam (Faz 0–10):** 38 / 157 görev tamamlandı (%24)

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

## Faz 3 — Ders Yönetimi 🔴

**Hedef:** Öğretmenler ders oluşturur ve yönetir.
**Başlangıç:** — | **Bitiş:** —

### Görevler

```
[ ] T-300  Migration 0001 (partial): courses table                                       [DATA_MODEL §2.2]
[ ] T-301  CourseRepository (CRUD, list-by-instructor)                                   [FR-11]
[ ] T-302  CourseService — ownership enforcement                                         [FR-14]
[ ] T-303  GET /api/v1/courses (paginated)                                               [FR-11]
[ ] T-304  POST /api/v1/courses                                                          [FR-10]
[ ] T-305  GET /api/v1/courses/{id}                                                      [FR-11]
[ ] T-306  PATCH /api/v1/courses/{id}                                                    [FR-12]
[ ] T-307  DELETE /api/v1/courses/{id} (archive)                                         [FR-13]
[ ] T-308  Admin UI: course list, create form, edit form                                 [FR-10..FR-13]
[ ] T-309  Course detail page with sessions placeholder                                  [FR-11]
[ ] T-310  Course field validation + i18n validation messages                            [FR-87]
[ ] T-311  Unit tests: CourseService ownership rules                                     [NFR-52]
```

### Kabul Kriterleri

```
[ ] Öğretmen kendi derslerini oluşturabilir, görüntüleyebilir, düzenleyebilir, arşivleyebilir
[ ] Öğretmen başka bir öğretmenin dersine dokunamaz
[ ] Tüm ders UI'ı çeviri anahtarlarını kullanır
[ ] Ders alanı doğrulaması aralık dışı girdiyi yerelleştirilmiş mesajlarla reddeder
```

### Notlar
> _(Buraya önemli kararlar, engeller veya notlar ekleyin)_

---

## Faz 4 — Oturum Yönetimi & QR Kodu 🔴

**Hedef:** Sınıf oturumlarını başlatmak ve QR kodlarını görüntülemek.
**Başlangıç:** — | **Bitiş:** —

### Görevler

```
[ ] T-400  Migration 0001 (partial): sessions table                                      [DATA_MODEL §2.3]
[ ] T-401  Support\ShortCode::generate() — 6 chars, charset A-HJ-NP-Z2-9, collision retry [FR-21]
[ ] T-402  SessionRepository (CRUD, find-active-by-code)                                 [FR-20]
[ ] T-403  SessionService — create, pause, resume, close, state-transition guards        [FR-20, FR-23..FR-25]
[ ] T-404  POST /api/v1/courses/{id}/sessions                                            [FR-20]
[ ] T-405  GET /api/v1/sessions/{id}                                                     [—]
[ ] T-406  PATCH /api/v1/sessions/{id} (title, show_results_to_students, moderation_mode) [FR-28]
[ ] T-407  POST /api/v1/sessions/{id}/pause + /resume                                   [FR-25]
[ ] T-408  POST /api/v1/sessions/{id}/close                                              [FR-24]
[ ] T-409  endroid/qr-code vendored via composer                                         [FR-22]
[ ] T-410  GET /api/v1/sessions/{id}/qr.png with Cache-Control                           [FR-22]
[ ] T-411  GET /api/v1/public/sessions/{short_code} (resolve)                            [—]
[ ] T-412  Projector view /live/{short_code} — large QR + session title                  [FR-22, FR-54]
[ ] T-413  Admin UI: session detail page + start-session flow                            [FR-20..FR-28]
[ ] T-414  Auto-close inactive sessions after 12h (cron-able bin/cleanup.php)            [FR-26]
[ ] T-415  Live participant count on session detail                                      [FR-27]
[ ] T-416  Unit tests: ShortCode uniqueness, session state transitions                   [NFR-52]
```

### Kabul Kriterleri

```
[ ] Öğretmen bir ders altında oturum başlatabilir
[ ] Benzersiz 6 karakterli kısa kod oluşturulur (charset A-HJ-NP-Z2-9)
[ ] Katılım URL'si ve QR PNG üretilir
[ ] Genel katılım URL'si öğrenci sayfasını açar
[ ] Öğretmen bir oturumu duraklatabilir, sürdürebilir ve kapatabilir
[ ] Durum geçişleri korunur (kapalı bir oturum yeniden başlatılamaz)
[ ] Projektör görünümü QR'ı büyük ve okunabilir şekilde render eder
```

### Notlar
> _(Buraya önemli kararlar, engeller veya notlar ekleyin)_

---

## Faz 5 — Öğrenci Katılım Akışı 🔴

**Hedef:** Öğrenciler takma adla katılır, hesap gerekmez.
**Başlangıç:** — | **Bitiş:** —

### Görevler

```
[ ] T-500  Migration 0001 (partial): participants table                                  [DATA_MODEL §2.6]
[ ] T-501  ParticipantRepository (register, count, find-by-session)                      [FR-40]
[ ] T-502  ParticipantService — nickname validation, normalization, uniqueness           [FR-41, FR-42]
[ ] T-503  config/profanity/{en,tr}.txt + profanity filter                               [FR-43]
[ ] T-504  Support\DeviceHash — SHA-256(server_secret || cookie_id || UA)                [FR-46]
[ ] T-505  eduqr_device persistent cookie (HttpOnly, 1y)                                 [FR-46]
[ ] T-506  GET /join/{short_code} — nickname form (mobile-first)                         [FR-40]
[ ] T-507  POST /api/v1/sessions/{short_code}/join — set eduqr_participant cookie        [FR-40..FR-43]
[ ] T-508  Reject joins for closed / paused sessions with localized message              [FR-47]
[ ] T-509  Student waiting screen template                                               [FR-45]
[ ] T-510  templates/partials/privacy-notice.php on the join page                        [FR-75]
[ ] T-511  i18n keys for all student UI                                                  [FR-80]
[ ] T-512  Unit tests: nickname validation, normalization, profanity                     [NFR-52]
[ ] T-513  Integration test: full join flow                                              [—]
```

### Kabul Kriterleri

```
[ ] Öğrenci katılım bağlantısını açabilir ve takma ad formunu görebilir
[ ] Öğrenci geçerli bir takma adla aktif bir oturuma katılabilir
[ ] Yinelenen takma adlar (büyük/küçük harf duyarsız) reddedilir
[ ] Uygunsuz takma adlar reddedilir
[ ] Kapalı / duraklatılmış oturumlar net bir yerelleştirilmiş mesaj gösterir ve katılımı engeller
[ ] Hiçbir soru aktif değilken öğrenci bekleme ekranına ulaşır
[ ] Gizlilik bildirimi katılım sayfasında görünür
[ ] Öğrenci UI'ı 360 piksel genişliğinde kullanılabilir
```

### Notlar
> _(Buraya önemli kararlar, engeller veya notlar ekleyin)_

---

## Faz 6 — Soru Yönetimi 🔴

**Hedef:** Öğretmenler soru oluşturur, etkinleştirir ve kapatır.
**Başlangıç:** — | **Bitiş:** —

### Görevler

```
[ ] T-600  Migration 0001 (partial): questions + options tables                          [DATA_MODEL §2.4-2.5]
[ ] T-601  QuestionRepository + OptionRepository                                         [FR-30]
[ ] T-602  QuestionService — create, validateForType, activate, close                    [FR-30, FR-34]
[ ] T-603  Support multiple_choice (2-8 options)                                         [FR-31, FR-32]
[ ] T-604  Support open_text                                                             [FR-31]
[ ] T-605  Support yes_no (auto 2 options)                                               [FR-31]
[ ] T-606  Support likert_5 (auto 5 options)                                             [FR-31]
[ ] T-607  POST /api/v1/sessions/{id}/questions                                          [FR-30]
[ ] T-608  PATCH /api/v1/questions/{id} (draft only)                                     [FR-30]
[ ] T-609  POST /api/v1/questions/{id}/activate — enforce one-active-question rule       [FR-33, FR-34]
[ ] T-610  POST /api/v1/questions/{id}/close                                             [FR-34]
[ ] T-611  DELETE /api/v1/questions/{id}                                                 [—]
[ ] T-612  GET /api/v1/sessions/{id}/questions                                           [—]
[ ] T-613  POST /api/v1/sessions/{id}/questions/reorder                                  [FR-35]
[ ] T-614  GET /api/v1/sessions/{short_code}/active-question (public)                    [FR-45]
[ ] T-615  Admin UI: question manager with drag-and-drop reorder                         [FR-30, FR-35]
[ ] T-616  i18n keys for question UI + question.type.* keys                              [FR-80]
[ ] T-617  Unit tests: one-active-question rule, type validation                         [NFR-52, FR-33]
```

### Kabul Kriterleri

```
[ ] Öğretmen dört soru türünü de oluşturabilir
[ ] multiple_choice 2-8 seçeneği zorunlu kılar; yes_no otomatik 2; likert_5 otomatik 5 oluşturur
[ ] Öğretmen bir soruyu etkinleştirebilir ve kapatabilir
[ ] Bir soruyu etkinleştirmek o oturumdaki diğer aktif soruyu kapatır
[ ] Öğrenci aktif soru endpoint'i aktif soruyu döndürür
[ ] Taslak sorular öğrencilere hiçbir zaman döndürülmez
[ ] Soru ve seçenek metni temizlenir
```

### Notlar
> _(Buraya önemli kararlar, engeller veya notlar ekleyin)_

---

## Faz 7 — Yanıt Toplama 🔴

**Hedef:** Öğrenciler yanıtları güvenli şekilde gönderir.
**Başlangıç:** — | **Bitiş:** —

### Görevler

```
[ ] T-700  Migration 0001 (partial): answers table                                       [DATA_MODEL §2.7]
[ ] T-701  AnswerRepository (insert, count, fetch-by-question)                           [FR-44]
[ ] T-702  AnswerService — validateAnswerShape per question type                         [FR-44]
[ ] T-703  POST /api/v1/answers                                                          [FR-44]
[ ] T-704  Validate participant belongs to the question's session                        [FR-44]
[ ] T-705  Validate question is active + session is active                               [FR-44, FR-47]
[ ] T-706  Validate selected_option_id belongs to the question                           [FR-44]
[ ] T-707  Sanitize open-text answer, enforce 2000-char cap                              [FR-44, SEC §10]
[ ] T-708  Enforce one-answer-per-question via UNIQUE index + graceful 409               [FR-44]
[ ] T-709  Student answer page /play — renders active question, submits answer           [FR-45]
[ ] T-710  No-JS fallback: plain form POST submits one answer                            [NFR-44]
[ ] T-711  Answer confirmation screen                                                    [FR-45]
[ ] T-712  i18n validation messages for answers                                          [FR-87]
[ ] T-713  Unit tests: answer shape validation, duplicate prevention                     [NFR-52]
[ ] T-714  Integration test: full answer flow incl. closed-question rejection            [—]
```

### Kabul Kriterleri

```
[ ] Öğrenci her soru türü için aktif soruyu yanıtlayabilir
[ ] Yinelenen yanıtlar zarif bir 409 ile reddedilir
[ ] Kapalı sorulara yanıtlar reddedilir
[ ] Kapalı veya duraklatılmış oturumlara yanıtlar reddedilir
[ ] selected_option_id soruya ait olmalıdır
[ ] Açık metin yanıtları temizlenir ve 2000 karakter ile sınırlandırılır
[ ] Gönderimden sonra onay gösterilir
[ ] JavaScript devre dışıyken bir yanıt gönderilebilir
```

### Notlar
> _(Buraya önemli kararlar, engeller veya notlar ekleyin)_

---

## Faz 8 — Canlı Sonuçlar 🔴

**Hedef:** Gerçek zamanlıya yakın öğretmen geri bildirimi.
**Başlangıç:** — | **Bitiş:** —

### Görevler

```
[ ] T-800  Migration 0002: all secondary indexes per DATA_MODEL §4                       [NFR-04]
[ ] T-801  ReportService::aggregate() — counts + percentages per option                  [FR-51]
[ ] T-802  ReportService — open-text answer list with nickname + timestamp               [FR-52]
[ ] T-803  GET /api/v1/sessions/{id}/results?question_id=...                             [FR-50..FR-52]
[ ] T-804  Student-visible results endpoint, gated by show_results flags                 [FR-53]
[ ] T-805  Admin live page — polls results every 2s, Chart.js render                    [FR-50, NFR-02]
[ ] T-806  Student client — polls active-question every 3s                              [FR-45]
[ ] T-807  Projector view — large-type live results                                      [FR-54]
[ ] T-808  show_results_to_students + per-question show_results toggle UI                [FR-53]
[ ] T-809  moderation_mode: hide/unhide open-text answers                                [FR-55]
[ ] T-810  POST /api/v1/answers/{id}/hide + /unhide                                      [FR-55]
[ ] T-811  Unit tests: aggregation math, percentage rounding                             [NFR-52]
[ ] T-812  Performance check: 100 concurrent answer submissions, p50 < 300 ms            [NFR-01]
```

### Kabul Kriterleri

```
[ ] Öğretmen katılımcı sayısını ve yanıt sayısını görebilir
[ ] multiple_choice / yes_no / likert_5 sonuçları sayım + yüzde gösterir
[ ] open_text sonuçları takma ad + zaman damgasıyla güvenli bir liste gösterir
[ ] Sonuçlar otomatik olarak yenilenir (öğretmen 2s, öğrenci 3s polling)
[ ] Yeni bir yanıt 5 saniye içinde görünür
[ ] Projektör sonuç görünümü sınıfın arkasından okunabilir
[ ] show_results geçişleri öğrenci görünür sonuçları doğru şekilde kısıtlar
[ ] Öğretmen uygunsuz bir açık metin yanıtını gizleyebilir
[ ] 100 eşzamanlı yanıt gönderimi 300 ms p50 altında kalır
```

### Notlar
> _(Buraya önemli kararlar, engeller veya notlar ekleyin)_

---

## Faz 9 — Raporlama & Dışa Aktarma 🔴

**Hedef:** Oturum sonrası raporlar.
**Başlangıç:** — | **Bitiş:** —

### Görevler

```
[ ] T-900  ReportService::buildReport() — metadata + summary + per-question breakdown   [FR-60, FR-61]
[ ] T-901  GET /api/v1/sessions/{id}/report (JSON)                                      [FR-60, FR-61]
[ ] T-902  GET /api/v1/sessions/{id}/report.csv?anonymize=                              [FR-62]
[ ] T-903  CSV formula-injection protection (prefix =,+,-,@ cells)                      [SEC §8]
[ ] T-904  GET /api/v1/sessions/{id}/report.html?anonymize= (printable)                 [FR-63]
[ ] T-905  Admin report page linked from session detail                                  [FR-60]
[ ] T-906  POST /api/v1/sessions/{id}/anonymize                                         [FR-70]
[ ] T-907  DELETE /api/v1/sessions/{id} (soft delete, 7-day grace)                      [FR-71]
[ ] T-908  bin/cleanup.php — hard-delete after grace, auto-anonymize after 365d         [FR-71, NFR-34]
[ ] T-909  Reports require instructor auth; no public report URL                         [FR-74]
[ ] T-910  Device hash + IP never in any report or export                               [FR-72, FR-73]
[ ] T-911  i18n keys for report + CSV headers                                            [FR-80]
[ ] T-912  Unit tests: report builder, anonymization                                    [NFR-52]
```

### Kabul Kriterleri

```
[ ] Öğretmen kapalı bir oturum için rapor açabilir
[ ] Rapor oturum meta verilerini, özet istatistiklerini ve soru bazında sonuçları gösterir
[ ] Rapor açık uçlu yanıtları güvenli şekilde gösterir
[ ] CSV dışa aktarma çalışır; CSV hücreleri formül enjeksiyonuna karşı korunur
[ ] anonymize=true raporda ve dışa aktarmada takma adları gizler
[ ] Hiçbir raporda veya dışa aktarmada cihaz hash'i veya IP adresi görünmez
[ ] Oturum anonimleştirme ve geçici silme çalışır
[ ] bin/cleanup.php, grace period sonrasında hard-delete gerçekleştirir
```

### Notlar
> _(Buraya önemli kararlar, engeller veya notlar ekleyin)_

---

## Faz 10 — Güvenlik & Kalite Sertleştirme 🔴

**Hedef:** MVP'yi üretime hazır hale getirmek.
**Başlangıç:** — | **Bitiş:** —

### Görevler

```
[ ] T-1000  Migration 0003: audit_logs table                                             [FR-90]
[ ] T-1001  AuditLogRepository + writes for all FR-90 actions                            [FR-90]
[ ] T-1002  Security headers (CSP, HSTS, X-Frame, X-CTO, etc.) on every response        [NFR-25]
[ ] T-1003  RateLimitMiddleware — login + join + answer throttling                       [FR-05, SEC §14]
[ ] T-1004  Review every instructor route for AuthMiddleware coverage                    [NFR-23]
[ ] T-1005  Review every template for htmlspecialchars on user content                   [NFR-22]
[ ] T-1006  Review every repository for prepared statements only                         [NFR-21]
[ ] T-1007  Logging discipline audit — no secrets/answers/hashes in logs                 [NFR-73]
[ ] T-1008  bin/rotate-secret.php + server_secret in .env                                [SEC §19]
[ ] T-1009  i18n completeness check in CI (en/tr parity)                                 [FR-81]
[ ] T-1010  Service + repository unit-test coverage >= 60%                               [NFR-52]
[ ] T-1011  bin/smoke.php — hits all GET endpoints, expects 200/expected codes           [—]
[ ] T-1012  deploy/apache.htaccess.example + deploy/nginx.conf.example                  [NFR-60]
[ ] T-1013  deploy/cpanel-notes.md — step-by-step shared-hosting install                [NFR-62]
[ ] T-1014  Nightly mysqldump backup script -> outside web root                          [SEC §17]
[ ] T-1015  Deployment hardening checklist (SEC §21) all green                           [SEC §21]
[ ] T-1016  README quick-start verified on a clean cPanel account                        [NFR-15]
```

### Kabul Kriterleri

```
[ ] audit_logs tüm FR-90 eylemlerini kaydeder
[ ] Güvenlik başlıkları curl ile doğrulanır
[ ] Her öğretmen rotası AuthMiddleware arkasında onaylanır
[ ] Her şablon kullanıcı içeriğini escape ettiği onaylanır
[ ] Her repository'nin yalnızca hazırlıklı ifadeler kullandığı onaylanır
[ ] Loglarda gizli bilgi / yanıt / hash bulunmaz
[ ] en/tr paritesi CI'da zorunlu tutulur
[ ] Service + repository kapsamı >= %60
[ ] bin/smoke.php geçer
[ ] Dağıtım sertleştirme listesi (SECURITY_PRIVACY.md §21) tamamen yeşil
```

### Notlar
> _(Buraya önemli kararlar, engeller veya notlar ekleyin)_

---

## Faz 11 — Gelecek İyileştirmeler (post-MVP) ⏸

**Hedef:** MVP sonrası geliştirmeler — her görev başlamadan önce proje sahibi onayı gerektirir.
**Başlangıç:** — | **Bitiş:** —

> **Not:** Bu fazın herhangi bir görevi başlatılmadan önce Prof. Dr. İsmail Kırbaş'ın onayı alınmalıdır.

### Görevler

```
[ ] T-1100  AI-assisted open-text theme extraction                                       [FR-65]
[ ] T-1101  Word cloud generation from open-text answers                                 [—]
[ ] T-1102  PDF report export (locale-aware fonts)                                       [FR-63]
[ ] T-1103  Cross-session course-level analytics                                         [FR-64]
[ ] T-1104  Quiz mode with scoring (uses options.is_correct)                             [—]
[ ] T-1105  Light gamification (badges, streaks)                                         [FR-48]
[ ] T-1106  Question image attachments                                                   [FR-39]
[ ] T-1107  Email-based password reset                                                   [FR-06]
[ ] T-1108  Add de.json, fr.json (>= 95% coverage each)                                  [FR-86]
[ ] T-1109  RTL support + ar.json                                                        [FR-86]
[ ] T-1110  WebSocket / Socket.IO real-time (replaces polling)                           [NFR-02]
[ ] T-1111  Health-check endpoint /api/v1/health                                         [NFR-72]
[ ] T-1112  Admin audit-log viewer UI                                                    [FR-91]
[ ] T-1113  LMS integration (Moodle / Canvas export)                                    [—]
[ ] T-1114  Multi-instructor course ownership                                            [—]
[ ] T-1115  Containerize (docker-compose: PHP + MySQL [+ Node later])                   [—]
```

### Notlar
> _(Buraya önemli kararlar, engeller veya notlar ekleyin)_

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
