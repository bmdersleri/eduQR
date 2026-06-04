# Product Requirements — eduQR

This document enumerates every functional (`FR-xx`) and non-functional (`NFR-xx`) requirement for the MVP. Every code change must reference at least one ID in its commit message and tests. When a feature is unclear, the answer is here — and if it is not here, the requirement is missing and should be added before coding.

If a requirement contradicts another document, **this file wins** on the question of "what must the system do?"; the other documents win on "how it is built" (`SYSTEM_ARCHITECTURE.md`), "what the schema is" (`DATA_MODEL.md`), or "what the endpoint looks like" (`API_SPEC.md`).

---

## 1. Conventions

- **`MUST`** — required for MVP. No MVP without it.
- **`SHOULD`** — strongly desired for MVP; can be deferred only with explicit owner approval, documented in `docs/adr/`.
- **`MAY`** — nice to have; reserved for later phases.

Every ID is stable for the life of the project. Do not renumber. To deprecate a requirement, mark it `[DEPRECATED]` and leave the ID in place.

---

## 2. Roles

| Role | DB representation | Notes |
| --- | --- | --- |
| Admin | `users.role = 'admin'` | Creates/manages instructor accounts |
| Instructor | `users.role = 'instructor'` | Owns courses, runs sessions |
| Participant (student) | row in `participants` (no `users` row) | Anonymous, session-scoped |

---

## 3. Functional Requirements

### 3.1 Authentication & Accounts

| ID | Priority | Requirement |
| --- | --- | --- |
| FR-01 | MUST | Instructors MUST be able to sign in with email + password. |
| FR-02 | MUST | Passwords MUST be stored using `password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12])`. |
| FR-03 | MUST | A logged-in instructor session MUST expire after 12 hours of inactivity (sliding). |
| FR-04 | MUST | Instructors MUST be able to log out from any page. |
| FR-05 | MUST | Failed login attempts MUST be rate-limited (≥ 5 failures for the same email within 10 min triggers a 15-min lockout). |
| FR-06 | MAY  | Email-based password reset MAY be added in Phase 11. |
| FR-07 | MUST | Students MUST NOT have user accounts; their identity is `participants.nickname` + opaque `device_hash` per session. |
| FR-08 | MUST | Login error responses MUST NOT reveal whether the email exists (always return the same `invalid_credentials` code). |
| FR-09 | SHOULD | Admins SHOULD be created only via `bin/user-add.php`; there is no public sign-up. |

### 3.2 Course Management

| ID | Priority | Requirement |
| --- | --- | --- |
| FR-10 | MUST | An instructor MUST be able to create a course with title, code, semester, description, and default language. |
| FR-11 | MUST | An instructor MUST be able to view the list of courses they own. |
| FR-12 | MUST | An instructor MUST be able to edit any course they own. |
| FR-13 | MUST | An instructor MUST be able to archive a course (`status = 'archived'`) without losing its sessions. |
| FR-14 | MUST | Course ownership MUST be enforced — an instructor MUST NOT read or modify another instructor's course. |
| FR-15 | MAY  | Admins MAY view and manage all courses across the institution. |

### 3.3 Session Management

| ID | Priority | Requirement |
| --- | --- | --- |
| FR-20 | MUST | An instructor MUST be able to start a new session under any course they own. |
| FR-21 | MUST | Each new session MUST receive a unique 6-character short code drawn from `A-H J-N P-Z 2-9` (no `0/O/I/1/L`). |
| FR-22 | MUST | The system MUST render the join URL as a QR code on demand. |
| FR-23 | MUST | A session MUST be in one of four states: `draft`, `active`, `paused`, `closed`. |
| FR-24 | MUST | An instructor MUST be able to close a session manually. Closed sessions MUST reject new joins and new answers. |
| FR-25 | MUST | An instructor MUST be able to pause and resume a session. Paused sessions MUST reject new answers. |
| FR-26 | SHOULD | Sessions SHOULD auto-close 12 hours after creation if not closed manually. |
| FR-27 | MUST | The instructor MUST see a live participant count for the active session. |
| FR-28 | MUST | A session's `show_results_to_students` flag MUST be toggleable by the instructor. |

### 3.4 Question Management

