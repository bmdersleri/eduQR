# API Specification — eduQR

This document defines every HTTP endpoint eduQR exposes. The shape, status codes, and error envelope here are binding. When you add an endpoint, append it to this document **before** writing the implementation.

**Locked decisions (do not deviate):**

- All JSON endpoints are versioned under **`/api/v1/`**.
- Response envelope uses **`success`** (boolean), not `ok`.
- Error codes are **stable, machine-readable, `snake_case`** strings.
- All timestamps in responses are **ISO-8601 UTC**: `"2026-05-13T19:09:48Z"`.

---

## 1. Conventions

### 1.1 Base path

JSON endpoints: `/api/v1/...`. HTML routes are unversioned. Never put HTML and JSON at the same path.

### 1.2 Content types

- Requests with a body MUST send `Content-Type: application/json`.
- JSON responses are `application/json; charset=utf-8`.
- HTML routes send `text/html; charset=utf-8`.
- Binary endpoints (QR PNG, CSV) send their own content type.

### 1.3 Authentication

| Audience | Mechanism |
| --- | --- |
| Instructor / admin | `eduqr_session` PHP session cookie (set on login) |
| Student (participant) | `eduqr_participant` PHP session cookie (set on join) |
| Public | None |

State-changing instructor endpoints (POST, PATCH, DELETE) MUST present a matching CSRF token via the `X-CSRF-Token` header (or `_csrf` body field for HTML form posts).

### 1.4 Success envelope

```json
{
  "success": true,
  "data": { },
  "message": "Optional localized human-readable message."
}
```

`data` may be an object, an array, or `null`. `message` is optional and always in the active locale.

### 1.5 Error envelope

```json
{
  "success": false,
  "error": {
    "code": "duplicate_nickname",
    "message": "This nickname is already taken in this session.",
    "field": "nickname"
  }
}
```

`code` is stable and machine-readable. `message` is always localized. `field` is optional and present for validation errors.

### 1.6 HTTP status codes

| Code | When |
| --- | --- |
| 200 | OK |
| 201 | Created |
| 204 | No Content (e.g. successful logout, close) |
| 400 | Bad input — validation failure |
| 401 | Not authenticated |
| 403 | Authenticated but forbidden |
| 404 | Resource not found |
| 409 | Conflict (duplicate nickname, already answered) |
| 410 | Gone (session or question closed) |
| 422 | Unprocessable (semantic validation failure) |
| 429 | Rate limit exceeded |
| 500 | Server error |

### 1.7 Locale

Every endpoint accepts an optional `?lang=tr|en|...`. If omitted, the locale resolves from the `eduqr_locale` cookie, then `Accept-Language`, then `APP_LOCALE_DEFAULT`. Error and success messages echo back in the resolved locale.

### 1.8 Pagination

List endpoints that can grow use offset pagination:

```text
GET /api/v1/courses?page=1&per_page=20
```

Response includes a `meta` block:

```json
{
  "success": true,
  "data": [ ],
  "meta": { "page": 1, "per_page": 20, "total": 47 }
}
```

---

## 2. Public Endpoints (no auth)

### 2.1 Resolve session by short code

`GET /api/v1/public/sessions/{short_code}`

Used by the student join page to verify a code before showing the nickname form.

Response 200:

```json
{
  "success": true,
  "data": {
    "short_code": "ABCD23",
    "title": "Week 5 - Linked Lists",
    "course_title": "Data Structures",
    "status": "active",
    "language": "en",
    "join_url": "https://eduqr.example.org/join/ABCD23"
  }
}
```

Errors: 404 `session_not_found`, 410 `session_closed`.

---

## 3. Student Endpoints

These set / require the `eduqr_participant` cookie.

### 3.1 Join a session

`POST /api/v1/sessions/{short_code}/join`

Request:

```json
{ "nickname": "Elif" }
```

Response 201:

```json
{
  "success": true,
  "data": {
    "participant_id": 123,
    "session_short_code": "ABCD23",
    "nickname": "Elif"
  },
  "message": "Joined session successfully."
}
```

Validation:

- Session must exist and be `active`.
- Nickname required, 1–24 chars, charset `^[\p{L}\p{N}_\- ]+$`.
- Nickname must pass the profanity filter.
- Nickname must be unique within the session (case-insensitive).

Errors: 400 `invalid_nickname`, 409 `duplicate_nickname`, 410 `session_closed`, 422 `session_paused`.

### 3.2 Get current active question

`GET /api/v1/sessions/{short_code}/active-question`

Optional query: `since=<iso8601>` (client-side optimization).

Response 200 (question available):

