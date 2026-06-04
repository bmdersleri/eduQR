# Implementation Tasks — eduQR

This is the working backlog. Tasks are grouped into **phases**; phases ship in order. Each task has a stable ID (`T-xxx`), a one-line description, and the requirement IDs it satisfies.

Implementation plans that drive multi-step work live under `docs/superpowers/plans/`. Current premium UI plan: [docs/superpowers/plans/2026-06-02-ui-premium-redesign-plan.md](docs/superpowers/plans/2026-06-02-ui-premium-redesign-plan.md).

**Rules for AI agents:**

- Implement **phase by phase**. Do not build all phases at once.
- A phase is not "done" until its acceptance checkpoint (and the matching section in `ACCEPTANCE_CRITERIA.md`) passes.
- Every task references at least one `FR-xx` / `NFR-xx`. A task with no requirement ID needs one added to `PRODUCT_REQUIREMENTS.md` first.
- Tick `[x]` only when the task is **shipped** — code + tests + docs + locale keys.
- A task that grows beyond roughly half a day of work should be split.

---

## Phase 0 — Project Setup

Goal: a runnable skeleton.

```text
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

Acceptance checkpoint: the app serves a home page; `bin/migrate.php` runs cleanly against an empty database.

---

## Phase 1 — Internationalization Foundation

Goal: i18n exists before any UI is built, so no string is ever hardcoded.

```text
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

Acceptance checkpoint: the home page renders in both `en` and `tr`; `bin/locale-check.php tr` reports ≥ 95 %.

---

## Phase 2 — Instructor Authentication

Goal: secure instructor access.

```text
[x] T-200  Migration 0001 (partial): users table                                        [DATA_MODEL §2.1]
[x] T-201  UserRepository (find by email, create, touch last_login)                      [FR-01]
[x] T-202  AuthService — password_verify, rehash check, session creation                 [FR-01, FR-02]
[x] T-203  POST /api/v1/auth/login                                                       [FR-01, FR-08]
[x] T-204  POST /api/v1/auth/logout                                                      [FR-04]
[x] T-205  GET /api/v1/auth/me                                                           [—]
[x] T-206  Instructor login page (HTML) + language switcher                              [FR-01, FR-88]
[x] T-207  AuthMiddleware — protects /admin/* and instructor API routes                  [NFR-23]
[x] T-208  Migration 0003 (partial): login_attempts table                                [FR-05]
[x] T-209  LoginAttemptRepository + rate-limit logic (5 fails / 10 min -> 15 min lock)    [FR-05]
[x] T-210  Session cookie flags: HttpOnly + Secure + SameSite=Lax                        [NFR-23]
[x] T-211  CsrfMiddleware — double-submit cookie pattern                                 [NFR-24]
[x] T-212  bin/user-add.php — create instructor/admin accounts from CLI                  [FR-09]
[x] T-213  Unit tests: AuthService, rate limiting                                        [NFR-52]
```

Acceptance checkpoint: instructor can log in and out; protected routes redirect when unauthenticated; no plain-text passwords; rate limiting works.

---

## Phase 3 — Course Management

Goal: instructors create and manage courses.

```text
[x] T-300  Migration 0001 (partial): courses table                                       [DATA_MODEL §2.2]
[x] T-301  CourseRepository (CRUD, list-by-instructor)                                    [FR-11]
[x] T-302  CourseService — ownership enforcement                                          [FR-14]
[x] T-303  GET /api/v1/courses (paginated)                                                [FR-11]
[x] T-304  POST /api/v1/courses                                                           [FR-10]
[x] T-305  GET /api/v1/courses/{id}                                                       [FR-11]
[x] T-306  PATCH /api/v1/courses/{id}                                                     [FR-12]
[x] T-307  DELETE /api/v1/courses/{id} (archive)                                          [FR-13]
[x] T-308  Admin UI: course list, create form, edit form                                  [FR-10..FR-13]
[x] T-309  Course detail page with sessions placeholder                                   [FR-11]
[x] T-310  Course field validation + i18n validation messages                             [FR-87]
[x] T-311  Unit tests: CourseService ownership rules                                      [NFR-52]
```

Acceptance checkpoint: instructor can create, view, edit, archive their own courses; cannot touch another instructor's course; all UI uses translation keys.

---

## Phase 4 — Session Management & QR Code

Goal: start classroom sessions and display QR codes.

