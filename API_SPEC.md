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

A failure that escapes every controller — an uncaught exception or a fatal
error (`E_ERROR`, memory exhaustion, a parse error) — is answered the same
way: the global handler in `Bootstrap` recognises `/api/v1/` paths and emits
this same envelope shape. A `DomainException` reaching the handler keeps its
own status and published code; anything else, including a fatal, is
`500 server_error`. The response never carries a stack trace, file path, or
class name, regardless of `APP_DEBUG`; the detail goes to `logs/app.log`
only. (NFR-85)

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
| 502 | Upstream AI provider error |
| 503 | Service unavailable |
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

### 1.9 Polled endpoints and `ETag`

Five endpoints are polled on a timer rather than fetched once. Each answers
`304 Not Modified` when the state behind it has not moved, and each derives its
`ETag` from a **version query** — a query cheap enough that answering `304`
costs less than building the body it replaces (NFR-76). A version query reads
counts, maximum ids and timestamps only; it never joins to build a row set.

| Endpoint | Polled by | Version query reads |
| --- | --- | --- |
| `GET /api/v1/sessions/{short_code}/active-question` | student wait, student answered, projector | the session's `status` and `updated_at`; the active question's `id`, `status`, `activated_at` and `updated_at` |
| `GET /api/v1/sessions/{id}/results` | instructor results, projector | the question's `status` and `updated_at`; over its answers, `COUNT(*)`, `MAX(id)` and `SUM(is_hidden)` |
| `GET /api/v1/sessions/{id}/participants/count` | instructor session detail | the count itself |
| `GET /api/v1/sessions/{id}/reactions` | instructor session detail | over the session's reactions, `COUNT(*)` and `MAX(updated_at)`; plus the `/questions` version below |
| `GET /api/v1/sessions/{id}/questions` | instructor session detail | over the session's questions, `COUNT(*)` and `MAX(updated_at)`; over their options, `COUNT(*)` and `MAX(id)` |

Rules:

- **`SUM(is_hidden)` is not optional on `/results`.** `answers` has no
  `updated_at`, so hiding an answer (§5.6) changes what the endpoint returns
  while leaving both `COUNT(*)` and `MAX(id)` where they were. A version that
  omitted it would serve a stale `304` to a moderating instructor — the one
  reader who must see the change immediately.
- **Authorization runs before the `ETag` is compared.** A `304` is a cache
  answer, not an authorization answer: a caller who may not read the resource
  gets `403` or `404` whether or not their `If-None-Match` matches.
- **The `ETag` covers only what the body carries.** `/active-question` returns
  the same body to every participant today, so its version is not
  participant-specific. Should `already_answered` ever become per-participant,
  the participant id joins the version query in the same change.
- **The options read on `/questions` is not caution.** Editing only a
  question's choices (§4.3) replaces its option rows without writing the
  question row, and `questions.updated_at` is `ON UPDATE CURRENT_TIMESTAMP`, so
  such an edit moves neither the count nor the maximum. Options are deleted and
  recreated rather than edited in place, so their `COUNT(*)` and `MAX(id)` both
  move whenever any of them does.
- **`/reactions` is scoped to the session and folds in `/questions`.** The
  endpoint aggregates every question in the session and emits a zeroed
  `got_it`/`lost` row for questions nobody has reacted to, so adding a question
  changes the response while leaving the reactions table untouched.
- **A poll response must be storable.** `304` is unreachable unless the browser
  kept the `200` that carried the `ETag`, and PHP's session handling sends
  `Cache-Control: no-store` by default on every authenticated response. Polled
  endpoints therefore send `Cache-Control: private, no-cache` and no `Pragma`:
  storable by the browser that asked, by nothing in between, and never reused
  without revalidating.
- **`MAX(updated_at)` has one-second granularity.** Two edits within the same
  second that leave the row count unchanged share a version. This is a property
  of `DATETIME`, and it is why the options read uses `MAX(id)` instead.
- A `304` carries the `ETag` header and no body. A `200` carries both.

### 1.10 Poll intervals

Intervals are configuration, not template constants (NFR-76). Four keys, one
per screen, all in milliseconds:

