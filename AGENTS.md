# AGENTS.md — Rules for AI Coding Agents

This file is the **first thing** any AI coding agent reads before touching this repository. That includes Claude Code, OpenAI Codex CLI, Google Antigravity, Cursor, Aider, Windsurf, and any future tool.

If this file conflicts with another document in the repo, **this file wins on process and conventions; the other document wins on its own subject matter** (e.g. `DATA_MODEL.md` is authoritative on schema, `API_SPEC.md` on endpoints).

A symlink or copy of this file at `CLAUDE.md` is acceptable for tools that look for that name specifically.

---

## 1. Project Identity

- **Name:** eduQR
- **Full title:** QR-Based Interactive Classroom Polling and Learning Analytics Platform
- **Tagline:** Interactive learning starts with a scan.
- **One-line:** A self-hostable, multilingual, privacy-first classroom-polling tool where students scan a QR, enter a nickname, and answer questions while the instructor watches live results and exports a per-session report.

---

## 2. Reading Order Before You Code

For any non-trivial task, read in this order:

1. `AGENTS.md` (this file) — the rules.
2. `README.md` — orientation + locked decisions.
3. `GLOSSARY.md` — clears up overloaded terms.
4. `PROJECT_BRIEF.md` — what we are building and why.
5. `PRODUCT_REQUIREMENTS.md` — FR / NFR IDs that you must reference.
6. The spec file most relevant to your task:
   - Schema changes → `DATA_MODEL.md`
   - Endpoint work → `API_SPEC.md`
   - UI work → `I18N_SPEC.md` (every string is translated)
   - Auth / validation / privacy → `SECURITY_PRIVACY.md`
   - Tech / folder questions → `SYSTEM_ARCHITECTURE.md`
7. `TASKS.md` — find the phase and task your work belongs to.
8. `ACCEPTANCE_CRITERIA.md` — know what "done" means.

If you cannot answer the question "which `FR-xx` or `NFR-xx` does my change satisfy?" you are not ready to code. Go back to `PRODUCT_REQUIREMENTS.md`.

Implementation plans for larger multi-step work live under `docs/superpowers/plans/`. Start with the plan that matches the current work item before touching code.

---

## 3. The Five Iron Laws

These are violated only with explicit human approval, never by an agent on its own initiative.

### Law 1 — No hardcoded user-facing strings

Every label, button, error, and message that a human will read goes through the `t()` helper backed by `locales/<code>.json`. If you find yourself typing English or Turkish text into a template, controller, JS file, or validator, **stop**. Add a key to `locales/en.json` and `locales/tr.json` and reference it.

```php
// ❌ Wrong
return ['error' => 'Session is closed.'];

// ✅ Right
return ['error' => ['code' => 'session_closed', 'message' => t('error.session_closed')]];
```

### Law 2 — No string-concatenated SQL

Always prepared statements. Always.

```php
// ❌ Wrong
$db->query("SELECT * FROM sessions WHERE short_code = '$code'");

// ✅ Right
$stmt = $db->prepare('SELECT * FROM sessions WHERE short_code = :code');
$stmt->execute(['code' => $code]);
```

### Law 3 — No unescaped user output

Every variable interpolated into HTML goes through `htmlspecialchars($v, ENT_QUOTES, 'UTF-8')`. JSON output uses `json_encode($v, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)`.

### Law 4 — One requirement per commit

Every commit message references at least one `FR-xx` or `NFR-xx` identifier. If no requirement applies, the requirement is missing — add it to `PRODUCT_REQUIREMENTS.md` first.

```text
feat(session): generate 6-char short_code with no ambiguous glyphs [FR-21]
```

### Law 5 — Small, reviewable changes

Cap a single change at roughly 600 lines of diff. Bigger work is split. An agent that produces 3,000-line PRs is not being helpful — it is being unreviewable.

---

## 4. Locked Technical Choices (Do Not Re-Litigate)

These are restated from `README.md`. They are not open questions. **Do not propose alternatives unless the human explicitly asks.**

| Area | Choice |
| --- | --- |
| PHP version | 8.2+ |
| Framework | None. Plain PHP + thin custom router. |
| Database | MySQL 8.0+ / MariaDB 10.6+, `utf8mb4_unicode_ci` |
| Auth table | `users` with `role ENUM('admin','instructor')` |
| Primary keys | `BIGINT UNSIGNED AUTO_INCREMENT` |
| Frontend | Server-rendered PHP + Bootstrap 5 + Vanilla ES2022 |
| Realtime | HTTP polling (no WebSockets in MVP) |
| Charts | Chart.js |
| QR | `endroid/qr-code` (server-side) |
| API base path | `/api/v1/` |
| Response envelope | `{success, data?, message?, error?}` |
| Error codes | `snake_case` stable strings |
| Locale files | JSON, file-based (not DB-stored) |
| Default locale | `en` |
| Initial locales | `en`, `tr` |
| Placeholder syntax | `{name}` |
| Password hashing | `password_hash` with `PASSWORD_BCRYPT`, cost 12 |
| Session code | 6 chars, charset `A-H J-N P-Z 2-9` |
| CSRF | Double-submit cookie pattern |
| Question types | `multiple_choice`, `open_text`, `yes_no`, `likert_5` |
| Session statuses | `draft`, `active`, `paused`, `closed` |
| Question statuses | `draft`, `active`, `closed` |
| Polling intervals | Instructor 2 s, Student 3 s |
| Public sign-up | Disabled in MVP; admins create accounts |