| ID | Priority | Requirement |
| --- | --- | --- |
| FR-30 | MUST | An instructor MUST be able to add questions to a session in advance (as `draft`) or on the fly. |
| FR-31 | MUST | The system MUST support these question types: `multiple_choice`, `open_text`, `yes_no`, `likert_5`. |
| FR-32 | MUST | A `multiple_choice` question MUST allow 2–8 options. `is_correct` is optional and not required for polling. |
| FR-33 | MUST | At most one question per session MUST be `active` at any time (the one-active-question rule). |
| FR-34 | MUST | An instructor MUST be able to activate (publish) and close a question. |
| FR-35 | SHOULD | Questions SHOULD be reorderable within a session by drag-and-drop. |
| FR-36 | MUST | Question text limit: 1–500 chars. Option text limit: 1–200 chars. |
| FR-37 | MUST | A question's `show_results` flag MUST be set independently of the session-level `show_results_to_students`. |
| FR-38 | MUST | A question's `allow_multiple_answers` flag (default `false`) MUST control whether a participant can answer it more than once. |
| FR-39 | MAY  | Questions MAY have an image attachment in Phase 11. |

### 3.5 Student Participation

| ID | Priority | Requirement |
| --- | --- | --- |
| FR-40 | MUST | A student MUST be able to join a session by scanning a QR or visiting the short URL `/join/{short_code}`. |
| FR-41 | MUST | A student MUST submit a nickname (1–24 characters, charset `^[\p{L}\p{N}_\- ]+$`) to participate. |
| FR-42 | MUST | A student's nickname MUST be unique within a session (case-insensitive via `nickname_normalized`). |
| FR-43 | MUST | Nicknames MUST be filtered against a configurable profanity list at `config/profanity/<locale>.txt`. |
| FR-44 | MUST | A student MUST be able to submit at most one answer per question, unless `questions.allow_multiple_answers = true`. |
| FR-45 | MUST | The student client MUST poll for the next active question every 3 seconds. |
| FR-46 | SHOULD | A `device_hash` SHOULD be derived (SHA-256 of `server_secret || persistent_cookie_id || user_agent`) for duplicate-join detection. |
| FR-47 | MUST | A student joining a closed or paused session MUST see a clear, localized message and MUST NOT be able to answer. |
| FR-48 | MAY  | Students MAY be able to react with simple emoji ("got it", "lost") in Phase 11. |
| FR-49 | MUST | A returning student MUST be auto-restored into the same session via the persistent `eduqr_device` cookie when the device hash matches. |

### 3.6 Live Results

| ID | Priority | Requirement |
| --- | --- | --- |
| FR-50 | MUST | The instructor MUST see live aggregated results for the active question. |
| FR-51 | MUST | For `multiple_choice` / `yes_no` / `likert_5`, results MUST show counts and percentages per option. |
| FR-52 | MUST | For `open_text`, results MUST show a list of submitted answers in arrival order with nickname and timestamp. |
| FR-53 | MUST | The instructor MUST be able to toggle whether students see live results (`show_results_to_students` + per-question `show_results`). |
| FR-54 | MUST | The projector view at `/live/{short_code}` MUST render results in large, high-contrast typography readable from the back of a classroom. |
| FR-55 | SHOULD | Open-text answers SHOULD be moderatable — instructor toggles "moderation mode" to require approval before public display. |

### 3.7 Reporting

| ID | Priority | Requirement |
| --- | --- | --- |
| FR-60 | MUST | After a session closes, the instructor MUST be able to view a session report. |
| FR-61 | MUST | The report MUST include: course title, session title, dates, participant count, question count, total answer count, per-question breakdown, raw answers, timestamps. |
| FR-62 | MUST | The report MUST be exportable as CSV. |
| FR-63 | SHOULD | The report SHOULD be exportable as printable HTML / PDF. |
| FR-64 | MAY  | A cross-session course-level report MAY be added in Phase 11. |
| FR-65 | MAY  | AI-assisted theme extraction for open-text answers MAY be added in Phase 11. |
| FR-66 | MAY  | The system MAY generate a deterministic word cloud from visible open-text answers and display it in live results and reports. |

### 3.8 Privacy Controls

| ID | Priority | Requirement |
| --- | --- | --- |
| FR-70 | MUST | An instructor MUST be able to anonymize a closed session (strip `nickname` and `device_hash` from the participants of that session, leaving aggregate data intact). |
| FR-71 | MUST | An instructor MUST be able to fully delete a session. Deletion is soft, with a 7-day grace period before hard delete. |
| FR-72 | MUST | IP addresses MUST NOT appear in any user-facing report or export. |
| FR-73 | MUST | The `device_hash` MUST NOT appear in any report or export. |
| FR-74 | MUST | Reports MUST require instructor authentication; there MUST be no public report URL. |
| FR-75 | MUST | A privacy notice link MUST be shown on every student join page. |