| Key | Default | Screen |
| --- | --- | --- |
| `POLL_INTERVAL_INSTRUCTOR_MS` | `2000` | `/admin/sessions/{id}/results` — the screen answers arrive on |
| `POLL_INTERVAL_INSTRUCTOR_SESSION_MS` | `5000` | `/admin/sessions/{id}` — participant count, reactions and question list, on one timer |
| `POLL_INTERVAL_STUDENT_MS` | `3000` | `/join/{short_code}/wait` and `/play/{short_code}/answered` |
| `POLL_INTERVAL_PROJECTOR_MS` | `3000` | `/live/{short_code}/results` |

The first two names are kept as `.env.example` already published them, which is
why the bare `INSTRUCTOR` key means the results screen specifically. The
defaults are the values the templates hardcoded before NFR-76, so a deployment
that sets nothing polls exactly as it did.

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
      "image_url": "/uploads/questions/4001_a1b2c3d4e5f60789.png",
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

Same shape as the instructor results endpoint (§5.5), but returns data **only** when `sessions.show_results_to_students = true` AND `questions.show_results = true`. If `sessions.exam_mode = true`, this endpoint always returns 403 `results_hidden`, regardless of `show_results_to_students` or `show_results`. Otherwise 403 `results_hidden`.

### 3.5 Send a comprehension reaction

`POST /api/v1/reactions`

Auth: participant cookie (`eduqr_participant`), same treatment as §3.3 — no CSRF token, rate-limited per IP.

Request:

```json
{ "question_id": 4001, "reaction": "got_it" }
```

Response 200:

```json
{
  "success": true,
  "data": { "reaction": "got_it" },
  "message": "Your reaction has been recorded."
}
```

Validation:

- Question must exist and be `active`.
- Question's session must be `active` (not `paused` or `closed`).
- Participant must belong to the question's session.
- `reaction` must be `got_it` or `lost`; any other value is rejected with 422 `invalid_reaction`.

Rules:

- A participant holds at most one reaction per question. Re-reacting **replaces** the previous value; it never creates a second row (`FR-48`).
- The response carries **no aggregate counts**. Reactions are not results and not correctness, so this endpoint is unaffected by `sessions.exam_mode`, `sessions.show_results_to_students` and per-question `questions.show_results` — a student may always react. That is safe precisely because the student never learns the totals; counts are instructor-only (§5.10).

Errors: 400 `missing_fields`, 401 `not_joined`, 403 `forbidden`, 404 `question_not_found`, 404 `session_not_found`, 410 `question_closed`, 410 `session_closed`, 422 `invalid_reaction`, 422 `session_paused`.

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
| GET | `/api/v1/courses/{id}/analytics` | Cross-session analytics for one course |
| PATCH | `/api/v1/courses/{id}` | Update |
| DELETE | `/api/v1/courses/{id}` | Archive |
| POST | `/api/v1/courses/{id}/restore` | Restore an archived course |
| GET | `/api/v1/courses/{id}/instructors` | List the course's instructors |
| POST | `/api/v1/courses/{id}/instructors` | Add a co-instructor by email (owner only) |
| DELETE | `/api/v1/courses/{id}/instructors/{userId}` | Remove a co-instructor (owner only) |

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

Ownership: an instructor only sees and modifies courses they own or co-instruct (`FR-14`, `FR-97`). Accessing any other course returns 403 `forbidden`.

#### 5.1.1 Course instructors (`FR-97`)

`courses.instructor_id` is the **owner** (the creator). `course_instructors` lists everyone with access and is the single source of truth for course authorization.

| Action | Owner | Co-instructor |
| --- | --- | --- |
| Read the course, its sessions, questions, reports, exports | yes | yes |
| Run sessions, add questions, moderate answers | yes | yes |
| Update course fields | yes | yes |
| Archive / restore the course | yes | no |
| Add / remove instructors | yes | no |
| See the course in `GET /api/v1/courses` | yes | yes |

All three endpoints require instructor authentication; the two mutating ones require a valid CSRF token.