```text
[x] T-400  Migration 0001 (partial): sessions table                                       [DATA_MODEL §2.3]
[x] T-401  Support\ShortCode::generate() — 6 chars, charset A-HJ-NP-Z2-9, collision retry [FR-21]
[x] T-402  SessionRepository (CRUD, find-active-by-code)                                  [FR-20]
[x] T-403  SessionService — create, pause, resume, close, state-transition guards         [FR-20, FR-23..FR-25]
[x] T-404  POST /api/v1/courses/{id}/sessions                                             [FR-20]
[x] T-405  GET /api/v1/sessions/{id}                                                      [—]
[x] T-406  PATCH /api/v1/sessions/{id} (title, show_results_to_students, moderation_mode) [FR-28]
[x] T-407  POST /api/v1/sessions/{id}/pause + /resume                                     [FR-25]
[x] T-408  POST /api/v1/sessions/{id}/close                                               [FR-24]
[x] T-409  endroid/qr-code vendored via composer                                          [FR-22]
[x] T-410  GET /api/v1/sessions/{id}/qr.png with Cache-Control                            [FR-22]
[x] T-411  GET /api/v1/public/sessions/{short_code} (resolve)                             [—]
[x] T-412  Projector view /live/{short_code} — large QR + session title                   [FR-22, FR-54]
[x] T-413  Admin UI: session detail page + start-session flow                             [FR-20..FR-28]
[x] T-414  Auto-close inactive sessions after 12h (cron-able bin/cleanup.php)             [FR-26]
[x] T-415  Live participant count on session detail                                       [FR-27]
[x] T-416  Unit tests: ShortCode uniqueness, session state transitions                    [NFR-52]
```

Acceptance checkpoint: instructor starts a session, sees a QR + join URL, can pause/resume/close; the public join URL opens a student page; projector view renders the QR large.

---

## Phase 5 — Student Join Flow

Goal: students join with a nickname, no account.

```text
[x] T-500  Migration 0001 (partial): participants table                                  [DATA_MODEL §2.6]
[x] T-501  ParticipantRepository (register, count, find-by-session)                       [FR-40]
[x] T-502  ParticipantService — nickname validation, normalization, uniqueness            [FR-41, FR-42]
[x] T-503  config/profanity/{en,tr}.txt + profanity filter                                [FR-43]
[x] T-504  Support\DeviceHash — SHA-256(server_secret || cookie_id || UA)                 [FR-46]
[x] T-505  eduqr_device persistent cookie (HttpOnly, 1y)                                  [FR-46]
[x] T-506  GET /join/{short_code} — nickname form (mobile-first)                          [FR-40]
[x] T-507  POST /api/v1/sessions/{short_code}/join — set eduqr_participant cookie         [FR-40..FR-43]
[x] T-508  Reject joins for closed / paused sessions with localized message               [FR-47]
[x] T-509  Student waiting screen template                                                [FR-45]
[x] T-510  templates/partials/privacy-notice.php on the join page                         [FR-75]
[x] T-511  i18n keys for all student UI                                                   [FR-80]
[x] T-512  Unit tests: nickname validation, normalization, profanity                      [NFR-52]
[x] T-513  Integration test: full join flow                                               [—]
[x] T-514  Returning student auto-restore via persistent device cookie                    [FR-49]
```

Acceptance checkpoint: student opens the join link, enters a nickname, joins an active session, lands on a waiting screen; closed/paused sessions show a clear message; the privacy notice is visible.

---

## Phase 6 — Question Management

Goal: instructors create, activate, and close questions.

```text
[x] T-600  Migration 0001 (partial): questions + options tables                           [DATA_MODEL §2.4-2.5]
[x] T-601  QuestionRepository + OptionRepository                                          [FR-30]
[x] T-602  QuestionService — create, validateForType, activate, close                     [FR-30, FR-34]
[x] T-603  Support multiple_choice (2-8 options)                                          [FR-31, FR-32]
[x] T-604  Support open_text                                                              [FR-31]
[x] T-605  Support yes_no (auto 2 options)                                                [FR-31]
[x] T-606  Support likert_5 (auto 5 options)                                              [FR-31]
[x] T-607  POST /api/v1/sessions/{id}/questions                                           [FR-30]
[x] T-608  PATCH /api/v1/questions/{id} (draft only)                                      [FR-30]
[x] T-609  POST /api/v1/questions/{id}/activate — enforce one-active-question rule        [FR-33, FR-34]
[x] T-610  POST /api/v1/questions/{id}/close                                              [FR-34]
[x] T-611  DELETE /api/v1/questions/{id}                                                  [—]
[x] T-612  GET /api/v1/sessions/{id}/questions                                            [—]
[x] T-613  POST /api/v1/sessions/{id}/questions/reorder                                   [FR-35]
[x] T-614  GET /api/v1/sessions/{short_code}/active-question (public)                     [FR-45]
[x] T-615  Admin UI: question manager with drag-and-drop reorder                          [FR-30, FR-35]
[x] T-616  i18n keys for question UI + question.type.* keys                               [FR-80]
[x] T-617  Unit tests: one-active-question rule, type validation                          [NFR-52, FR-33]
```

