# Acceptance Criteria — eduQR

This document defines what "done" means. A feature, phase, or module is **not complete** until its criteria here are satisfied. AI coding agents must verify against this file before claiming a task is finished, and must clearly document any unmet criterion rather than assuming completion.

Each criterion links back to a requirement ID where applicable.

---

## 1. How to Use This Document

- **Per-phase checkpoints** (§3) gate moving from one phase to the next in `TASKS.md`.
- **Per-module criteria** (§4) gate considering a module's code complete.
- **MVP criteria** (§2) gate declaring the MVP ready for a classroom pilot.
- **Pilot criteria** (§5) gate the first real classroom use.

A criterion is either met or not met. There is no "mostly done."

---

## 2. MVP Acceptance Criteria

The MVP is complete when **all** of the following hold.

### 2.1 Instructor can

```text
[ ] Log in with email + password                                          [FR-01]
[ ] Log out from any page                                                  [FR-04]
[ ] Be redirected to login when accessing a protected route unauthenticated [NFR-23]
[ ] Create a course (title, code, semester, description, default language)  [FR-10]
[ ] View, edit, and archive their own courses                               [FR-11, FR-12, FR-13]
[ ] Be denied access to another instructor's course                         [FR-14]
[ ] Start a live session under a course                                     [FR-20]
[ ] See a generated 6-char short code and QR code                           [FR-21, FR-22]
[ ] Open a projector view showing the QR large                              [FR-54]
[ ] Create multiple_choice, open_text, yes_no, and likert_5 questions        [FR-31]
[ ] Activate a question (which closes any other active question)            [FR-33, FR-34]
[ ] Close a question                                                        [FR-34]
[ ] Pause and resume a session                                              [FR-25]
[ ] See a live participant count                                            [FR-27]
[ ] View live results updating automatically                                [FR-50, NFR-02]
[ ] Toggle whether students see live results                                [FR-53]
[ ] Close a session                                                         [FR-24]
[ ] View a session report                                                   [FR-60]
[ ] Export the report as CSV                                                [FR-62]
[ ] Anonymize a closed session                                              [FR-70]
[ ] Use the entire interface in English or Turkish                          [FR-81]
```

### 2.2 Student can

```text
[ ] Scan a QR or open a session link to reach the join page                 [FR-40]
[ ] Enter a nickname and join an active session                             [FR-41]
[ ] Be blocked from joining with a duplicate nickname (case-insensitive)     [FR-42]
[ ] Be blocked from joining a closed or paused session, with a clear message [FR-47]
[ ] See a waiting screen when no question is active                          [FR-45]
[ ] See the active question when one is published                            [FR-45]
[ ] Submit an answer to multiple_choice / open_text / yes_no / likert_5       [FR-44]
[ ] Be prevented from answering the same question twice                      [FR-44]
[ ] See a confirmation after submitting                                       [FR-45]
[ ] See the privacy notice on the join page                                  [FR-75]
[ ] Use the student interface on a mobile browser                             [NFR-12]
[ ] Submit one answer even with JavaScript disabled (form fallback)           [NFR-44]
```

### 2.3 Internationalization

```text
[ ] English interface available, complete                                    [FR-81]
[ ] Turkish interface available, >= 95% key coverage                          [FR-81]
[ ] English is the fallback language                                          [FR-83]
[ ] No user-facing string is hardcoded anywhere                                [FR-80]
[ ] Language switcher present on every page with UI                            [FR-88]
[ ] Locale persists across requests via cookie                                 [FR-82]
[ ] Missing translation keys fall back to English, then to the key             [FR-83]
[ ] Validation and error messages use translation keys                          [FR-87]
[ ] Dates and numbers format per the active locale                              [FR-85]
```

### 2.4 Reporting