---

## 5. Folder Discipline

Every new file has exactly one right home. If you cannot answer "which folder?" in five seconds, you are mixing concerns — split the change.

```text
public/        ← only this is web-accessible
src/
  Controllers/{Admin,Api,Public}   ← HTTP entry, validation only
  Services/                        ← business rules, no SQL
  Repositories/                    ← all SQL, no business rules
  Middleware/                      ← cross-cutting (Auth, Csrf, I18n)
  Support/                         ← Database, ShortCode, Validator
templates/                         ← view partials, escape every variable
locales/                           ← <code>.json — translation files
database/{schema.sql, migrations/, seeds/}
bin/                               ← CLI helpers
tests/{Unit, Integration}
docs/adr/                          ← architecture decision records
```

Forbidden:

- SQL inside templates.
- SQL inside controllers (it lives in repositories).
- Business logic inside repositories (it lives in services).
- HTML inside services or repositories.
- Translation strings inside the database.
- Build steps for the student-facing pages.

---

## 6. Naming Conventions

| Thing | Convention | Example |
| --- | --- | --- |
| PHP class | `PascalCase` | `SessionService` |
| PHP method, variable | `camelCase` | `findActiveByCode()` |
| PHP constant | `UPPER_SNAKE_CASE` | `MAX_ANSWER_LENGTH` |
| File (one class per file) | Match class name | `SessionService.php` |
| Namespace | Match folder | `EduQR\Services` |
| DB table | `snake_case`, plural | `participants` |
| DB column | `snake_case` | `selected_option_id` |
| Foreign key constraint | `fk_<table>_<col>` | `fk_answers_question` |
| JSON key in API | `snake_case` | `participant_id` |
| Error code | `snake_case` | `duplicate_nickname` |
| Translation key | `area.screen.element` | `auth.login.submit` |
| JS file | `kebab-case.js` | `live-results-poller.js` |
| Route segment | `kebab-case` | `/active-question` |
| Cookie | `snake_case`, `eduqr_` prefix | `eduqr_session` |

---

## 7. The Three Things You Must Always Do (per feature)

### 7.1 Translate

For every user-visible string:

1. Add the key to **both** `locales/en.json` and `locales/tr.json`.
2. Reference it with `t('the.key')` in code and `t('the.key', ['name' => $value])` for placeholders.
3. Validate locale coverage stays ≥ 95% with `php bin/locale-check.php tr`.

### 7.2 Reference a requirement

Find the matching `FR-xx` or `NFR-xx`. Cite it:

- In the commit message: `feat(answer): enforce one-answer-per-question [FR-44]`
- In the test name: `function test_one_answer_per_participant_FR44(): void`
- In the docblock when non-obvious: `@requirement FR-44`

If no requirement exists, **add it to `PRODUCT_REQUIREMENTS.md` first**, then code.

### 7.3 Test

Add at least one PHPUnit test per code path. Tests live in `tests/Unit/` (no DB) and `tests/Integration/` (real DB). Naming:

```text
tests/Unit/Services/SessionServiceTest.php
  function test_start_session_generates_unique_short_code(): void
```

Service-layer tests target ≥ 60 % coverage at MVP. Don't pad with trivial tests — test the business rule.

---

## 8. Definition of Done

A task ships only when **all** of these are true:

```text
[ ] Code matches the spec — no hardcoded strings, prepared statements only, output escaped
[ ] New / changed user-visible strings exist in BOTH en.json and tr.json
[ ] Unit + integration tests pass locally (composer test)
[ ] PHP-CS-Fixer is clean (composer lint)
[ ] composer audit shows no new high/critical CVEs
[ ] Schema change → new migration file in database/migrations/, schema.sql updated, DATA_MODEL.md updated
[ ] API change → API_SPEC.md updated BEFORE the code
[ ] Commit message references ≥ 1 FR / NFR id
[ ] Related ACCEPTANCE_CRITERIA item is now satisfiable
[ ] Non-obvious decision → ADR added under docs/adr/
```

---

## 9. Standard Workflows

### 9.1 Adding a new API endpoint

1. Define it in `API_SPEC.md`: path, method, auth, request body, success response, error codes.
2. Add `FR-xx` to `PRODUCT_REQUIREMENTS.md` if one does not exist.
3. Create / extend a controller in `src/Controllers/Api/`.
4. Put validation in the controller. Put business logic in a service. Put SQL in a repository.
5. Register the route in `src/Router.php`.
6. Add error codes to `API_SPEC.md` §12 if new ones were introduced.
7. Add translation keys for any user-facing strings (error messages).
8. Write tests.
9. Commit with the `FR-xx` reference.

