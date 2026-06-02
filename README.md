# eduQR

**QR-Based Interactive Classroom Polling and Learning Analytics Platform**

> Interactive learning starts with a scan.

---

## What This Repository Is

This is the **specification** for eduQR — the set of binding documents an AI coding agent (Claude Code, OpenAI Codex CLI, Google Antigravity, Cursor, Aider, etc.) needs to implement the project without ambiguity.

If you are an AI agent reading this, your first stop after this file is `AGENTS.md`.

If you are a human evaluating the spec, read in this order: this README → `PROJECT_BRIEF.md` → `PRODUCT_REQUIREMENTS.md` → `SYSTEM_ARCHITECTURE.md`.

Implementation plans for larger work items live under `docs/superpowers/plans/`. The current UI redesign plan is [`docs/superpowers/plans/2026-06-02-ui-premium-redesign-plan.md`](docs/superpowers/plans/2026-06-02-ui-premium-redesign-plan.md).

## Status

| Item | Value |
| --- | --- |
| Phase | MVP complete — all Phase 0–10 tasks shipped (2026-05-15) |
| Spec version | 3.0 |
| Owner | Prof. Dr. İsmail Kırbaş — `ismailkirbas@mehmetakif.edu.tr` |
| Institution | Burdur Mehmet Akif Ersoy University, Computer Engineering |
| Target deployment | Shared cPanel hosting → containerized later |
| Initial languages | English (`en`), Turkish (`tr`) |
| License | Code: MIT (planned). Docs: CC BY-NC 4.0 (planned). Final license set before public release. |

## Document Index

The documents below are **all binding**. Each one is the single source of truth for its area. If two documents contradict each other, that is a bug in the spec — file an issue.

| # | File | Purpose |
| --- | --- | --- |
| 1 | [AGENTS.md](./AGENTS.md) | First-read for every AI agent. Rules, workflow, definition of done. |
| 2 | [PROJECT_BRIEF.md](./PROJECT_BRIEF.md) | Vision, problem, users, scope. |
| 3 | [PRODUCT_REQUIREMENTS.md](./PRODUCT_REQUIREMENTS.md) | Functional (`FR-xx`) and non-functional (`NFR-xx`) requirements. |
| 4 | [SYSTEM_ARCHITECTURE.md](./SYSTEM_ARCHITECTURE.md) | Tech stack, components, folder layout, data flows. |
| 5 | [DATA_MODEL.md](./DATA_MODEL.md) | Tables, columns, constraints, indexes, ERD. |
| 6 | [API_SPEC.md](./API_SPEC.md) | Every endpoint with request/response shape. |
| 7 | [I18N_SPEC.md](./I18N_SPEC.md) | Multi-language contract. |
| 8 | [SECURITY_PRIVACY.md](./SECURITY_PRIVACY.md) | Auth, validation, KVKK/GDPR, hardening. |
| 9 | [TASKS.md](./TASKS.md) | Phased implementation backlog. |
| 10 | [ACCEPTANCE_CRITERIA.md](./ACCEPTANCE_CRITERIA.md) | Per-phase and per-module gates. |
| 11 | [GLOSSARY.md](./GLOSSARY.md) | Term definitions (resolves ambiguous words like "session"). |

Starter files (drop into the repository as-is):

| File | Purpose |
| --- | --- |
| `.gitignore` | Standard PHP/Node ignores. |
| `.env.example` | All required environment variables. |
| `database/schema.sql` | Reference schema; canonical SQL. |
| `locales/en.json` | English UI strings, ~110 keys. |
| `locales/tr.json` | Turkish UI strings, same keys. |

---

## Quick Start (cPanel Shared Hosting)

```bash
# 1. Upload project to ~/eduqr-app/ (DocumentRoot → ~/eduqr-app/public/)
# 2. Install dependencies
php8.2 composer install --no-dev --optimize-autoloader

# 3. Configure environment
cp .env.example .env && chmod 600 .env
# Edit .env: DB_*, APP_URL, LOG_PATH, BACKUP_DIR
php8.2 bin/rotate-secret.php --apply   # generate APP_SECRET

# 4. Run migrations
php8.2 bin/migrate.php

# 5. Create first instructor account
php8.2 bin/user-add.php --email=you@example.org --name="Your Name" --password=...

# 6. Copy .htaccess
cp deploy/apache.htaccess.example public/.htaccess

# 7. Verify
php8.2 bin/smoke.php --url=https://yourdomain.example.org
```

Full step-by-step instructions: [`deploy/cpanel-notes.md`](./deploy/cpanel-notes.md)

---

## Quick Start (Ubuntu + Nginx + MariaDB — Interactive Wizard)

The interactive wizard handles the entire installation in one command.
It assumes Nginx and MariaDB are **already installed** on the server.

