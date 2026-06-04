# Glossary

Several terms in this project have overloaded meanings. This file is the **single source of truth** for what each one means inside eduQR. When you see a term elsewhere in the docs, look here if you are unsure.

Terms are alphabetical.

---

### admin

A row in the `users` table whose `role` is `'admin'`. Has the rights of an instructor plus user management. Not a separate table.

### anonymize

The action of stripping `nickname` and `device_hash` from the participants of a closed classroom **session** so that a downloaded report contains no per-participant identifiers. Performed by `POST /api/v1/sessions/{id}/anonymize`. Irreversible.

### answer

A single row in the `answers` table. Belongs to exactly one **participant** and one **question**. For option-based question types it has a `selected_option_id`; for `open_text` it has `answer_text`. Never both populated, never both null.

### archived (course)

A `courses` row whose `status` is `'archived'`. Hidden from default list views but data preserved.

### classroom session

Synonym for **session** (the eduQR domain object). Used when disambiguation from HTTP session is helpful.

### course

A row in the `courses` table. Belongs to one instructor (`users.id` with `role='instructor'`). Has many **sessions**.

### CSRF token

The value stored in the `eduqr_csrf` cookie and required (matching) in the `X-CSRF-Token` header on every state-changing instructor request.

### device hash

A SHA-256 hash of `(server_secret || persistent_cookie_id || user_agent)`. Stored as `participants.device_hash`. Used **only** for low-effort duplicate-join detection within one classroom session. **Never displayed**, **never exported**, **never used as a long-term tracking identifier**.

### endroid/qr-code

The PHP library used for server-side QR generation. Vendored via Composer.

### error code

A **stable, machine-readable, snake_case** string returned in error responses (`error.code`). Examples: `session_closed`, `duplicate_nickname`, `invalid_credentials`. The human-readable text is in `error.message` and is localized; the code is not.

### FR-xx / NFR-xx

Functional / Non-functional Requirement identifier, defined in `PRODUCT_REQUIREMENTS.md`. Every feature commit references at least one.

### HTTP session

The cookie-backed `eduqr_session` mechanism that authenticates an instructor's browser to the server. **Not** the eduQR domain "session". When a doc says "session" without qualification it means the classroom session.

### instructor

A row in the `users` table whose `role` is `'instructor'`. Owns courses and runs classroom sessions.

### locale

A language tag like `en`, `tr`, `ar`. The active locale is decided per request by `I18nMiddleware` per `I18N_SPEC.md` §6.

### nickname

A 1–24 character string a student picks before joining a classroom session. Stored on `participants.nickname`. Sanitized, profanity-filtered, unique within a session (case-insensitive via `nickname_normalized`).

### one-active-question rule

At most one row per `(session_id)` may have `questions.status = 'active'` at any time. Enforced at the application layer because MySQL InnoDB does not support partial unique indexes.

### option

A row in the `options` table. A choice for a `multiple_choice`, `yes_no`, or `likert_5` question. Open-text questions have zero options.

### participant

A row in the `participants` table. A student who joined a classroom **session** with a nickname. Anonymous-ish (no account). Tied to one session.

### projector view

The read-only display at `/live/{short_code}` intended for the classroom projector. Shows the QR code and (optionally) live results in large type.

### question

A row in the `questions` table. Belongs to one **session**. Has a `question_type` of `multiple_choice`, `open_text`, `yes_no`, or `likert_5`.

### question bank

A course-scoped collection of reusable question templates stored outside any single session. Bank questions can be generated from lecture notes or created manually, then copied into future sessions as draft questions.

### report

The post-session summary returned by `GET /api/v1/sessions/{id}/report` (JSON), `report.csv` (CSV), or `report.html` (printable). Contains participant count, question-by-question breakdown, raw answers, timestamps. Exportable.

### role

`users.role` enum value: `'admin'` or `'instructor'`. There is no `'student'` role — students are anonymous **participants**, not `users` rows.

### server_secret

A 32-byte secret in `.env` used to salt **device hash** computation. Generated on first install. Rotated yearly; old hashes become non-matching, which is fine because the use case is per-session.

### session (classroom)

A row in the `sessions` table. A single classroom lecture instance. Has a short_code, status, course, questions, participants, answers. **This is the default meaning of "session" in eduQR docs.**

### session (HTTP)

See **HTTP session**.

### session code / short code

The 6-character public identifier of a classroom session. Charset `A-H J-N P-Z 2-9` (no `0/O/I/1/L`). What the QR encodes via the join URL.

### show_results_to_students

Boolean column on `sessions`. When `true`, students see live aggregate results for the current question on their own device.

### t() / t(key, params)

The translation helper. `t('auth.login.submit')` returns the active-locale string for that key, falling back to English, then to the key itself.

### user

A row in the `users` table. **Always an instructor or admin, never a student.** Students do not have user rows; they have participant rows scoped to a single classroom session.

### users table

The single auth table. Holds both instructors (`role='instructor'`) and admins (`role='admin'`). Earlier draft documents may have called this `instructors`; that is wrong — it is `users`.