```text
[ ] Report includes course title, session title, dates                        [FR-61]
[ ] Report includes participant count, question count, total answer count      [FR-61]
[ ] Report includes per-question answer count and distribution                 [FR-61]
[ ] Report includes open-ended answers rendered safely                          [FR-61, SEC §10]
[ ] CSV export works and is anonymizable                                         [FR-62]
[ ] Anonymized report/export contains no nicknames                               [FR-70]
[ ] No report or export contains device hashes or IP addresses                   [FR-72, FR-73]
[ ] Reports require instructor authentication; no public report URL              [FR-74]
```

### 2.5 Security & Privacy

```text
[ ] Passwords are bcrypt-hashed; no plain text ever stored                       [FR-02]
[ ] Login errors never reveal whether the email exists                           [FR-08]
[ ] Failed logins are rate-limited                                               [FR-05]
[ ] All instructor routes are auth-protected                                     [NFR-23]
[ ] All SQL uses prepared statements                                              [NFR-21]
[ ] All user-generated output is escaped before HTML render                       [NFR-22]
[ ] CSRF protection on every state-changing instructor request                    [NFR-24]
[ ] Security headers (CSP, HSTS, X-Frame, etc.) on every response                  [NFR-25]
[ ] Cookies use HttpOnly (where applicable) + Secure + correct SameSite            [NFR-23]
[ ] Session codes are random and not guessable                                    [FR-21]
[ ] Closed sessions reject new participants and answers                            [FR-24]
[ ] Paused sessions reject new answers                                             [FR-25]
[ ] Closed questions reject new answers                                            [FR-44]
[ ] Students are never required to provide personal data beyond a nickname         [NFR-32]
[ ] Logs never contain passwords, cookies, raw answers, or device hashes           [NFR-73]
```

---

## 3. Per-Phase Checkpoints

Each phase in `TASKS.md` is gated by the checkpoint below. Do not start the next phase until the current one passes.

### Phase 0 — Project Setup

```text
[ ] App serves a home page
[ ] bin/migrate.php runs cleanly against an empty database
[ ] composer lint and composer test both run (even if test suite is near-empty)
[ ] ADRs 0001-0004 exist
```

### Phase 1 — Internationalization Foundation

```text
[ ] Home page renders in both en and tr
[ ] bin/locale-check.php tr reports >= 95% coverage
[ ] GET /api/v1/locales returns en and tr
[ ] No hardcoded string in any file touched so far
```

### Phase 2 — Instructor Authentication

```text
[ ] Instructor can log in and log out
[ ] Protected routes redirect unauthenticated users to login
[ ] Passwords are bcrypt-hashed
[ ] 5 failed logins within 10 min trigger a lockout
[ ] CSRF token required on the login POST
[ ] bin/user-add.php creates a working account
```

### Phase 3 — Course Management

```text
[ ] Instructor can create, view, edit, archive their own courses
[ ] Instructor cannot read or modify another instructor's course
[ ] All course UI uses translation keys
[ ] Course field validation rejects out-of-range input with localized messages
```

### Phase 4 — Session Management & QR Code

```text
[ ] Instructor can start a session under a course
[ ] A unique 6-char short code is generated (charset A-HJ-NP-Z2-9)
[ ] A join URL and QR PNG are produced
[ ] The public join URL opens a student-facing page
[ ] Instructor can pause, resume, and close a session
[ ] State transitions are guarded (cannot resume a closed session)
[ ] Projector view renders the QR large and readable
```

### Phase 5 — Student Join Flow

```text
[ ] Student can open the join link and see the nickname form
[ ] Student can join an active session with a valid nickname
[ ] Duplicate nicknames (case-insensitive) are rejected
[ ] Profanity-listed nicknames are rejected
[ ] Returning students are auto-restored via the persistent device cookie
[ ] Closed / paused sessions show a clear localized message and block joining
[ ] Student lands on a waiting screen when no question is active
[ ] The privacy notice is visible on the join page
[ ] The student UI is usable at 360 px width
```

### Phase 6 — Question Management

```text
[ ] Instructor can create all four question types
[ ] multiple_choice enforces 2-8 options; yes_no auto-creates 2; likert_5 auto-creates 5
[ ] Instructor can activate and close a question
[ ] Activating a question closes any other active question in that session
[ ] The student active-question endpoint returns the active question
[ ] Draft questions are never returned to students
[ ] Question and option text are sanitized
```