Acceptance checkpoint: instructor creates all four question types, activates and closes them; activating one closes any other active question; the student endpoint returns the active question.

---

## Phase 7 — Answer Collection

Goal: students submit answers safely.

```text
[x] T-700  Migration 0001 (partial): answers table                                       [DATA_MODEL §2.7]
[x] T-701  AnswerRepository (insert, count, fetch-by-question)                             [FR-44]
[x] T-702  AnswerService — validateAnswerShape per question type                          [FR-44]
[x] T-703  POST /api/v1/answers                                                           [FR-44]
[x] T-704  Validate participant belongs to the question's session                         [FR-44]
[x] T-705  Validate question is active + session is active                                [FR-44, FR-47]
[x] T-706  Validate selected_option_id belongs to the question                            [FR-44]
[x] T-707  Sanitize open-text answer, enforce 2000-char cap                                [FR-44, SEC §10]
[x] T-708  Enforce one-answer-per-question via UNIQUE index + graceful 409                 [FR-44]
[x] T-709  Student answer page /play — renders active question, submits answer             [FR-45]
[x] T-710  No-JS fallback: plain form POST submits one answer                              [NFR-44]
[x] T-711  Answer confirmation screen                                                      [FR-45]
[x] T-712  i18n validation messages for answers                                            [FR-87]
[x] T-713  Unit tests: answer shape validation, duplicate prevention                       [NFR-52]
[x] T-714  Integration test: full answer flow incl. closed-question rejection              [—]
```

Acceptance checkpoint: student answers the active question; duplicate answers rejected; closed questions and closed/paused sessions reject answers; open-text answers stored safely.

---

## Phase 8 — Live Results

Goal: near-real-time instructor feedback.

```text
[x] T-800  Migration 0002: all secondary indexes per DATA_MODEL §4                        [NFR-04]
[x] T-801  ReportService::aggregate() — counts + percentages per option                    [FR-51]
[x] T-802  ReportService — open-text answer list with nickname + timestamp                 [FR-52]
[x] T-803  GET /api/v1/sessions/{id}/results?question_id=...                               [FR-50..FR-52]
[x] T-804  Student-visible results endpoint, gated by show_results flags                   [FR-53]
[x] T-805  Admin live page — polls results every 2s, Chart.js render                       [FR-50, NFR-02]
[x] T-806  Student client — polls active-question every 3s                                 [FR-45]
[x] T-807  Projector view — large-type live results                                        [FR-54]
[x] T-808  show_results_to_students + per-question show_results toggle UI                   [FR-53]
[x] T-809  moderation_mode: hide/unhide open-text answers                                   [FR-55]
[x] T-810  POST /api/v1/answers/{id}/hide + /unhide                                         [FR-55]
[x] T-811  Unit tests: aggregation math, percentage rounding                                [NFR-52]
[x] T-812  Performance check: 100 concurrent answer submissions, p50 < 300 ms               [NFR-01]
```

Acceptance checkpoint: instructor sees live results updating; multiple-choice as charts; open-text as a safe list; results refresh automatically; projector view is classroom-readable.

---

## Phase 9 — Reports & Export

Goal: post-session reports.

```text
[x] T-900  ReportService::buildReport() — metadata + summary + per-question breakdown      [FR-60, FR-61]
[x] T-901  GET /api/v1/sessions/{id}/report (JSON)                                         [FR-60, FR-61]
[x] T-902  GET /api/v1/sessions/{id}/report.csv?anonymize=                                 [FR-62]
[x] T-903  CSV formula-injection protection (prefix =,+,-,@ cells)                          [SEC §8]
[x] T-904  GET /api/v1/sessions/{id}/report.html?anonymize= (printable)                    [FR-63]
[x] T-905  Admin report page linked from session detail                                    [FR-60]
[x] T-906  POST /api/v1/sessions/{id}/anonymize                                            [FR-70]
[x] T-907  DELETE /api/v1/sessions/{id} (soft delete, 7-day grace)                         [FR-71]
[x] T-908  bin/cleanup.php — hard-delete after grace, auto-anonymize after 365d            [FR-71, NFR-34]
[x] T-909  Reports require instructor auth; no public report URL                           [FR-74]
[x] T-910  Device hash + IP never in any report or export                                  [FR-72, FR-73]
[x] T-911  i18n keys for report + CSV headers                                              [FR-80]
[x] T-912  Unit tests: report builder, anonymization                                        [NFR-52]
```