### 9.2 Adding a new question type

1. Update `PRODUCT_REQUIREMENTS.md` FR-31 with the new enum value.
2. Add migration: `ALTER TABLE questions MODIFY question_type ENUM(...)`. **Append-only** — never edit existing migrations.
3. Extend `QuestionService::validateForType()`.
4. Extend `AnswerService::validateAnswerShape()`.
5. Extend `ReportService::aggregateByType()`.
6. Add template partial at `templates/student/question/<new-type>.php`.
7. Add locale keys `question.type.<new_type>` to both `en.json` and `tr.json`.
8. Update `API_SPEC.md` examples for the new type.
9. Tests.

### 9.3 Adding a new locale

Follow `I18N_SPEC.md` §12 exactly. Do not check off any item until coverage is ≥ 95 % and a maintainer has done a sample smoke test in that language.

### 9.4 Schema change

1. Add a new migration file `database/migrations/00NN_<verb>_<thing>.sql`. Sequential prefix.
2. Update `database/schema.sql` to reflect the cumulative state.
3. Update `DATA_MODEL.md`.
4. Update affected repositories.
5. Update affected services.
6. Update tests.

**Never edit an already-applied migration.** To change something, write a new migration.

---

## 10. Anti-Patterns — Do Not Do

- ❌ Add Laravel / Symfony / Slim / "small framework X" "for convenience" during MVP.
- ❌ Add a Node.js dependency to a PHP project targeting shared hosting.
- ❌ Introduce a build step (Vite, Webpack, esbuild) for the student-facing pages.
- ❌ Add OAuth, SSO, or any third-party identity provider in MVP.
- ❌ Store translation strings in the database.
- ❌ Use `eval`, variable-string `assert`, `unserialize` on user input, or `extract` on request data.
- ❌ Log raw request bodies that contain answers, passwords, or cookies.
- ❌ Add analytics trackers (Google Analytics, Mixpanel, Hotjar, etc.) — privacy-hostile.
- ❌ Work around the CSP rather than fixing the underlying cause.
- ❌ Produce > 600-line changes without a plan reviewed by the human.
- ❌ Echo a variable into HTML without `htmlspecialchars`.
- ❌ Mock data / placeholder content in production code paths.
- ❌ Auto-translate `tr.json` with an LLM and ship without human review.

---

## 11. When to Stop and Ask

Pause and ask the human owner before doing any of these:

- Dropping or renaming a database column.
- Removing or weakening any `MUST` requirement.
- Changing the auth model.
- Adding a third-party service that touches student data.
- Disabling a security control "temporarily".
- Producing more than ~600 lines of new code in one change.
- Doing anything not covered by an existing `FR-xx` or `NFR-xx`.

When in doubt: ask one clarifying question. It is always cheaper than reverting a wrong-direction PR.

---

## 12. Commit & PR Format

Conventional Commits with a requirement ID:

```text
<type>(<scope>): <subject> [FR-xx | NFR-xx]

<body — what, why, not how>

Refs: FR-xx, NFR-yy
```

`<type>` ∈ `feat | fix | refactor | docs | test | chore | perf | security | i18n`

Good examples:

```text
feat(session): generate 6-char short_code with no ambiguous glyphs [FR-21]
fix(answer): reject submissions to closed questions [FR-44, NFR-21]
i18n(report): add tr.json keys for CSV export labels [FR-80]
security(auth): rate-limit failed logins by email [FR-05]
docs(api): document /api/v1/sessions/{id}/anonymize [FR-70]
```

---

## 13. Tone for Agent-Generated Text

For commit messages, comments, and user-facing strings:

- Plain, direct English. No marketing fluff.
- Turkish strings: academic-yet-approachable register. Second-person informal (`sen`) for student-facing UI, formal (`siz`) for instructor-facing UI.
- Verbs over nouns ("Submit" beats "Submission").
- No exclamation marks in error messages.
- No emoji in user-facing strings or commit messages.

---

## 14. Useful Commands

```bash
# Install
composer install

# Dev server
php -S localhost:8080 -t public/

# Migrations (idempotent)
php bin/migrate.php

# Seed demo data
php bin/seed.php demo

# Create a new instructor account
php bin/user-add.php instructor "demo@example.org"

# Tests
composer test

# Lint
composer lint

# i18n coverage check
php bin/locale-check.php tr

# Rotate server secret (dev only)
php bin/rotate-secret.php
```

---

## 15. When the Human Owner is Ambiguous

The human owner is **Prof. Dr. İsmail Kırbaş**. He is technically fluent, prefers concise and structured answers, and tends to bias toward action. If a request from him conflicts with this file:

1. State the conflict back to him in one sentence.
2. Propose the smallest possible deviation that addresses his goal.
3. Wait for confirmation.

Do not "improve" the spec without his approval.

---

## 16. Acknowledgment

By acting on this repository, you accept these conventions. If you discover a rule that is unclear, contradictory, or out of date, **fix the rule first** (as a docs commit) and then write code that follows the fixed rule.