A co-instructor who attempts an owner-only action (archive, restore, or either instructor mutation) gets 403 with the `forbidden` code and a message explaining that the action is owner-only; a caller with no access to the course gets the generic `forbidden` message. The machine-readable code is `forbidden` in both cases.

**`GET /api/v1/courses/{id}/instructors`** — visible to the owner and to co-instructors.

```json
{
  "success": true,
  "data": [
    { "user_id": 7,  "email": "owner@example.org", "display_name": "Ada Lovelace", "role": "owner",         "created_at": "2026-05-14 09:00:00" },
    { "user_id": 12, "email": "co@example.org",    "display_name": "Alan Turing",  "role": "co_instructor", "created_at": "2026-06-01 11:20:00" }
  ]
}
```

Errors: 403 `forbidden`, 404 `course_not_found`.

**`POST /api/v1/courses/{id}/instructors`** — owner only. The body identifies an **existing** instructor account by email; there is no invitation flow.

```json
{ "email": "co@example.org" }
```

```json
{
  "success": true,
  "data": { "user_id": 12, "role": "co_instructor" },
  "message": "Instructor added to the course."
}
```

The target must be an **active account with `role = 'instructor'`**. Addresses that belong to no account, to an admin, or to a deactivated account are all reported as `instructor_not_found`, so the endpoint cannot be used to probe which addresses are registered.

Validation failures follow the `field:reason` convention and return 400 `validation_error` with a `field` key (`email:required`, `email:invalid`). Errors: 400 `validation_error`, 403 `forbidden` (caller is not the owner), 404 `course_not_found`, 404 `instructor_not_found`, 409 `already_course_instructor` (the user is already the owner or already a co-instructor).

**`DELETE /api/v1/courses/{id}/instructors/{userId}`** — owner only. The owner cannot be removed, because a course must always have an owner.

```json
{
  "success": true,
  "data": null,
  "message": "Instructor removed from the course."
}
```

Errors: 403 `forbidden`, 404 `course_not_found`, 404 `course_instructor_not_found` (that user is not on the course), 409 `cannot_remove_course_owner`.

Both mutating endpoints write an `audit_logs` row (`course.instructor_added`, `course.instructor_removed`) against the `course` entity (`FR-90`).

Restore response (`POST /api/v1/courses/{id}/restore`):

```json
{
  "success": true,
  "data": null,
  "message": "Course restored."
}
```

The endpoint requires instructor authentication and a valid CSRF token. It changes only `courses.status` from `archived` to `active`; sessions and reports are preserved. Errors: 403 `forbidden`, 404 `course_not_found`, 409 `invalid_course_state`.

Course analytics response:

```json
{
  "success": true,
  "data": {
    "course": {
      "id": 12,
      "title": "Data Structures",
      "code": "CSE203",
      "semester": "2026-Spring",
      "status": "active"
    },
    "summary": {
      "session_count": 4,
      "closed_session_count": 3,
      "participant_count": 118,
      "question_count": 21,
      "answer_count": 403,
      "average_participation_rate": 0.7935,
      "last_session_at": "2026-05-13 19:00:00"
    },
    "question_type_breakdown": [
      { "type": "multiple_choice", "count": 9 },
      { "type": "open_text", "count": 4 },
      { "type": "yes_no", "count": 3 },
      { "type": "likert_5", "count": 5 }
    ],
    "sessions": [
      {
        "session_id": 42,
        "title": "Week 5 - Linked Lists",
        "short_code": "ABCD23",
        "status": "closed",
        "started_at": "2026-05-13 19:00:00",
        "closed_at": "2026-05-13 19:55:00",
        "participant_count": 31,
        "question_count": 6,
        "answer_count": 177,
        "participation_rate": 0.9516,
        "anonymized": false,
        "is_quiz": false
      }
    ]
  }
}
```

Errors: 404 `course_not_found`, 403 `forbidden`.

### 5.2 Sessions