### Phase 7 — Answer Collection

```text
[ ] Student can answer the active question for each question type
[ ] Duplicate answers are rejected with a graceful 409
[ ] Answers to closed questions are rejected
[ ] Answers to closed or paused sessions are rejected
[ ] selected_option_id must belong to the question
[ ] Open-text answers are sanitized and capped at 2000 chars
[ ] A confirmation is shown after submission
[ ] One answer can be submitted with JavaScript disabled
```

### Phase 8 — Live Results

```text
[ ] Instructor sees participant count and answer count
[ ] multiple_choice / yes_no / likert_5 results show counts + percentages
[ ] open_text results show a safe list with nickname + timestamp
[ ] Results refresh automatically (instructor 2s, student 3s polling)
[ ] A new answer is visible within 5 seconds
[ ] The projector result view is readable from the back of a classroom
[ ] show_results toggles correctly gate student-visible results
[ ] An instructor can hide an inappropriate open-text answer
[ ] 100 concurrent answer submissions stay under 300 ms p50
```

### Phase 9 — Reports & Export

```text
[ ] Instructor can open a report for a closed session
[ ] Report shows session metadata, summary stats, and per-question results
[ ] Report shows open-ended answers safely
[ ] CSV export works; CSV cells are protected against formula injection
[ ] anonymize=true hides nicknames in report and export
[ ] No device hash or IP appears in any report or export
[ ] Session anonymization and soft-deletion work
[ ] bin/cleanup.php hard-deletes after the grace period
```

### Phase 10 — Security, Privacy & Quality Hardening

```text
[ ] audit_logs records all FR-90 actions
[ ] Security headers verified with curl
[ ] Every instructor route confirmed behind AuthMiddleware
[ ] Every template confirmed to escape user content
[ ] Every repository confirmed to use prepared statements only
[ ] No secrets / answers / hashes appear in logs
[ ] en/tr parity enforced in CI
[ ] Service + repository coverage >= 60%
[ ] bin/smoke.php passes
[ ] Deployment hardening checklist (SECURITY_PRIVACY.md §21) fully green
```

---

## 4. Per-Module Acceptance Criteria

### 4.1 Authentication Module

```text
[ ] Login form validates required fields
[ ] Invalid credentials return a safe generic error (no email enumeration)   [FR-08]
[ ] Successful login creates an authenticated instructor session              [FR-01]
[ ] Logout clears the authenticated session                                   [FR-04]
[ ] Protected routes redirect unauthenticated users to login                   [NFR-23]
[ ] Password hashing uses bcrypt cost 12                                       [FR-02]
[ ] Failed-login rate limiting works                                            [FR-05]
```

### 4.2 Course Module

```text
[ ] Instructor can create a course with all fields                             [FR-10]
[ ] Instructor can list, edit, and archive courses                              [FR-11, FR-12, FR-13]
[ ] Course ownership is enforced on every read and write                        [FR-14]
[ ] Course UI uses translation keys                                              [FR-80]
```

### 4.3 Session Module

```text
[ ] Instructor can create a session under a course                              [FR-20]
[ ] A unique random 6-char short code is generated                               [FR-21]
[ ] A join URL and QR code are produced                                          [FR-22]
[ ] Session status lifecycle is enforced (draft/active/paused/closed)            [FR-23]
[ ] Instructor can pause, resume, and close                                       [FR-24, FR-25]
[ ] Closed sessions reject new joins and answers                                  [FR-24]
[ ] Paused sessions reject new answers                                            [FR-25]
[ ] Session UI uses translation keys                                              [FR-80]
```

### 4.4 Student Join Module