```bash
# 1. Upload / clone the project and install dependencies
composer install --no-dev --optimize-autoloader

# 2. Launch the wizard — it guides you through every step interactively
php bin/wizard.php
```

The wizard covers:

| Step | What it does |
| --- | --- |
| **[1] Requirements** | PHP 8.2+, required extensions, `vendor/` — prints exact `apt install` hints on failure |
| **[2] App config** | Creates `.env` from `.env.example`; auto-generates `APP_SECRET` |
| **[3] Database** | Tests MariaDB connection, writes DB credentials to `.env`, runs all migrations |
| **[4] Nginx config** | Renders `deploy/nginx.conf.template` → `deploy/nginx.conf` with your domain, FPM socket and TLS paths |
| **[5] Admin account** | Creates the first admin user (validates password policy) |
| **[6] Smoke tests** | Checks 5 endpoints: `/`, `/login`, `/api/v1/locales`, auth/me, courses |

After the wizard finishes it prints the exact `systemctl` commands to activate the Nginx config.

**Resuming a failed install** — skip completed steps with flags:

```bash
php bin/wizard.php --skip-nginx --skip-admin --skip-verify
```

---

## Locked Decisions (Read Before Coding)

These choices are **not up for debate during MVP implementation**. They are restated in each spec document but are listed here so an agent reading only the README still gets them right.

### Stack

- Backend: **PHP 8.2+**, plain (no Laravel/Symfony for MVP), front controller pattern.
- Database: **MySQL 8.0+ or MariaDB 10.6+**, charset `utf8mb4`, collation `utf8mb4_unicode_ci`.
- Frontend HTML: server-rendered PHP partials.
- CSS: **Bootstrap 5** (RTL build conditionally loaded for RTL locales).
- JS: vanilla **ES2022 modules**, no bundler.
- Charts: **Chart.js**.
- QR: **`endroid/qr-code`** server-side.
- Real-time: **HTTP polling**. Instructor 2 s, student 3 s. **No WebSockets in MVP.**
- i18n: **JSON locale files**, `t('key', {params})` helper, English fallback.
- Auth: **PHP session cookies** + CSRF double-submit. **bcrypt cost 12.**

### Database

- Auth table is **`users`** with `role ENUM('admin','instructor')`. There is no separate `instructors` table.
- Primary keys: `BIGINT UNSIGNED AUTO_INCREMENT`.
- Timestamps: `DATETIME`, UTC.
- Foreign keys: explicitly named `fk_<table>_<col>`.
- Session statuses: **`draft`, `active`, `paused`, `closed`**.
- Question statuses: **`draft`, `active`, `closed`**.
- Question types (exact strings): **`multiple_choice`, `open_text`, `yes_no`, `likert_5`**.

### API

- Versioned under **`/api/v1/`**.
- Response envelope:

  ```json
  {
    "success": true,
    "data": { ... },
    "message": "Optional localized message"
  }
  ```

  Error:

  ```json
  {
    "success": false,
    "error": {
      "code": "session_closed",
      "message": "This session is closed.",
      "field": "session_code"
    }
  }
  ```

- Error codes are **stable, machine-readable, snake_case** strings. See `API_SPEC.md` §12.
- All timestamps in responses are **ISO-8601 UTC**: `"2026-05-13T19:09:48Z"`.

### Internationalization

- Every user-facing string comes from a locale file. **Zero hardcoded UI strings, ever.**
- Translation key naming: `area.screen.element` (e.g. `auth.login.submit`).
- Placeholders are `{name}` style, not `%s`.
- Locale resolution order: URL prefix → `?lang=` query → `eduqr_locale` cookie → `Accept-Language` → `APP_LOCALE_DEFAULT`.
- Required at launch: `en`, `tr`. Fallback: `en`.

### Session Code

- Length: **6 characters**.
- Charset: **`A-H J-N P-Z 2-9`** (no `0/O/I/1/L`).
- Generated server-side with collision retry.

### Cookies

| Name | Flags | Lifetime |
| --- | --- | --- |
| `eduqr_session` | `HttpOnly; Secure; SameSite=Lax; Path=/` | 12 h sliding |
| `eduqr_participant` | `HttpOnly; Secure; SameSite=Lax; Path=/` | Bound to classroom session |
| `eduqr_locale` | `Secure; SameSite=Lax; Path=/` (no HttpOnly — JS reads it) | 1 year |
| `eduqr_csrf` | `Secure; SameSite=Strict; Path=/` (no HttpOnly) | Rotates on login + 24 h |

### Validation Snapshot

Full table in `SECURITY_PRIVACY.md` §8. Highlights:

| Field | Rule |
| --- | --- |
| Email | RFC 5322 + `FILTER_VALIDATE_EMAIL`, max 190 chars |
| Password | 10–128 chars, must contain ≥ 3 of {lowercase, uppercase, digit, symbol} |
| Nickname | 1–24 chars, `^[\p{L}\p{N}_\- ]+$` (Unicode letters/digits, underscore, hyphen, space) |
| Question text | 1–500 chars |
| Option text | 1–200 chars |
| Open-text answer | 1–2000 chars |
| Session title | 1–200 chars |
| Course title | 1–200 chars |
| Course code | 1–40 chars |

---

## Quick Start (for the Implementation Repo)

These commands assume the implementation repo has been initialized following `SYSTEM_ARCHITECTURE.md` §4.

```bash
# 1. Clone the implementation repo (separate from this spec repo)
git clone https://github.com/bmdersleri/eduQR.git
cd eduQR

# 2. Install dependencies
composer install

# 3. Configure environment
cp .env.example .env
# Edit .env with database credentials and base URL

# 4. Run migrations
php bin/migrate.php

# 5. Seed demo data (optional)
php bin/seed.php demo

# 6. Run dev server
php -S localhost:8080 -t public/
```

Open `http://localhost:8080` in your browser. Default seeded credentials are in `bin/seed.php`; change them immediately on a real deployment.

## JSON Question Import (staged lesson flow)

Instructor can import staged questions into a session with:

`POST /api/v1/sessions/{id}/questions/import`

Supported formats:

### 1. Legacy Format:
```json
{
  "questions": [
    {
      "question_text": "How well did you understand linked lists?",
      "question_type": "multiple_choice",
      "options": [
        { "option_text": "Very well" },
        { "option_text": "Mostly" }
      ],
      "stage": "opening"
    }
  ]
}
```

### 2. Staged Flow Format:
```json
{
  "course_name": "Physics 101",
  "topic_name": "Force and Motion",
  "sections": {
    "opening": [
      {"question_text": "Warm-up question", "question_type": "open_text"}
    ],
    "middle": [
      {"question_text": "Core concept check", "question_type": "yes_no"}
    ],
    "closing": [
      {"question_text": "Exit ticket", "question_type": "open_text"}
    ]
  }
}
```

Import order is always `opening -> middle -> closing`. Each question is stored with `stage` metadata (`opening`, `middle`, or `closing`) to persist its instructional stage. The staged flow format automatically prefixes question texts with `[Course Name | Topic Name | StageLabel]`. Any invalid payload structure triggers the stable error code `invalid_import_payload` (HTTP 400).

---

## Folder Layout (target implementation)

```text
eduqr/
├── public/                  # web root — only this is web-accessible
│   ├── index.php            # front controller
│   ├── assets/{css,js,img}
│   └── .htaccess
├── src/
│   ├── Bootstrap.php
│   ├── Router.php
│   ├── Controllers/{Admin,Api,Public}
│   ├── Services/
│   ├── Repositories/
│   ├── Middleware/
│   └── Support/
│       └── Wizard/          # interactive setup wizard classes
│           ├── Console.php  # injectable I/O helper
│           ├── Step.php     # abstract base
│           └── Steps/       # RequirementsStep, EnvStep, DatabaseStep,
│                            # NginxStep, AdminStep, VerifyStep
├── templates/{layouts,admin,student,live}
├── locales/{en.json, tr.json, ...}
├── database/{schema.sql, migrations/, seeds/}
├── bin/{install.php, migrate.php, seed.php, locale-check.php,
│        user-add.php, rotate-secret.php, smoke.php, backup.php,
│        wizard.php}         # interactive Nginx setup wizard
├── tests/{Unit, Integration}
├── deploy/{apache.htaccess.example, nginx.conf.example,
│           nginx.conf.template,     # wizard renders this
│           cpanel-notes.md}
├── docs/adr/                # architecture decision records
├── .env.example
├── .gitignore
├── composer.json
└── README.md
```

---

## Glossary Quick Reference

These terms appear repeatedly and are often overloaded. The full table is in `GLOSSARY.md`.

| Term | Meaning in eduQR |
| --- | --- |
| **Session** | A classroom lecture instance during which students join and answer questions. *Not* the PHP HTTP session. |
| **HTTP session** | The cookie-backed PHP session used for instructor login. |
| **Participant** | A student who joined a classroom session with a nickname. Anonymous, no account. |
| **User** | A row in the `users` table — instructor or admin. |
| **Question** | A single poll item posed during a session. |
| **Answer** | A participant's submission for one question. |
| **Locale** | A language/region tag, e.g. `en`, `tr`, `ar`. |

---

## Contact

Project owner: **Prof. Dr. İsmail Kırbaş**
Department of Computer Engineering, Burdur Mehmet Akif Ersoy University
`ismailkirbas@mehmetakif.edu.tr`