### 3.9 Internationalization

| ID | Priority | Requirement |
| --- | --- | --- |
| FR-80 | MUST | Every user-facing string MUST be sourced from a locale file. No hardcoded strings. |
| FR-81 | MUST | The system MUST support `en` and `tr` at launch with ≥ 95 % key coverage each. |
| FR-82 | MUST | The user's locale MUST be selectable via UI and persisted in the `eduqr_locale` cookie. |
| FR-83 | MUST | If a translation key is missing, the system MUST fall back to English, then to the key itself. |
| FR-84 | SHOULD | The locale SHOULD be auto-detected from `Accept-Language` on first visit. |
| FR-85 | SHOULD | Date, time, and number formatting SHOULD follow the active locale via PHP `IntlDateFormatter` and `NumberFormatter`. |
| FR-86 | MAY  | RTL locales (Arabic, Hebrew, Farsi) MAY be added with full layout mirroring. |
| FR-87 | MUST | Validation messages and error codes' messages MUST also use translation keys. |
| FR-88 | MUST | A language switcher MUST appear on every page that has user-facing UI. |

See `I18N_SPEC.md` for the implementation contract.

### 3.10 Audit

| ID | Priority | Requirement |
| --- | --- | --- |
| FR-90 | SHOULD | The system SHOULD write an `audit_logs` row for these actions: `session.created`, `session.closed`, `session.anonymized`, `session.deleted`, `question.activated`, `question.closed`, `report.exported`, `user.created`. |
| FR-91 | MAY  | An admin UI for browsing the audit log MAY be added in Phase 11. |
| FR-92 | SHOULD | A session SHOULD support quiz mode (is_quiz=1). In quiz mode, multiple_choice questions with at least one correct option (is_correct=1) contribute to a per-participant score. The session report SHOULD display each participant's score and a ranking. |
| FR-93 | MUST | An instructor MUST be able to save reusable questions in a course-scoped question bank and copy them into any later session under the same course. |
| FR-94 | MUST | The system MUST be able to generate question bank entries from lecture notes via an LLM, including opening, middle, and closing questions. |
| FR-95 | MUST | An instructor MUST be able to review generated bank questions and publish selected entries into a session as draft questions. |

---

## 4. Non-Functional Requirements

### 4.1 Performance

| ID | Priority | Requirement |
| --- | --- | --- |
| NFR-01 | MUST | Median answer-submission API latency MUST be < 300 ms under 100 concurrent students. |
| NFR-02 | MUST | Live results MUST reflect a new answer within 5 seconds (≤ 3 s poll + ≤ 2 s aggregation). |
| NFR-03 | SHOULD | A single instance SHOULD support at least 200 concurrent students per session without degradation. |
| NFR-04 | MUST | Database queries on the hot path (active-question fetch, answer insert, results aggregate) MUST be backed by indexes per `DATA_MODEL.md` §5. |

### 4.2 Compatibility

| ID | Priority | Requirement |
| --- | --- | --- |
| NFR-10 | MUST | Server MUST run on PHP 8.2+ with standard cPanel extensions: `mysqli` or `pdo_mysql`, `mbstring`, `gd`, `intl`, `json`. |
| NFR-11 | MUST | Database MUST be MySQL 8.0+ or MariaDB 10.6+. |
| NFR-12 | MUST | Student-side UI MUST work on iOS Safari 15+ and Chrome for Android 110+. |
| NFR-13 | MUST | Instructor-side UI MUST work on the last 2 major versions of Chrome, Firefox, Safari, Edge. |
| NFR-14 | SHOULD | UI SHOULD be responsive down to 360 px width. |
| NFR-15 | MUST | The application MUST install on shared cPanel hosting with `public_html/eduqr/` as the document-root subfolder. |

### 4.3 Security