```text
[ ] Public join page loads for a valid active session                            [FR-40]
[ ] Invalid session codes show a safe error page                                  [—]
[ ] Closed sessions show a closed-session message                                 [FR-47]
[ ] Nickname is required, validated, sanitized, profanity-filtered                 [FR-41, FR-43]
[ ] Nickname is unique within the session, case-insensitive                        [FR-42]
[ ] Participant record is created after a successful join                          [FR-40]
[ ] Student is redirected to the waiting / active-question page                    [FR-45]
[ ] Student UI is mobile-first                                                     [NFR-12, NFR-14]
[ ] Privacy notice is shown                                                        [FR-75]
```

### 4.5 Question Module

```text
[ ] Instructor can create all four question types                                 [FR-31]
[ ] Options are stored for option-based question types                              [FR-32]
[ ] Instructor can activate and close a question                                    [FR-34]
[ ] One-active-question rule enforced                                                [FR-33]
[ ] The student interface can retrieve the active question                           [FR-45]
[ ] Question and option text are sanitized                                            [SEC §8]
[ ] Draft questions are never visible to students                                     [—]
```

### 4.6 Answer Module

```text
[ ] Student can submit an answer to the active question                              [FR-44]
[ ] Answer is stored with participant and question references                         [FR-44]
[ ] Option-based answers validate selected option ownership                            [FR-44]
[ ] Open-ended answers are sanitized                                                   [SEC §10]
[ ] Duplicate answer prevention works when multiple answers are not allowed             [FR-44]
[ ] Answers to closed questions are rejected                                            [FR-44]
[ ] Answers to closed / paused sessions are rejected                                    [FR-24, FR-25]
[ ] A confirmation message is displayed                                                  [FR-45]
```

### 4.7 Live Results Module

```text
[ ] Instructor sees participant count and answer count                                  [FR-50]
[ ] Distribution shown for option-based questions                                        [FR-51]
[ ] Open-ended answers shown safely                                                       [FR-52]
[ ] Results refresh via polling (instructor 2s, student 3s)                               [FR-45, NFR-02]
[ ] Projector view is classroom-readable                                                  [FR-54]
[ ] show_results gating works                                                              [FR-53]
[ ] Instructor can hide inappropriate open-text answers                                    [FR-55]
```

### 4.8 Report Module

```text
[ ] Instructor can open a report for a session                                            [FR-60]
[ ] Report shows session metadata and summary stats                                         [FR-61]
[ ] Report shows question-level results and open-ended answers safely                        [FR-61]
[ ] CSV export works                                                                          [FR-62]
[ ] Anonymized export hides nicknames                                                         [FR-70]
[ ] Device hashes and IPs never appear                                                        [FR-72, FR-73]
[ ] Reports require instructor authentication                                                  [FR-74]
```

### 4.9 Internationalization Module

```text
[ ] Translation loader reads en.json and tr.json                                              [FR-81]
[ ] Missing keys fall back to English, then to the key                                          [FR-83]
[ ] New UI labels are added to both language files                                              [FR-80]
[ ] Language switcher works on every page with UI                                                [FR-88]
[ ] No major user-facing page contains a hardcoded label                                          [FR-80]
[ ] Locale resolution follows the documented order                                                [I18N §6]
```

---

## 5. Classroom Pilot Acceptance

The system is ready for classroom pilot testing when:

```text
[ ] An instructor can run a complete session end-to-end: QR -> join -> question -> answer -> report
[ ] At least 30 simulated students can join and answer questions concurrently
[ ] No 5xx errors occur during answer submission under that load
[ ] The report's counts and distributions exactly match the submitted answers
[ ] Both Turkish and English interfaces are fully usable
[ ] The student interface works on real iOS Safari and Android Chrome devices
[ ] The QR code can be scanned from a projected screen at typical classroom distance
[ ] A student joining the journey from scan to first answer completes it in under 30 seconds
```

---

## 6. Completion Notes

If any criterion is not met, the feature, phase, or module is **incomplete**. AI coding agents must document the unmet criteria explicitly in the change summary — never silently assume completion or quietly downgrade a `MUST` requirement.

When a criterion cannot be met because of a genuine spec gap, the correct action is to update `PRODUCT_REQUIREMENTS.md` (and this file) first, with the human owner's approval, and then proceed.