| Method | Path | Purpose |
| --- | --- | --- |
| POST | `/api/v1/courses/{id}/sessions` | Start a new session under a course |
| GET | `/api/v1/sessions/{id}` | Session detail |
| PATCH | `/api/v1/sessions/{id}` | Update (title, `show_results_to_students`, `moderation_mode`, `is_quiz`, `exam_mode`) |
| POST | `/api/v1/sessions/{id}/pause` | Pause |
| POST | `/api/v1/sessions/{id}/resume` | Resume |
| POST | `/api/v1/sessions/{id}/close` | Close |
| POST | `/api/v1/sessions/{id}/anonymize` | Strip nicknames + device hashes |
| DELETE | `/api/v1/sessions/{id}` | Request deletion (7-day grace) |

`exam_mode` (boolean) overrides result visibility for students: while enabled, it hides live results and answer correctness regardless of `show_results_to_students` or per-question `show_results` (`FR-96`).

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
| POST | `/api/v1/sessions/{id}/questions/import` | Import questions in bulk |
| PATCH | `/api/v1/questions/{id}` | Update a draft question (text, options) |
| POST | `/api/v1/questions/{id}/activate` | Publish (closes any other active question) |
| POST | `/api/v1/questions/{id}/close` | Close the question |
| DELETE | `/api/v1/questions/{id}` | Delete a question |
| POST | `/api/v1/questions/{id}/image` | Upload or replace a draft question image |
| DELETE | `/api/v1/questions/{id}/image` | Remove a draft question image |
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

Create body (fill in the blank):

```json
{
  "question_text": "The powerhouse of the cell is the ____.",
  "question_type": "fill_in_the_blank",
  "correct_answer": "mitochondria"
}
```

`fill_in_the_blank` uses a `correct_answer` string field instead of the `options` array used by `multiple_choice`. The server auto-creates a single `options` row for it (`is_correct=1`, `option_text` = the correct answer) so that grading reuses the existing option-based scoring path.

Question images are attached through the separate multipart endpoint below; JSON create/update bodies MUST NOT contain `image_path`.

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

Errors: 404 `question_not_found`, 422 `invalid_question_type`, 422 `invalid_option_count`, 422 `session_not_active`, 400 `validation_error` (`correct_answer` required or too long, `fill_in_the_blank` only).

#### 5.3.1 Question image attachment

`POST /api/v1/questions/{id}/image`

Request: `multipart/form-data` with field `image`.

Validation:

- Question must exist, belong to the authenticated instructor, and still be `draft`.
- File must be JPG or PNG.
- File size must be 10 MB or smaller.
- Stored path is server-generated under `public/uploads/questions/`; clients never provide `image_path`.
- Replacing an image deletes the previous file when present.

Response 200:

```json
{
  "success": true,
  "data": {
    "image_url": "/uploads/questions/4001_a1b2c3d4e5f60789.png"
  }
}
```

`DELETE /api/v1/questions/{id}/image` removes the stored image reference and deletes the file when present.

Errors: 400 `missing_fields`, 400 `upload_error`, 404 `question_not_found`, 422 `file_too_large`, 422 `invalid_file_type`, 422 `invalid_state_transition`, 500 `upload_failed`.

#### 5.3.2 Question bulk import

`POST /api/v1/sessions/{id}/questions/import`

Imports questions in bulk into the session. Supports two JSON payload formats:

1) Legacy Format:
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

2) Staged Flow Format:
```json
{
  "course_name": "CS101",
  "topic_name": "Loops",
  "sections": {
    "opening": [
      {
        "question_text": "What is a loop?",
        "question_type": "open_text"
      }
    ],
    "middle": [],
    "closing": []
  }
}
```

Validation & Rules:
- Staged flow format processes questions strictly in the order `opening` -> `middle` -> `closing`.
- Stored questions include their corresponding `stage` metadata ('opening', 'middle', 'closing').
- Staged flow format prefixes imported question text with `[Course Name | Topic Name | StageLabel]`.
- Invalid payload structure returns stable error code `invalid_import_payload` (400).

Response 201:
```json
{
  "success": true,
  "data": {
    "ids": [4001],
    "count": 1
  },
  "message": "Questions imported successfully."
}
```

Errors: 400 `invalid_import_payload`, 400 `missing_fields`, 404 `session_not_found`, 403 `forbidden`, 422 `invalid_question_type`, 422 `invalid_option_count`.

