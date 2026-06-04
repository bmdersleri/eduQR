# System Architecture — eduQR

This document defines **how** eduQR is built. It is binding for the MVP. Deviations require an ADR (`docs/adr/`) approved by the human owner.

For "what it must do," see `PRODUCT_REQUIREMENTS.md`.
For "what the schema is," see `DATA_MODEL.md`.

---

## 1. Stack Decision (locked)

### 1.1 MVP Stack

| Layer | Choice | Rationale |
| --- | --- | --- |
| Backend language | **PHP 8.2+** | Universally available on shared / cPanel hosting; matches existing institutional infrastructure. |
| Backend style | **Plain PHP + thin custom router + service classes** | Avoids framework lock-in; deployment artifact is a folder of files. **No Laravel, no Symfony, no Slim for MVP.** |
| Database | **MySQL 8.0+ / MariaDB 10.6+** | Same hosting compatibility; widely supported tooling. |
| Charset / collation | **`utf8mb4` / `utf8mb4_unicode_ci`** | Full Unicode, case-insensitive uniqueness. |
| Templates | **Server-rendered PHP partials** | No template-engine dependency. Lightweight. |
| CSS | **Bootstrap 5** (`bootstrap.min.css`; `bootstrap.rtl.min.css` for RTL) | Quick composition, dark-mode-friendly, RTL-ready. |
| JS | **Vanilla ES2022 modules** | No build step. Upgradable later. |
| Charts | **Chart.js** (vendored or CDN with SRI) | Mature, locale-friendly. |
| QR generation | **`endroid/qr-code`** (PHP, server-side) | Works without JS; cacheable. |
| Real-time | **HTTP polling** at 2 s (instructor) / 3 s (student) | No WebSocket dependency. |
| i18n | **JSON locale files + `t()` helper** | Git-friendly, translator-friendly. Details in `I18N_SPEC.md`. |
| Auth | **PHP `session_*` cookies + CSRF tokens** | Standard, well-understood. |
| Password hashing | **`password_hash` + `PASSWORD_BCRYPT`, cost 12** | Robust, no extra deps. |
| Tests | **PHPUnit** | Standard. |
| Lint | **PHP-CS-Fixer**, PSR-12 ruleset | Standard. |

### 1.2 Future Stack (Phase 11+)

Documented as the upgrade path, **not** part of MVP work:

| Layer | Upgrade |
| --- | --- |
| Real-time | Replace polling with Socket.IO sidecar (Node.js) or Ratchet (PHP). |
| Frontend | Migrate instructor panel to React + Vite. Student side stays lightweight. |
| Build | CI pipeline with PHPUnit, PHP-CS-Fixer, Playwright e2e. |
| Hosting | Containerize with Docker; deploy to a small VPS or managed Kubernetes. |
| AI | Add an LLM-backed service for lecture-note question generation and open-text theme extraction. |

**Keep the door open, do not walk through it yet.**

---

## 2. Logical Components

```text
                    ┌─────────────────────────────────────────┐
                    │              Public Internet            │
                    └───────────────────┬─────────────────────┘
                                        │ HTTPS
                ┌───────────────────────┴───────────────────────┐
                │                Web Server                     │
                │              (Apache / Nginx)                 │
                └───────────────────────┬───────────────────────┘
                                        │
                ┌───────────────────────┴───────────────────────┐
                │           PHP front controller                │
                │             public/index.php                  │
                └─────┬─────────────────────────────────────┬───┘
                      │                                     │
       ┌──────────────┴──────────────┐         ┌────────────┴───────────┐
       │ Web routes (HTML)           │         │ API routes (JSON)      │
       │ /admin/*  /join/*  /live/*  │         │ /api/v1/*              │
       └──────────────┬──────────────┘         └────────────┬───────────┘
                      │                                     │
                      └─────────────────┬───────────────────┘
                                        │
                ┌───────────────────────┴───────────────────────┐
                │              Middleware chain                 │
                │     I18n  →  Csrf  →  Auth  →  RateLimit      │
                └───────────────────────┬───────────────────────┘
                                        │
                ┌───────────────────────┴───────────────────────┐
                │                 Service Layer                 │
                │  AuthService, SessionService, QuestionService,│
                │  AnswerService, ReportService, I18nService    │
                └───────────────────────┬───────────────────────┘
                                        │
                ┌───────────────────────┴───────────────────────┐
                │            Repositories / DAOs                │
                │     (one class per table; prepared SQL)       │
                └───────────────────────┬───────────────────────┘
                                        │
                ┌───────────────────────┴───────────────────────┐
                │            MySQL / MariaDB                    │
                └───────────────────────────────────────────────┘
```