| ID | Priority | Requirement |
| --- | --- | --- |
| NFR-20 | MUST | All traffic MUST be HTTPS in production; cookies MUST set `Secure`. |
| NFR-21 | MUST | All SQL MUST use prepared statements; no string concatenation into queries. |
| NFR-22 | MUST | All user-supplied content rendered in HTML MUST be escaped with `htmlspecialchars($v, ENT_QUOTES, 'UTF-8')`. |
| NFR-23 | MUST | Cookies MUST be `HttpOnly` (except `eduqr_locale` and `eduqr_csrf`), `Secure`, `SameSite=Lax` (CSRF: `Strict`). |
| NFR-24 | MUST | All state-changing instructor endpoints (POST, PATCH, DELETE) MUST require a matching CSRF token (double-submit cookie). |
| NFR-25 | MUST | A strict Content-Security-Policy header MUST be sent on every response. See `SECURITY_PRIVACY.md` §7. |
| NFR-26 | MUST | Default PDO settings: `ATTR_EMULATE_PREPARES = false`, `ATTR_ERRMODE = ERRMODE_EXCEPTION`, charset `utf8mb4`. |

### 4.4 Privacy & Compliance

| ID | Priority | Requirement |
| --- | --- | --- |
| NFR-30 | MUST | The system MUST satisfy KVKK (Türkiye) and GDPR (EU) for in-classroom feedback data. |
| NFR-31 | MUST | A privacy notice MUST be reachable from every student join screen. |
| NFR-32 | MUST | No personal data of students (real name, email, ID number, phone) is collected. |
| NFR-33 | SHOULD | Web-server logs SHOULD have IP addresses redacted after 30 days. |
| NFR-34 | MUST | After 365 days, closed sessions MUST be auto-anonymized: clear `nickname` and `device_hash`, retain aggregate counts. |

### 4.5 Accessibility

| ID | Priority | Requirement |
| --- | --- | --- |
| NFR-40 | MUST | All interactive elements MUST be keyboard-navigable. |
| NFR-41 | MUST | Color contrast MUST meet WCAG 2.1 AA. |
| NFR-42 | MUST | All form fields MUST have associated `<label>` elements. |
| NFR-43 | SHOULD | The projector view SHOULD support an explicit "large text" toggle. |
| NFR-44 | SHOULD | The student answer screen SHOULD work without JavaScript for one-shot answer submission (degrades to form POST). |

### 4.6 Maintainability

| ID | Priority | Requirement |
| --- | --- | --- |
| NFR-50 | MUST | PHP code MUST follow PSR-12. |
| NFR-51 | MUST | All non-trivial functions MUST have docblock comments. |
| NFR-52 | SHOULD | Service-layer and repository-layer code SHOULD have ≥ 60 % unit-test coverage at MVP. |
| NFR-53 | MUST | Migrations MUST be append-only and stored in `database/migrations/` with a 4-digit prefix. |
| NFR-54 | MUST | `schema.sql` MUST reflect the cumulative result of all applied migrations. |

### 4.7 Deployment

| ID | Priority | Requirement |
| --- | --- | --- |
| NFR-60 | MUST | The app MUST deploy as a set of files to a `public_html`-style document root, with sensitive files (`.env`, `vendor/`) outside it. |
| NFR-61 | MUST | A `bin/install.php` helper MUST perform first-time setup (env checks, `.env` scaffolding) and refuse to run twice in production. |
| NFR-62 | SHOULD | A `deploy/cpanel-notes.md` MUST document step-by-step shared-hosting installation. |

### 4.8 Observability

| ID | Priority | Requirement |
| --- | --- | --- |
| NFR-70 | MUST | Errors MUST be written to a server-only log file (never echoed to the user in production). |
| NFR-71 | SHOULD | Each request SHOULD record route, status code, duration, and a correlation ID. |
| NFR-72 | MAY  | A health-check endpoint `GET /api/v1/health` MAY be added in Phase 11. |
| NFR-73 | MUST | Logs MUST NOT contain passwords, full cookies, raw answer bodies, or device hashes. |

---

## 5. Out of Scope (MVP)

These are explicitly **not** in scope for the MVP and any agent asked to implement them must escalate to the human owner before starting:

- LMS integration (Moodle, Canvas, etc.)
- Native mobile applications
- Real-time WebSockets / Socket.IO
- Advanced AI analysis
- Quiz mode with grading
- Gamification (badges, points, leaderboards)
- Multi-tenant institutional management
- Payment / subscription
- Public sign-up
- Multi-instructor course ownership

---

## 6. Traceability

Every implemented feature MUST reference at least one requirement ID in its commit message and tests. Example commit subject:

```text
feat(session): generate 6-char short_code with no ambiguous glyphs [FR-21]
```

Every requirement here should be discoverable in at least one test or implementation file. A periodic audit can `grep -rn 'FR-21'` across the repo.