```json
{
  "success": true,
  "data": {
    "question": {
      "id": 4001,
      "type": "multiple_choice",
      "text": "How well did you understand linked lists?",
      "options": [
        { "id": 9001, "text": "Very well" },
        { "id": 9002, "text": "Mostly" },
        { "id": 9003, "text": "Partially" },
        { "id": 9004, "text": "Needs revisiting" }
      ],
      "activated_at": "2026-05-13T19:11:00Z",
      "already_answered": false
    }
  }
}
```

Response 200 (no active question):

```json
{ "success": true, "data": { "question": null } }
```

Errors: 401 `not_joined`, 410 `session_closed`.

### 3.3 Submit an answer

`POST /api/v1/answers`

Request (multiple-choice / yes-no / Likert):

```json
{ "question_id": 4001, "selected_option_id": 9002 }
```

Request (open text):

```json
{ "question_id": 4001, "answer_text": "I struggled with pointer logic." }
```

Response 201:

```json
{
  "success": true,
  "data": { "answer_id": 77777 },
  "message": "Your answer has been submitted."
}
```

Validation:

- Question must exist and be `active`.
- Question's session must be `active` (not `paused` or `closed`).
- Participant must belong to the question's session.
- Exactly one of `selected_option_id` / `answer_text` must be present, matching the question type.
- `selected_option_id` must belong to the question.
- `answer_text` 1–2000 chars, sanitized.
- Duplicate answer rejected unless `questions.allow_multiple_answers = true`.

Errors: 400 `missing_fields`, 409 `duplicate_answer`, 410 `question_closed`, 410 `session_closed`, 422 `invalid_answer_shape`, 422 `session_paused`.

### 3.4 Live results (student-visible)

`GET /api/v1/sessions/{short_code}/results?question_id=4001`

Same shape as the instructor results endpoint (§5.5), but returns data **only** when `sessions.show_results_to_students = true` AND `questions.show_results = true`. Otherwise 403 `results_hidden`.

---

## 4. Instructor Authentication

### 4.1 Log in

`POST /api/v1/auth/login`

Request:

```json
{ "email": "demo@example.org", "password": "..." }
```

Response 200:

```json
{
  "success": true,
  "data": {
    "user": {
      "id": 1,
      "display_name": "Demo Instructor",
      "email": "demo@example.org",
      "role": "instructor",
      "preferred_language": "tr"
    }
  },
  "message": "Login successful."
}
```

Validation:

- Email and password required.
- Invalid credentials MUST NOT reveal whether the email exists — always return `invalid_credentials`.

Errors: 400 `missing_fields`, 401 `invalid_credentials`, 429 `too_many_attempts`.

### 4.2 Log out

`POST /api/v1/auth/logout` → 204 No Content.

### 4.3 Whoami

`GET /api/v1/auth/me` → user object (same shape as login `data.user`) or 401 `not_authenticated`.

---

## 5. Instructor Resource Endpoints

Require an `eduqr_session` cookie tied to a `users` row with `role IN ('instructor','admin')`.

### 5.1 Courses

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/api/v1/courses` | List my courses (paginated) |
| POST | `/api/v1/courses` | Create |
| GET | `/api/v1/courses/{id}` | Read |
| PATCH | `/api/v1/courses/{id}` | Update |
| DELETE | `/api/v1/courses/{id}` | Archive |

Create / update body:

```json
{
  "title": "Data Structures",
  "code": "CSE203",
  "semester": "2026-Spring",
  "description": "Undergraduate data structures course",
  "default_language": "en"
}
```

Create response:

```json
{
  "success": true,
  "data": { "id": 12 },
  "message": "Course created successfully."
}
```

Ownership: an instructor only sees and modifies their own courses (`FR-14`). Accessing another instructor's course returns 403 `forbidden`.

### 5.2 Sessions

| Method | Path | Purpose |
| --- | --- | --- |
| POST | `/api/v1/courses/{id}/sessions` | Start a new session under a course |
| GET | `/api/v1/sessions/{id}` | Session detail |
| PATCH | `/api/v1/sessions/{id}` | Update (title, `show_results_to_students`, `moderation_mode`) |
| POST | `/api/v1/sessions/{id}/pause` | Pause |
| POST | `/api/v1/sessions/{id}/resume` | Resume |
| POST | `/api/v1/sessions/{id}/close` | Close |
| POST | `/api/v1/sessions/{id}/anonymize` | Strip nicknames + device hashes |
| DELETE | `/api/v1/sessions/{id}` | Request deletion (7-day grace) |

Create body:

```json
{ "title": "Week 5 - Linked Lists", "language": "en" }
```

Create response:

```json
{
  "success": true,
  "data": {
    "id": 42,
    "short_code": "ABCD23",
    "join_url": "https://eduqr.example.org/join/ABCD23",
    "qr_url": "/api/v1/sessions/42/qr.png",
    "status": "active"
  },
  "message": "Session created successfully."
}
```

Pause / resume / close responses: 200 with a localized `message`, or 204.

Errors: 404 `session_not_found`, 403 `forbidden`, 422 `invalid_state_transition` (e.g. resuming a closed session).

### 5.3 Questions

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/api/v1/sessions/{id}/questions` | List questions for a session |
| POST | `/api/v1/sessions/{id}/questions` | Create a question |
| PATCH | `/api/v1/questions/{id}` | Update a draft question (text, options) |
| POST | `/api/v1/questions/{id}/activate` | Publish (closes any other active question) |
| POST | `/api/v1/questions/{id}/close` | Close the question |
| DELETE | `/api/v1/questions/{id}` | Delete a question |
| POST | `/api/v1/sessions/{id}/questions/reorder` | Reorder questions |