### 2.1 Layer Responsibilities

| Layer | Owns | Does Not Own |
| --- | --- | --- |
| Front controller | URL parsing, dispatch, error envelope, global middleware | Business logic |
| Web routes / controllers | HTML rendering, form handling | JSON output |
| API routes / controllers | JSON output, status codes, error envelope, **input validation** | Templating, business rules |
| Middleware | Cross-cutting concerns (auth, CSRF, i18n, rate limit) | Domain logic |
| Services | Business rules, validation that involves multiple tables | SQL strings |
| Repositories | Prepared SQL, one class per table | Business rules, HTML |
| Database | Persistence, constraints, indexes | Application logic |

---

## 3. Main Interfaces

### 3.1 Instructor Panel

Authenticated. Mounted under `/admin/*`. Provides:

- Dashboard (`/admin`)
- Course management (`/admin/courses`, `/admin/courses/{id}`)
- Session detail + question manager (`/admin/sessions/{id}`)
- Live results (`/admin/sessions/{id}/live`)
- Reports (`/admin/sessions/{id}/report`)

### 3.2 Student Interface

Public. Mobile-first. Provides:

- Join (`/join/{short_code}` → QR target)
- Nickname form
- Waiting screen
- Active question screen
- Answer submission
- Confirmation

### 3.3 Projector / Live Results View

Public read-only. Mounted at `/live/{short_code}`. Provides:

- Large QR code display
- Participation count
- Optional question results (when `show_results_to_students = true`)
- High-contrast typography sized for back-of-room readability

---

## 4. Folder Layout

The repository root is the project root. **Only `public/` is web-accessible.**

```text
eduqr/
├── .env.example
├── .gitignore
├── composer.json
├── composer.lock
├── README.md
├── PROJECT_BRIEF.md
├── PRODUCT_REQUIREMENTS.md
├── SYSTEM_ARCHITECTURE.md
├── DATA_MODEL.md
├── API_SPEC.md
├── I18N_SPEC.md
├── SECURITY_PRIVACY.md
├── TASKS.md
├── ACCEPTANCE_CRITERIA.md
├── GLOSSARY.md
├── AGENTS.md
│
├── public/                  # WEB ROOT — only this is exposed
│   ├── index.php            # front controller
│   ├── assets/
│   │   ├── css/
│   │   ├── js/
│   │   └── img/
│   └── .htaccess
│
├── src/
│   ├── Bootstrap.php
│   ├── Config.php
│   ├── Router.php
│   ├── Controllers/
│   │   ├── Admin/
│   │   ├── Api/
│   │   └── Public/
│   ├── Services/
│   │   ├── AuthService.php
│   │   ├── CourseService.php
│   │   ├── SessionService.php
│   │   ├── QuestionService.php
│   │   ├── AnswerService.php
│   │   ├── ReportService.php
│   │   ├── ParticipantService.php
│   │   └── I18nService.php
│   ├── Repositories/
│   │   ├── UserRepository.php
│   │   ├── CourseRepository.php
│   │   ├── SessionRepository.php
│   │   ├── QuestionRepository.php
│   │   ├── OptionRepository.php
│   │   ├── ParticipantRepository.php
│   │   ├── AnswerRepository.php
│   │   ├── AuditLogRepository.php
│   │   └── LoginAttemptRepository.php
│   ├── Middleware/
│   │   ├── AuthMiddleware.php
│   │   ├── CsrfMiddleware.php
│   │   ├── I18nMiddleware.php
│   │   └── RateLimitMiddleware.php
│   ├── Support/
│   │   ├── Database.php     # PDO factory
│   │   ├── Validator.php
│   │   ├── ShortCode.php
│   │   ├── DeviceHash.php
│   │   └── Csrf.php
│   └── Exceptions/
│       ├── SessionNotFoundException.php
│       ├── SessionClosedException.php
│       ├── DuplicateNicknameException.php
│       ├── AlreadyAnsweredException.php
│       └── ValidationException.php
│
├── templates/
│   ├── layouts/
│   │   ├── admin.php
│   │   ├── public.php
│   │   └── projector.php
│   ├── admin/
│   ├── student/
│   ├── live/
│   └── partials/
│       ├── language-switcher.php
│       └── privacy-notice.php
│
├── locales/
│   ├── en.json
│   ├── tr.json
│   └── README.md
│
├── config/
│   └── profanity/
│       ├── en.txt
│       └── tr.txt
│
├── database/
│   ├── schema.sql
│   ├── migrations/
│   │   ├── 0001_initial.sql
│   │   ├── 0002_indexes.sql
│   │   └── 0003_audit_log.sql
│   └── seeds/
│       └── demo.sql
│
├── bin/
│   ├── install.php
│   ├── migrate.php
│   ├── seed.php
│   ├── user-add.php
│   ├── locale-check.php
│   ├── rotate-secret.php
│   └── smoke.php
│
├── tests/
│   ├── Unit/
│   ├── Integration/
│   └── phpunit.xml
│
├── deploy/
│   ├── nginx.conf.example
│   ├── apache.htaccess.example
│   └── cpanel-notes.md
│
├── docs/
│   └── adr/
│       ├── 0001-plain-php-over-framework.md
│       ├── 0002-polling-over-websockets.md
│       ├── 0003-server-rendered-templates.md
│       └── 0004-json-locale-files.md
│
└── logs/                    # writeable, never web-accessible
```