#### 5.3.3 Course question bank and LLM generation

`GET /api/v1/courses/{id}/question-bank`

Returns the reusable question bank for the instructor's course. Each item contains the stored question payload plus source metadata.

Response 200:

```json
{
  "success": true,
  "data": [
    {
      "id": 9101,
      "source_kind": "lecture_notes",
      "source_title": "Week 5 notes",
      "question": {
        "question_text": "What is the key idea behind recursion?",
        "question_type": "open_text",
        "stage": "opening",
        "show_results": false,
        "allow_multiple_answers": false,
        "options": []
      },
      "created_at": "2026-06-04T18:00:00Z"
    }
  ]
}
```

`POST /api/v1/courses/{id}/question-bank/generate`

Generates reusable bank questions from lecture notes using the configured LLM provider and stores the results in the course bank.

Request:

```json
{
  "source_title": "Week 5 notes",
  "lecture_notes": "...text pasted from the lecture notes..."
}
```

Response 201:

```json
{
  "success": true,
  "data": {
    "ids": [9101, 9102, 9103],
    "count": 3
  },
  "message": "Questions generated successfully."
}
```

Errors: 400 `missing_fields`, 404 `course_not_found`, 403 `forbidden`, 422 `invalid_question_type`, 422 `invalid_option_count`, 422 `invalid_llm_response`, 503 `llm_unavailable`.

`POST /api/v1/questions/{id}/bank`

Copies an existing session question into the course question bank so it can be reused in later sessions.

Response 201:

```json
{
  "success": true,
  "data": { "id": 9201 },
  "message": "Question saved to the bank."
}
```

Errors: 404 `question_not_found`, 403 `forbidden`.

`POST /api/v1/sessions/{id}/questions/from-bank`

Copies one or more bank questions into the session as draft questions.

Request:

```json
{ "bank_question_ids": [9101, 9102, 9103] }
```

Response 201:

```json
{
  "success": true,
  "data": {
    "ids": [4001, 4002, 4003],
    "count": 3
  },
  "message": "Questions imported successfully."
}
```

Errors: 400 `missing_fields`, 404 `session_not_found`, 404 `question_bank_not_found`, 403 `forbidden`, 422 `invalid_question_type`, 422 `invalid_option_count`.

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
    "word_cloud": [
      { "term": "pointer", "count": 7, "weight": 1.0 },
      { "term": "logic",   "count": 5, "weight": 0.71 }
    ],
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
        "word_cloud": [
          { "term": "pointer", "count": 7, "weight": 1.0 },
          { "term": "logic",   "count": 5, "weight": 0.71 }
        ],
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

### 5.7.1 LMS exports (`FR-98`)

Two file downloads that an instructor uploads to Moodle or Canvas by hand. eduQR never talks to an LMS: there is no API call, no OAuth, no LTI, and no student data leaves the server except through the downloaded file.

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/api/v1/sessions/{id}/questions.gift.txt` | Moodle GIFT question export |
| GET | `/api/v1/sessions/{id}/gradebook.csv?anonymize=true\|false` | Gradebook CSV of quiz scores |

Both require instructor authentication and the same course access as §5.7 — owner or co-instructor (`FR-97`). `exam_mode` (`FR-96`) restricts students only and never blocks these exports.

**`questions.gift.txt`** — `Content-Type: text/plain; charset=utf-8`, `Content-Disposition: attachment; filename="session-{id}-questions.gift.txt"`. UTF-8, no BOM (GIFT is read as UTF-8 text). Contains no participant data at all. Every question is emitted with a `::title::` prefix, so question text can never be mistaken for a `//` comment line. The characters `\ ~ = # { } :` are backslash-escaped and newlines become the GIFT `\n` escape, keeping one question per block.

| eduQR type | GIFT form |
| --- | --- |
| `multiple_choice` (one correct) | multiple choice, `=` correct / `~` wrong |
| `multiple_choice` (several correct) | weighted multiple choice, `~%p%` credit split across the correct options |
| `yes_no` (correct option maps to yes/no) | true/false, `{T}` or `{F}` |
| `fill_in_the_blank` | short answer, `{=answer}` from the single correct option |
| `open_text` | essay, `{}` |
| `likert_5` | essay, `{}`, with the five scale options preserved as visible text |

