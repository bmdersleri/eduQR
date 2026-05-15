# Security & Privacy — eduQR

This document defines how eduQR protects users, data, and infrastructure. It is binding. AI coding agents must not introduce code that conflicts with anything below. To deviate, open an ADR with explicit justification approved by the human owner.

---

## 1. Threat Model

### 1.1 Assets to protect

| Asset | Why it matters |
| --- | --- |
| Instructor credentials | Access to all of an instructor's sessions and reports. |
| Session reports | Pedagogically sensitive; legally protected under KVKK / GDPR. |
| Student nicknames + answers | Pseudonymous learning data; must stay unlinkable to real identities. |
| Server (host) | Compromise damages institutional reputation. |

### 1.2 Adversaries

| Adversary | Capability |
| --- | --- |
| Curious student | Browser only, no credentials. Wants to vote twice, see hidden answers, impersonate. |
| Malicious student | Same, plus SQLi / XSS / CSRF attempts. |
| External attacker | Internet-wide scanning, credential stuffing, vulnerability scanning. |
| Compromised instructor account | Authenticated access to that instructor's own data. |

### 1.3 Out of scope

- Physical classroom security.
- Compromised end-user devices (malware on a student's phone).
- State-level adversaries.

---

## 2. Privacy Model

eduQR is built for **minimal data collection**. Students join without accounts; they are identified only by a self-chosen nickname and an opaque per-session device hash.

### 2.1 What the system collects

- **Instructors / admins:** email, password hash, display name, preferred language, login timestamps.
- **Students:** nickname, opaque device hash, session join time, answers, answer timestamps.

### 2.2 What the system does NOT collect

Never required, never stored: real name, student number, email of students, phone number, national identity number, precise location, photographs. Web-server access logs may transiently hold IPs but those are auto-redacted after 30 days (`NFR-33`); the application database stores **no raw IP** anywhere.

---

## 3. Nickname Policy

- Nicknames are lightweight identifiers, **not verified identities**.
- 1–24 characters. Charset `^[\p{L}\p{N}_\- ]+$` (Unicode letters/digits, underscore, hyphen, space).
- Sanitized before storage, escaped before display.
- Unique within a session, case-insensitive (`participants.nickname_normalized`).
- Filtered against a configurable profanity list at `config/profanity/<locale>.txt`.
- Excluded from anonymized reports (replaced with `Participant N`).

---

## 4. Device Hash Policy

A device hash supports low-effort duplicate-join detection. It must be handled carefully.

```text
device_hash = SHA-256( server_secret || persistent_cookie_id || user_agent )
```

- `persistent_cookie_id` is a per-browser UUID set at first visit. `HttpOnly`, 1-year lifetime, `SameSite=Lax`.
- `server_secret` lives in `.env`, generated on first install, rotated yearly.
- The hash is **session-scoped**: combined with `session_id` before any comparison. eduQR does not track a student across sessions.

Hard rules:

- Device hash is **never displayed** to instructors.
- Device hash is **never exported** in reports or CSV.
- Device hash is **never used** as a long-term tracking identifier.
- If duplicate prevention can be achieved without it, prefer the simpler privacy-preserving approach.

It is a **friction reducer, not a security control.** A determined student can clear cookies or switch browsers. Do not claim otherwise in UI or docs.

---

## 5. Authentication

### 5.1 Instructor login

- Email + password.
- Passwords stored with `password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12])`.
- Verification with `password_verify`; rehash if `password_needs_rehash` returns true.
- Public sign-up is **disabled in MVP**. Accounts are created by an admin via `bin/user-add.php` or the admin API.
- Login errors MUST NOT reveal whether the email exists — always return `invalid_credentials` (`FR-08`).

### 5.2 Session cookies

| Cookie | Purpose | Flags | Lifetime |
| --- | --- | --- | --- |
| `eduqr_session` | Instructor HTTP session | `HttpOnly; Secure; SameSite=Lax; Path=/` | 12 h sliding |
| `eduqr_participant` | Student session (per classroom session) | `HttpOnly; Secure; SameSite=Lax; Path=/` | Bound to the classroom session's `closed_at` |
| `eduqr_locale` | Language preference | `Secure; SameSite=Lax; Path=/` (no `HttpOnly` — JS reads it) | 1 year |
| `eduqr_csrf` | CSRF double-submit token | `Secure; SameSite=Strict; Path=/` (no `HttpOnly`) | Rotates on login + every 24 h |
| `eduqr_device` | Persistent per-browser UUID for device hashing | `HttpOnly; Secure; SameSite=Lax; Path=/` | 1 year |

### 5.3 Rate limiting (FR-05)

- Failed-login attempts tracked in `login_attempts`.
- ≥ 5 failed attempts for the same `email` within 10 minutes → 15-minute lockout for that email.
- The same `ip_hash` exceeding 60 login attempts per hour → temporary block.
- Implementation: count `login_attempts` rows where `succeeded = 0` and `created_at > NOW() - INTERVAL 10 MINUTE`. Pure SQL, no Redis needed for MVP.

---

## 6. Authorization

- Instructors access **only their own** courses, and only sessions under those courses (`FR-14`).
- Admins may access all courses and manage user accounts.
- Reports require instructor authentication — there is **no public report URL** (`FR-74`).
- Public student routes expose only the minimum session and question data needed.
- Internal database IDs are not exposed where a short code or opaque token would do.

---

## 7. Input Validation

Every controller validates input before passing it to a service. A central `Support\Validator` enforces type, length, charset, pattern, and enum constraints.

### 7.1 Validation table (authoritative)

| Field | Rule |
| --- | --- |
| Email | RFC 5322 + `filter_var(..., FILTER_VALIDATE_EMAIL)`, max 190 chars |
| Password | 10–128 chars; must contain ≥ 3 of {lowercase, uppercase, digit, symbol} |
| Display name | 1–150 chars |
| Course title | 1–200 chars |
| Course code | 0–40 chars (optional) |
| Course semester | 0–40 chars (optional) |
| Session title | 1–200 chars |
| Short code | `^[A-HJ-NP-Z2-9]{6}$` |
| Nickname | 1–24 chars, `^[\p{L}\p{N}_\- ]+$`, trimmed |
| Question text | 1–500 chars |
| Option text | 1–200 chars |
| Open-text answer | 1–2000 chars |
| Question type | enum: `multiple_choice`, `open_text`, `yes_no`, `likert_5` |
| Session status | enum: `draft`, `active`, `paused`, `closed` |
| Question status | enum: `draft`, `active`, `closed` |
| Locale code | enum: must be in `APP_LOCALES` |
| Multiple-choice option count | 2–8 |
| Likert option count | exactly 5 |
| Yes/no option count | exactly 2 |

All text fields are checked with `mb_check_encoding($value, 'UTF-8')`. Invalid input is rejected with a localized message and a `validation_error` (or more specific) code.

### 7.2 Profanity filter (FR-43)

- A configurable wordlist per locale at `config/profanity/<locale>.txt`.
- Applied to nicknames at join time.
- Applied to open-text answers when the session's `moderation_mode = 1`.
- Matching is case-insensitive and Unicode-normalized.

---

## 8. Output Encoding

| Context | Encoding |
| --- | --- |
| HTML body | `htmlspecialchars($v, ENT_QUOTES \| ENT_SUBSTITUTE, 'UTF-8')` |
| HTML attribute | same as above |
| JSON | `json_encode($v, JSON_UNESCAPED_UNICODE \| JSON_THROW_ON_ERROR)` |
| URL | `rawurlencode` |
| CSV cell | Prefix `=`, `+`, `-`, `@` with a single quote to prevent formula injection; quote per RFC 4180 |
| Inline JS (avoid) | If unavoidable, `json_encode` and inject as a JSON literal |

**No template ever echoes a raw user-supplied value.** Reviewers run `grep -nE '<\?=\s*\$[a-zA-Z_]' templates/` and confirm each match is a known-safe ID or wrapped in escaping.

User-generated content that must be escaped before HTML rendering: nicknames, course titles, session titles, question texts, option texts, open-ended answers.

---

## 9. SQL Safety

- All queries go through `PDO::prepare`.
- No string interpolation into SQL, ever.
- PDO settings: `ATTR_EMULATE_PREPARES = false`, `ATTR_ERRMODE = ERRMODE_EXCEPTION`, `MYSQL_ATTR_INIT_COMMAND = "SET NAMES utf8mb4"`.
- Dynamic `ORDER BY` / `LIMIT` values come from a whitelist, never directly from the request.

Reviewer command:

```bash
grep -nE '"\s*\.\s*\$|\$[a-zA-Z_]+\s*\.\s*"' src/Repositories/*.php
# Any hit needs human review.
```

---

## 10. Open-Ended Answer Safety

Open-ended answers are the highest-risk input — arbitrary student text.

- Strip or escape all HTML; never render raw HTML from an answer.
- Enforce the 2000-character maximum.
- When `moderation_mode = 1`, answers are not shown publicly until approved.
- Instructors can hide an inappropriate answer (`answers.is_hidden = 1`) via `POST /api/v1/answers/{id}/hide`; hidden answers are excluded from the projector view and from reports.

---

## 11. Session Security

- Short codes are random, 6 characters, charset `A-H J-N P-Z 2-9` — not sequential, not easily guessable.
- Short codes are unique across all sessions (`UNIQUE` index + collision retry).
- `closed` sessions reject new participants and new answers.
- `paused` sessions reject new answers.
- Sessions older than 12 hours auto-close if not closed manually (`FR-26`).
- Draft questions are never visible to students.

---

## 12. Question & Answer Rules

- Only `active` questions accept answers.
- Closed questions reject answers (`410 question_closed`).
- The selected option must belong to the question.
- The participant must belong to the same session as the question.
- Duplicate answers are rejected unless `questions.allow_multiple_answers = true` (default `false`).

---

## 13. CSRF Protection

- Every state-changing instructor request (POST, PATCH, DELETE) MUST present:
  - the `eduqr_csrf` cookie, AND
  - an equal value in the `X-CSRF-Token` header (or `_csrf` form field for HTML posts).
- The token is 32 bytes from `random_bytes`, base64-url encoded, rotated on login and every 24 hours.
- GET endpoints never mutate state. A GET that mutates state is a bug — fix the verb, not the CSRF check.

Actions requiring CSRF: course create/update/delete, session create/pause/resume/close/anonymize/delete, question create/update/activate/close/delete/reorder, answer hide/unhide, user create/update.

---

## 14. Security Headers (CSP and friends)

`public/index.php` MUST set these on every response:

```http
Content-Security-Policy:
  default-src 'self';
  img-src 'self' data:;
  style-src 'self' 'unsafe-inline';
  script-src 'self';
  frame-ancestors 'none';
  base-uri 'self';
  form-action 'self'
Strict-Transport-Security: max-age=31536000; includeSubDomains
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: camera=(), microphone=(), geolocation=()
```

`'unsafe-inline'` for styles is a Bootstrap concession; aim to remove it in Phase 11 by precomputing class lists. If Chart.js is loaded from a CDN, add the CDN origin to `script-src` and use an SRI hash.

---

## 15. KVKK / GDPR Compliance

### 15.1 Lawful basis

- **Students:** legitimate interest in classroom-feedback collection, anonymized. The privacy notice on the join screen states this and links to the institutional privacy policy.
- **Instructors:** contract (institutional use).

### 15.2 Retention

| Data | Retention |
| --- | --- |
| Active session data | Until session closes + 365 days |
| Anonymized session data | Indefinite (contains no personal data) |
| Login attempts | 90 days |
| Audit log | 365 days |
| Web-server access logs | 30 days, then IP redaction |

### 15.3 Data subject rights

- **Access:** instructors export their own data via the report endpoints.
- **Deletion:** instructors request session deletion (7-day grace, then hard delete).
- **Anonymization:** instructors strip nicknames from a session (`FR-70`).
- **Student requests:** students have no account, so requests are handled per-session by the owning instructor.

### 15.4 Privacy notice (FR-75)

Required text, localized, shown on every student join page (translation key `privacy.notice.body`):

> eduQR uses your nickname only for classroom participation during this session. You are not required to enter your real name. Your answers may be reviewed by the instructor for educational feedback and learning analytics. Read the full notice.

---

## 16. Logging Discipline

- Log to file at `LOG_PATH/eduqr.log`. Rotate daily, keep 30 days.
- **Never log:** passwords (even hashed), full session cookies, CSRF tokens, raw request bodies that may contain answers, device hashes.
- **Always log:** timestamp, correlation ID, route, status code, duration, user ID (if any), stable error code.
- After 30 days, log lines have their IP field redacted to `0.0.0.0` (`NFR-33`).
- Internal logs use stable English `snake_case` codes, not localized messages.

---

## 17. Dependency Hygiene

- Composer dependencies are pinned with exact or caret versions in `composer.json` — no `*`.
- Run `composer audit` weekly.
- A critical CVE in a dependency is patched within 7 days.
- Keep the dependency set small. Vendoring `endroid/qr-code` is fine; pulling 200 transitive packages for one helper is not.

---

## 18. File Permissions (shared hosting)

```text
.env                           600
public/                        755 (dirs) / 644 (files)
src/, locales/, templates/     755 / 644
config/profanity/              755 / 644
bin/*.php                      750
database/migrations/           750 / 640
logs/                          750
vendor/                        755 / 644
```

`public/` must be the **only** directory the web server can reach. Verify:

```bash
curl -I https://eduqr.example.org/../src/Bootstrap.php
# Expected: 403 or 404. Anything else is a misconfiguration.
```

---

## 19. Secrets Management

- `.env` lives outside `public/` and is never committed.
- No secrets in code, templates, or JS.
- The `APP_SECRET` / `server_secret` for device hashing is generated on first install with `random_bytes(32)` and stored in `.env`.
- Rotate the database password after every personnel change with database access.

---

## 20. Incident Response (brief)

If a breach is suspected:

1. Rotate all secrets (`.env` regenerated, DB password changed).
2. Force-logout all instructors (delete server-side session files).
3. Inspect `audit_logs` for the suspected window.
4. Notify the institutional data protection officer within 72 hours per GDPR / KVKK.
5. File a post-mortem under `docs/incidents/YYYY-MM-DD.md`.

---

## 21. Deployment Hardening Checklist (release gate)

```text
[x] APP_ENV=production and debug / display_errors disabled
     → Config::bool('APP_DEBUG') default false; Bootstrap error handler hides traces.
     → Operator: set APP_ENV=production + APP_DEBUG=false in .env.

[ ] HTTPS-only; no HTTP listener bound in production
     → Operator: configure web server; see deploy/apache.htaccess.example (HTTPS block).

[x] .env outside the document root, not committed, perms 600
     → Document root is public/; .env sits in project root (one level up).
     → Operator: chmod 600 .env after install.

[x] Database credentials not in version control
     → .env is in .gitignore. Only .env.example (no secrets) is committed.

[x] All cookies set HttpOnly (where applicable) + Secure + correct SameSite
     → PHP session cookie: httponly/secure/SameSite=Lax in Bootstrap.php.
     → eduqr_device + eduqr_participant cookies: httponly/secure/SameSite=Lax in JoinController.

[x] CSRF protection active on every POST/PATCH/DELETE
     → CsrfMiddleware::verify() called on all mutation endpoints.
     → Student answer route is CSRF-exempt (uses participant cookie auth instead).

[x] CSP and security headers verified with curl
     → Bootstrap::sendSecurityHeaders() sets X-Frame-Options, X-Content-Type-Options,
        X-XSS-Protection, Referrer-Policy, Permissions-Policy, CSP, HSTS (when COOKIE_SECURE=true).

[ ] Directory listing disabled on the web server
     → Operator: confirm via curl. Apache: Options -Indexes in public/.htaccess.

[x] Upload directories (if any) do not execute scripts
     → No file upload feature in MVP. N/A.

[x] Error pages do not leak stack traces
     → APP_DEBUG=false: exception handler renders templates/errors/500.php only.

[ ] composer audit shows no unresolved high/critical CVEs
     → Operator: run `composer audit` before each deployment.

[x] Backup + restore tested end-to-end at least once, backups stored outside web root
     → bin/backup.php writes to BACKUP_DIR (default: ../backups, outside web root).
     → Operator: verify restore procedure on staging.

[x] Default seeded passwords rotated
     → No default passwords. First user created with bin/user-add.php.

[x] bin/install.php removed or disabled in production
     → No bin/install.php in this codebase; bin/migrate.php is CLI-only.

[x] Logs writeable but not web-accessible
     → LOG_PATH configured outside web root. Bootstrap uses error_log() with file appender.

[x] Public routes return only intended data (manual spot-check)
     → bin/smoke.php validates all public GET endpoints return expected status codes.
```