---

## 5. Routing Conventions

- **HTML routes** (server-rendered) live under `Controllers/Admin/`, `Controllers/Public/`.
- **JSON API routes** live under `Controllers/Api/` and are **versioned**: `/api/v1/...`.
- **Locale prefix is optional**: `/tr/admin/courses` and `/admin/courses` both work; the prefix overrides the cookie when present.

### 5.1 Canonical URL Patterns

| Pattern | Purpose |
| --- | --- |
| `/login` | Instructor login form |
| `/admin` | Instructor dashboard |
| `/admin/courses` | Course list |
| `/admin/courses/new` | Create-course form |
| `/admin/courses/{id}` | Course detail / sessions list |
| `/admin/sessions/{id}` | Session detail / question manager |
| `/admin/sessions/{id}/live` | Live result panel |
| `/admin/sessions/{id}/report` | Post-session report |
| `/live/{short_code}` | Projector view (large display) |
| `/join/{short_code}` | Student join page (QR target) |
| `/play` | Student play / answer page |
| `/api/v1/...` | JSON endpoints (see `API_SPEC.md`) |

### 5.2 Locale-Aware Routing

- An optional `/{locale}/` prefix is accepted on any HTML route. Example: `/tr/admin`, `/en/join/ABCD23`.
- API routes do not take a locale prefix. They accept `?lang=` and headers.

---

## 6. Request Flow Examples

### 6.1 Student joins a session

```text
1. Student scans QR → GET /join/ABCD23
2. Front controller resolves route → JoinController::show()
3. I18nMiddleware resolves locale
4. SessionService::findActiveByCode("ABCD23")
5. If found, render templates/student/nickname.php
6. Student submits → POST /join/ABCD23 with CSRF token
7. ParticipantService::register()
8. On success, set eduqr_participant cookie and redirect → /play
```

### 6.2 Student answers an active question (polling)

```text
1. Browser: GET /api/v1/sessions/ABCD23/active-question?since=<ts>
2. AuthMiddleware checks eduqr_participant cookie
3. QuestionService::getActiveForSession()
4. Response: { question: {...} } or { question: null }
5. Student picks option, POST /api/v1/answers
   body: { question_id, selected_option_id?, answer_text? }
6. AnswerService::submit() enforces uniqueness rule
7. Response: { success: true, data: { answer_id: ... } }
```

### 6.3 Instructor watches live results

```text
1. Instructor opens /admin/sessions/42/live
2. Page loads templates/admin/live.php
3. JS polls /api/v1/sessions/42/results?question_id=Q every 2 s
4. ReportService::aggregate(Q) returns counts/percentages or text answers
5. Chart.js redraws on each response
```

### 6.4 Instructor closes session and reads report