Create body (multiple-choice):

```json
{
  "question_text": "How well did you understand linked lists?",
  "question_type": "multiple_choice",
  "show_results": false,
  "allow_multiple_answers": false,
  "options": [
    { "option_text": "Very well" },
    { "option_text": "Mostly" },
    { "option_text": "Partially" },
    { "option_text": "Needs revisiting" }
  ]
}
```

Create body (open text):

```json
{
  "question_text": "What was the hardest part of today's lesson?",
  "question_type": "open_text",
  "show_results": false
}
```

Create body (yes/no): `question_type: "yes_no"` — options auto-generated.
Create body (Likert): `question_type: "likert_5"` — 5 options auto-generated.

Create response:

```json
{
  "success": true,
  "data": { "id": 4001 },
  "message": "Question created successfully."
}
```

Reorder body:

```json
{ "order": [4001, 4005, 4003, 4002] }
```

Activate rules:

- The question's session must be `active`.
- Any currently `active` question in the same session is set to `closed` in the same transaction (the one-active-question rule, `FR-33`).

Errors: 404 `question_not_found`, 422 `invalid_question_type`, 422 `invalid_option_count`, 422 `session_not_active`.

### 5.4 QR code image

`GET /api/v1/sessions/{id}/qr.png?size=512`

Returns a PNG of the QR code encoding the canonical `join_url`. `Content-Type: image/png`. `Cache-Control: public, max-age=3600`. `size` is clamped to 128–1024.

### 5.5 Live results

`GET /api/v1/sessions/{id}/results?question_id={qid}`

Multiple-choice / yes-no / Likert response:

```json
{
  "success": true,
  "data": {
    "question_id": 4001,
    "question_type": "multiple_choice",
    "answer_count": 30,
    "participant_count": 42,
    "distribution": [
      { "option_id": 9001, "option_text": "Very well",        "count": 12, "percentage": 40.0 },
      { "option_id": 9002, "option_text": "Mostly",           "count": 10, "percentage": 33.3 },
      { "option_id": 9003, "option_text": "Partially",        "count":  6, "percentage": 20.0 },
      { "option_id": 9004, "option_text": "Needs revisiting", "count":  2, "percentage":  6.7 }
    ]
  }
}
```

Open-text response:

```json
{
  "success": true,
  "data": {
    "question_id": 4002,
    "question_type": "open_text",
    "answer_count": 12,
    "participant_count": 42,
    "answers": [
      { "answer_id": 77770, "nickname": "Yasemin", "answer_text": "Pointer logic.",   "is_hidden": false, "created_at": "2026-05-13T19:13:02Z" },
      { "answer_id": 77771, "nickname": "Bahar",   "answer_text": "Insertion order.", "is_hidden": false, "created_at": "2026-05-13T19:13:08Z" }
    ]
  }
}
```

### 5.6 Hide / unhide an open-text answer

`POST /api/v1/answers/{id}/hide` and `POST /api/v1/answers/{id}/unhide` → 200 with localized `message`. Sets `answers.is_hidden`. Used with `moderation_mode` (`FR-55`).