Acceptance checkpoint: instructor opens a session report with correct counts and distributions; CSV export works and is anonymizable; deletion and anonymization work; no device hash or IP appears anywhere.

---

## Phase 10 — Security, Privacy & Quality Hardening

Goal: make the MVP production-ready.

```text
[x] T-1000  Migration 0003: audit_logs table                                              [FR-90]
[x] T-1001  AuditLogRepository + writes for all FR-90 actions                              [FR-90]
[x] T-1002  Security headers (CSP, HSTS, X-Frame, X-CTO, etc.) on every response           [NFR-25]
[x] T-1003  RateLimitMiddleware — login + join + answer throttling                          [FR-05, SEC §14]
[x] T-1004  Review every instructor route for AuthMiddleware coverage                       [NFR-23]
[x] T-1005  Review every template for htmlspecialchars on user content                      [NFR-22]
[x] T-1006  Review every repository for prepared statements only                            [NFR-21]
[x] T-1007  Logging discipline audit — no secrets/answers/hashes in logs                    [NFR-73]
[x] T-1008  bin/rotate-secret.php + server_secret in .env                                   [SEC §19]
[x] T-1009  i18n completeness check in CI (en/tr parity)                                    [FR-81]
[x] T-1010  Service + repository unit-test coverage >= 60%                                  [NFR-52]
[x] T-1011  bin/smoke.php — hits all GET endpoints, expects 200/expected codes              [—]
[x] T-1012  deploy/apache.htaccess.example + deploy/nginx.conf.example                      [NFR-60]
[x] T-1013  deploy/cpanel-notes.md — step-by-step shared-hosting install                    [NFR-62]
[x] T-1014  Nightly mysqldump backup script -> outside web root                             [SEC §17]
[x] T-1015  Deployment hardening checklist (SEC §21) all green                              [SEC §21]
[x] T-1016  README quick-start verified on a clean cPanel account                           [NFR-15]
```

Acceptance checkpoint: hardening checklist in `SECURITY_PRIVACY.md` §21 is fully green; en/tr parity holds; smoke script passes; the MVP is ready for a classroom pilot.

---

## Phase 11 — Future Enhancements (post-MVP)

Not part of the MVP. Each item must be escalated to the human owner before work starts.

```text
[x] T-1100  AI-assisted open-text theme extraction                                         [FR-65]
[x] T-1101  Word cloud generation from open-text answers                                    [FR-66]
[x] T-1102  PDF report export (locale-aware fonts)                                          [FR-63]
[x] T-1103  Cross-session course-level analytics                                            [FR-64]
[x] T-1104  Quiz mode with scoring (uses options.is_correct)                                [FR-92]
[x] T-1117  Course-scoped question bank                                                     [FR-93, FR-95]
[x] T-1118  LLM question generation from lecture notes                                      [FR-94]
[x] T-1119  Admin UI: generate bank questions and copy them into sessions                   [FR-93, FR-94, FR-95]
[ ] T-1105  Light gamification (badges, streaks)                                            [FR-48]
[x] T-1106  Question image attachments                                                      [FR-39]
[x] T-1107  Email-based password reset                                                      [FR-06]
[ ] T-1108  Add de.json, fr.json (>= 95% coverage each)                                     [FR-86]
[ ] T-1109  RTL support + ar.json                                                           [FR-86]
[ ] T-1110  WebSocket / Socket.IO real-time (replaces polling)                              [NFR-02]
[x] T-1111  Health-check endpoint /api/v1/health                                            [NFR-72]
[x] T-1112  Admin audit-log viewer UI                                                       [FR-91]
[ ] T-1113  LMS integration (Moodle / Canvas export)                                        [—]
[ ] T-1114  Multi-instructor course ownership                                               [—]
[ ] T-1115  Containerize (docker-compose: PHP + MySQL [+ Node later])                       [—]
[x] T-1116  Question import V2 (legacy questions[] and staged sections opening->middle->closing, processing order, metadata, error: invalid_import_payload) [FR-31]
```

---

## Conventions for Editing This File

- Tick `[x]` only when a task is **shipped** — code, tests, docs, and locale keys all done.
- Keep completed tasks for one release cycle; they are useful for retrospectives.
- New tasks go to the end of the phase they belong to and get the next free ID in that phase's range.
- A task with no `FR-` / `NFR-` reference cannot be started — add the requirement first.
- Migration tasks reference `DATA_MODEL.md` sections; keep `schema.sql` and `DATA_MODEL.md` in sync as you go.