```text
1. POST /api/v1/sessions/42/close
2. SessionService::close()  →  status='closed', closed_at=NOW()
3. AuditLogRepository::write('session.closed', sessionId)
4. Redirect to /admin/sessions/42/report
5. ReportService::buildReport(42) loads metadata, questions, answers
6. CSV / HTML / JSON variants share the same builder output
```

---

## 7. Deployment Topology

### 7.1 Shared / cPanel hosting (MVP target)

```text
public_html/
└── eduqr/
    └── ... (contents of /public/)

home/<user>/
└── eduqr-app/
    └── ... (everything outside /public/, incl. vendor/, src/, locales/)
```

`public_html/eduqr/index.php` requires `__DIR__ . '/../../eduqr-app/src/Bootstrap.php'`.

**All sensitive files live outside the document root.** See `deploy/cpanel-notes.md` for step-by-step instructions.

### 7.2 VPS / containerized (future)

- Nginx in front, PHP-FPM behind.
- MySQL on the same host for MVP; separate for high-scale.
- Optional Redis for sessions and rate-limiting in Phase 11.

---

## 8. Configuration (`.env`)

All environment-specific values come from a single `.env` file, read by a tiny custom parser in `Config.php`. **Do not require a framework just for config.**

Required keys are documented in `.env.example`. Highlights:

```ini
APP_NAME=eduQR
APP_ENV=production              # production | development
APP_URL=https://eduqr.example.org
APP_LOCALE_DEFAULT=en
APP_LOCALES=en,tr
APP_SECRET=...                  # random_bytes(32), base64-encoded

DB_HOST=localhost
DB_PORT=3306
DB_NAME=eduqr
DB_USER=eduqr_app
DB_PASS=...

SESSION_NAME=eduqr_session
SESSION_LIFETIME_MINUTES=720
COOKIE_SECURE=true
COOKIE_SAMESITE=Lax

LOG_PATH=/home/<user>/eduqr-app/logs
LOG_LEVEL=warning
```

Production MUST NOT commit `.env`. `composer install` should fail loud if `.env` is missing in production mode.

---

## 9. Error Handling Strategy

| Layer | Strategy |
| --- | --- |
| Repository | Throw on SQL error. Never swallow. |
| Service | Catch repo exceptions, translate to domain exceptions (`SessionNotFoundException`, `DuplicateNicknameException`, `AlreadyAnsweredException`). |
| Controller | Catch domain exceptions, return appropriate HTTP status + localized message via the error envelope. |
| Middleware | Convert any unhandled exception to a 500 with a localized generic message; log details server-side. |
| Global | Uncaught exceptions logged with stack trace; user sees a generic localized error page (status 500). |

See the canonical error-code list in `API_SPEC.md` §12.

---

## 10. Concurrency Notes

- **Same question, simultaneous answers:** `UNIQUE (question_id, participant_id)` enforces "one answer per participant per question" at the DB level when `allow_multiple_answers = false`. Second insert fails cleanly; service translates to `already_answered`.
- **Two instructors editing same session:** last write wins for MVP. Optimistic locking (`version` column) deferred to Phase 11.
- **Short-code collision:** `ShortCode::generate()` retries up to 5 times on `UNIQUE` violation before throwing.
- **One-active-question rule:** enforced at application layer in `QuestionService::activate()` — set all other questions for the session to `closed` in a transaction with the new activation.

---

## 11. Architecture Decision Records (ADRs)

Material decisions live in `docs/adr/` as small markdown files. Each ADR has:

```markdown
# ADR-XXXX: <Title>
Status: Accepted | Superseded | Deprecated
Date: YYYY-MM-DD

## Context
## Decision
## Consequences
```

Initial ADRs (write before first feature commit):

- **ADR-0001** — Plain PHP over a framework for MVP.
- **ADR-0002** — HTTP polling over WebSockets for MVP.
- **ADR-0003** — Server-rendered templates over an SPA for the student side.
- **ADR-0004** — JSON locale files over `gettext` `.po/.mo`.

Adding an ADR is mandatory when overruling anything in this file or in `AGENTS.md`.

---

## 12. Architectural Constraints

- Do not hardcode user-facing text.
- Do not put SQL in templates or controllers.
- Do not put business logic in repositories.
- Do not expose instructor-only data on public routes.
- Do not allow answers to closed or paused sessions.
- Keep the student interface simple, fast, and mobile-first.
- Keep `public/` the only web-accessible directory.