### 5.7 Reports

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/api/v1/sessions/{id}/report` | Structured session report (JSON) |
| GET | `/api/v1/sessions/{id}/report.csv?anonymize=true\|false` | CSV download |
| GET | `/api/v1/sessions/{id}/report.html?anonymize=true\|false` | Printable HTML |
| GET | `/api/v1/sessions/{id}/report.pdf?anonymize=true\|false` | PDF download |

JSON report shape:

```json
{
  "success": true,
  "data": {
    "session": {
      "id": 42,
      "title": "Week 5 - Linked Lists",
      "course_title": "Data Structures",
      "language": "en",
      "started_at": "2026-05-13T19:09:48Z",
      "closed_at": "2026-05-13T20:31:12Z",
      "anonymized": false
    },
    "summary": {
      "participant_count": 42,
      "question_count": 6,
      "answer_count": 211,
      "participation_rate": 0.83
    },
    "questions": [
      {
        "id": 4001,
        "type": "multiple_choice",
        "text": "How well did you understand linked lists?",
        "answer_count": 39,
        "distribution": [
          { "option_text": "Very well", "count": 12, "percentage": 30.8 },
          { "option_text": "Mostly",    "count": 18, "percentage": 46.2 }
        ]
      },
      {
        "id": 4002,
        "type": "open_text",
        "text": "What was the hardest part?",
        "answer_count": 21,
        "answers": [
          { "nickname": "Elif",   "answer_text": "Pointer logic" },
          { "nickname": "İsmail", "answer_text": "Recursive operations" }
        ]
      }
    ]
  }
}
```

When `anonymize=true`, nicknames are replaced with `Participant 1`, `Participant 2`, … in the order they appear. Device hashes never appear in any variant (`FR-73`).

### 5.8 Locales

`GET /api/v1/locales` → list of active locales (used by the language switcher).

```json
{
  "success": true,
  "data": [
    { "code": "en", "label_native": "English", "is_rtl": false },
    { "code": "tr", "label_native": "Türkçe",  "is_rtl": false }
  ]
}
```

---

## 6. Admin Endpoints

Require a `users` row with `role = 'admin'`.

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/api/v1/admin/users` | List instructor / admin accounts |
| POST | `/api/v1/admin/users` | Create an account (also available via `bin/user-add.php`) |
| PATCH | `/api/v1/admin/users/{id}` | Update (display name, `is_active`, role) |

Create body:

```json
{
  "email": "new.instructor@example.org",
  "display_name": "New Instructor",
  "role": "instructor",
  "preferred_language": "tr",
  "password": "TempPass#2026"
}
```

Errors: 403 `forbidden` (caller is not admin), 409 `email_taken`, 400 `invalid_password`.

---

## 7. Error Code Reference

Stable, machine-readable codes. Add to this table when introducing a new code.

| Code | HTTP | Meaning |
| --- | --- | --- |
| `missing_fields` | 400 | One or more required fields absent |
| `validation_error` | 400 | Generic field validation failure |
| `invalid_nickname` | 400 | Length, charset, or profanity check failed |
| `invalid_password` | 400 | Password does not meet policy |
| `invalid_credentials` | 401 | Bad email or password |
| `not_authenticated` | 401 | Instructor cookie missing or expired |
| `not_joined` | 401 | Participant cookie missing or expired |
| `forbidden` | 403 | Authenticated but not allowed |
| `results_hidden` | 403 | Student requested results while toggle is off |
| `session_not_found` | 404 | Short code or session ID does not resolve |
| `course_not_found` | 404 | |
| `question_not_found` | 404 | |
| `answer_not_found` | 404 | |
| `email_taken` | 409 | Account email already exists |
| `duplicate_nickname` | 409 | Nickname already taken in this session |
| `duplicate_answer` | 409 | Participant already answered this question |
| `session_closed` | 410 | Session is closed |
| `question_closed` | 410 | Question is closed |
| `invalid_answer_shape` | 422 | Answer body does not match the question type |
| `invalid_question_type` | 422 | Unknown `question_type` value |
| `invalid_option_count` | 422 | Wrong number of options for the type |
| `invalid_state_transition` | 422 | e.g. resuming a closed session |
| `session_paused` | 422 | Action not allowed while session is paused |
| `session_not_active` | 422 | Action requires an active session |
| `too_many_attempts` | 429 | Rate limit hit |
| `server_error` | 500 | Unhandled exception |

Internal logs use the same `snake_case` codes; user-facing messages come from translation keys (`error.<code>`). See `I18N_SPEC.md` §11.

---

## 8. Lifecycle Walkthrough — One Question End-to-End

```text
POST /api/v1/auth/login                                      ← instructor signs in
POST /api/v1/courses/12/sessions   { title: "Week 5" }       ← starts session, gets short_code
POST /api/v1/sessions/42/questions { ...question payload }   ← creates a question (status=draft)
POST /api/v1/questions/4001/activate                         ← publishes (closes any other active)
   GET /api/v1/sessions/ABCD23/active-question  (student polls every 3 s)
   POST /api/v1/answers              { question_id, ... }    ← students answer
GET  /api/v1/sessions/42/results?question_id=4001  (instructor polls every 2 s)
POST /api/v1/questions/4001/close                            ← closes the question
POST /api/v1/sessions/42/close                               ← closes the session
GET  /api/v1/sessions/42/report                              ← reads the report
GET  /api/v1/sessions/42/report.csv?anonymize=true           ← exports anonymized CSV
```