A question whose GIFT form needs a correct answer but has none marked (a poll-style `multiple_choice` or `yes_no`) is **not** skipped and **not** emitted as broken GIFT: it is downgraded to a valid essay item with its options preserved as visible text and a `//` comment above it recording the downgrade. Nothing is silently lost and the file always imports.

```text
// eduQR — Moodle GIFT export, session 42
::Q1:: Which of these is a stack operation? {
=push
~append
}

::Q2:: How confident are you?\n- Strongly disagree\n- Disagree\n- Neutral\n- Agree\n- Strongly agree {}
```

**`gradebook.csv`** — `Content-Type: text/csv; charset=utf-8`, `Content-Disposition: attachment; filename="session-{id}-gradebook[-anonymized].csv"`, UTF-8 BOM, comma-delimited, exactly as §5.7's `report.csv`. One header row plus one row per participant, ordered by score descending. Columns: nickname, score, maximum score, percentage. The nickname column is the same participant identifier `report.csv` already emits; no other identifying field is added. Cells starting with `=`, `+`, `-`, `@` are prefixed with `'` against spreadsheet formula injection.

The maximum score is the number of questions in the session that have at least one `is_correct` option (`FR-92`). A session with no scorable questions yields a maximum of `0` and a percentage of `0`. A session with no participants yields the header row alone.

`anonymize=true` replaces nicknames with `Participant 1`, `Participant 2`, … exactly as §5.7. A session already anonymized through `POST /api/v1/sessions/{id}/anonymize` (`FR-70`) is anonymous in both exports regardless of the flag, because the nicknames were rewritten in storage.

Errors for both: 401 `not_authenticated`, 403 `forbidden`, 404 `session_not_found`, 404 `course_not_found`.

### 5.8 Open-text theme extraction

`GET /api/v1/questions/{id}/themes`

Returns AI-assisted themes derived from the visible open-text answers of a question. The response is scoped to the instructor who owns the session.

Response 200:

```json
{
  "success": true,
  "data": {
    "question_id": 4002,
    "question_text": "What was the hardest part?",
    "answer_count": 21,
    "themes": [
      {
        "title": "Pointer basics",
        "summary": "Students repeatedly mention pointer usage and following references.",
        "keywords": ["pointers", "references"],
        "example_answers": ["Pointer logic", "Following references"]
      }
    ]
  }
}
```

Errors: 404 `question_not_found`, 403 `forbidden`, 422 `question_not_open_text`, 422 `invalid_llm_response`, 503 `llm_unavailable`.

### 5.9 Locales

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

### 5.10 Comprehension reactions

`GET /api/v1/sessions/{id}/reactions`

Per-question `got_it` / `lost` aggregates for a session (`FR-48`). Ownership is enforced exactly as for §5.5 — the caller must own the session's course.

```json
{
  "success": true,
  "data": [
    { "question_id": 4001, "got_it": 24, "lost": 6 },
    { "question_id": 4002, "got_it": 11, "lost": 18 }
  ]
}
```

Questions with no reactions yet are returned with zero counts. This is the **only** endpoint that exposes reaction totals; the student endpoint (§3.5) never returns them.

Errors: 401 `not_authenticated`, 403 `forbidden`, 404 `session_not_found`.

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
| `nickname_required` | 400 | Nickname absent |
| `nickname_too_long` | 400 | Nickname longer than the limit |
| `nickname_invalid_chars` | 400 | Nickname contains characters outside the allowed set |
| `profane_nickname` | 400 | Nickname failed the profanity check |
| `invalid_credentials` | 401 | Bad email or password |
| `not_authenticated` | 401 | Instructor cookie missing or expired, or the account behind it was deactivated or deleted — the three cases are deliberately indistinguishable to the caller (NFR-87) |
| `not_joined` | 401 | Participant cookie missing or expired |
| `forbidden` | 403 | Authenticated but not allowed |
| `results_hidden` | 403 | Student requested results while toggle is off |
| `session_not_found` | 404 | Short code or session ID does not resolve |
| `course_not_found` | 404 | |
| `question_not_found` | 404 | |
| `question_not_open_text` | 422 | Theme extraction requested for a non open-text question |
| `question_bank_not_found` | 404 | Reusable question bank item not found or not in scope |
| `instructor_not_found` | 404 | No active instructor account exists for the given email |
| `course_instructor_not_found` | 404 | That user is not an instructor on this course |
| `answer_not_found` | 404 | |
| `upload_error` | 400 | Uploaded image transfer failed |
| `email_taken` | 409 | Account email already exists |
| `already_course_instructor` | 409 | User already has instructor access to this course |
| `cannot_remove_course_owner` | 409 | The course owner cannot be removed from the course |
| `duplicate_nickname` | 409 | Nickname already taken in this session |
| `duplicate_answer` | 409 | Participant already answered this question |
| `session_closed` | 410 | Session is closed |
| `question_closed` | 410 | Question is closed |
| `invalid_answer_shape` | 422 | Answer body does not match the question type |
| `invalid_reaction` | 422 | Unknown comprehension reaction value |
| `invalid_question_type` | 422 | Unknown `question_type` value |
| `invalid_option_count` | 422 | Wrong number of options for the type |
| `invalid_stage` | 422 | Unknown `stage` value in a question import |
| `invalid_import_payload` | 400 | Import body is not a shape the endpoint accepts |
| `invalid_llm_response` | 422 | AI provider returned malformed question JSON |
| `file_too_large` | 422 | Uploaded image is larger than allowed |
| `invalid_file_type` | 422 | Uploaded image is not JPG or PNG |
| `invalid_state_transition` | 422 | e.g. resuming a closed session |
| `session_paused` | 422 | Action not allowed while session is paused |
| `session_not_active` | 422 | Action requires an active session |
| `too_many_attempts` | 429 | Rate limit hit |
| `llm_unavailable` | 503 | LLM provider unavailable or not configured |
| `upload_failed` | 500 | Uploaded image could not be saved |
| `server_error` | 500 | Unhandled exception |

Internal logs use the same `snake_case` codes; user-facing messages come from translation keys (`error.<code>`). See `I18N_SPEC.md` §11.

### 7.1 Validation failures

A validation failure is a domain failure and is answered the same way as any
other one: from a typed exception carrying the status, the published code and
the offending field (NFR-83). It is not signalled by a `field:reason` string
that a controller then translates in a private table.

Two rules decide the envelope:

- **`field` is present when one named input is at fault, and absent otherwise.**
  `question_type:invalid` names `question_type`; `invalid_answer_shape` names
  nothing, because it is the combination of body and question type that is
  wrong, not one member of it.
- **A specific published code beats the generic one.** `validation_error` and
  `missing_fields` are what a failure falls back to, never what it is promoted
  to. Where an endpoint already publishes something more precise, every
  endpoint reaching the same throw site publishes it too.

Four responses used to differ depending on which endpoint reached the throw
site, because `QuestionBankService::copyToSession()` calls
`QuestionService::create()` and the two controllers held disagreeing tables.
They did not all differ the same way: the two `correct_answer` rows and the
`stage` row had no arm at all on the bank endpoint and fell through to a
generic `400`, while `question_type` was already `422 invalid_question_type` on
both — it was the question endpoint that omitted `field`. The single answer for
each, in force from NFR-83 onward:

| Failure | HTTP | Code | `field` |
| --- | --- | --- | --- |
| Unknown `question_type` | 422 | `invalid_question_type` | `question_type` |
| Unknown import `stage` | 422 | `invalid_stage` | `stage` |
| `correct_answer` absent | 400 | `validation_error` | `correct_answer` |
| `correct_answer` too long | 400 | `validation_error` | `correct_answer` |

The first two were already the question endpoint's answer and are now the
question-bank endpoint's as well; the bank endpoint previously fell through to
`400 validation_error`. The last two gain the `field` member on the bank
endpoint, which had no arm for them at all.

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
