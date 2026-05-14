# Project Brief — eduQR

This document defines **why** eduQR exists, **who** it is for, and **what** the first version must and must not do. It is the source of truth for product scope. When in doubt about whether a feature belongs in the product, refer back to this file.

---

## 1. Identity

| Field | Value |
| --- | --- |
| Name | eduQR |
| Full title | QR-Based Interactive Classroom Polling and Learning Analytics Platform |
| Tagline | Interactive learning starts with a scan. |
| Owner | Prof. Dr. İsmail Kırbaş — Burdur Mehmet Akif Ersoy University |

## 2. Vision

To transform passive lectures into active, measurable, two-way learning experiences by giving every instructor a frictionless way to ask, every student a frictionless way to answer, and both sides a data-rich view of how the class is actually understanding the material.

Long term: a lightweight, privacy-aware, multilingual classroom interaction platform for higher education, workshops, and training environments — self-hostable on standard institutional infrastructure.

## 3. Problem Statement

In higher-education classrooms today:

- **Instructors** rarely get honest, in-the-moment feedback on whether students are following along. Asking "any questions?" yields silence even when comprehension is low.
- **Students** are reluctant to interrupt, embarrassed to admit confusion, or simply disengaged. Lectures become one-way broadcasts.
- **Existing tools** (Mentimeter, Slido, Kahoot) are expensive at institutional scale, store data outside the institution's jurisdiction (a problem under KVKK / GDPR), require accounts, and are not localized for Turkish higher education.
- **Post-session analytics** are either non-existent or locked behind paywalls, so instructors cannot evidence-base their teaching improvements.

eduQR addresses all four problems with a self-hostable, multilingual, privacy-first platform.

## 4. Target Users

### Primary

**Instructor** — `role = 'instructor'`
University faculty or instructors who deliver in-person, hybrid, or online lectures and want real-time feedback during class plus structured reports afterward.

**Student** — `participant`, anonymous
Enrolled or attending student who joins a session via a QR code. No account required. Identified only by a self-chosen nickname and an opaque device hash.

### Secondary

**Admin** — `role = 'admin'`
Manages instructor accounts, institutional defaults, language and privacy settings. In MVP, performs account creation via CLI.

**Researcher / teaching-quality office**
Reads anonymized aggregate reports to inform curriculum and quality assurance.

### Tertiary (post-MVP)

- Workshop facilitators
- Training-center instructors
- Continuing-education programs
- Educational-technology researchers

## 5. Value Proposition

| For | Promise |
| --- | --- |
| Instructors | "Ask any question, get answers in seconds, see a real report at the end of every class." |
| Students | "Scan once, answer in five seconds, no account, no tracking of who you are." |
| Departments | "Self-host on existing infrastructure, satisfy KVKK / GDPR, use it in any language." |
| Researchers | "Get structured CSV / PDF exports of learning-feedback data without IT-ticket overhead." |

## 6. Main Use Case

An instructor starts a classroom session and displays a QR code through a projector. Students scan it with their mobile devices, enter a nickname, and join. During the lecture, the instructor publishes multiple-choice or open-ended questions. Students answer from their phones. The instructor monitors live response distributions and later reviews a detailed session report.

End-to-end student time from "see QR" to "first answer submitted": **under 30 seconds**.
End-to-end instructor time from "start session" to "first question published": **under 60 seconds**.

## 7. Educational Value

eduQR supports:

- Active learning
- Formative assessment
- Immediate classroom feedback
- Student engagement
- Reflection prompts and exit tickets
- Peer-discussion starting points
- Learning analytics
- Evidence-based instructional improvement

## 8. Goals (MVP — must ship)

The first shipping version must:

1. Let an authenticated instructor create a course and start a live session.
2. Generate a 6-character short code and a QR pointing to a public join URL.
3. Allow students to join with only a nickname — no email, no account.
4. Support four question types: `multiple_choice`, `open_text`, `yes_no`, `likert_5`.
5. Publish one question at a time to all connected students with sub-5-second visibility.
6. Collect, store, and display answers in real time (via polling).
7. Toggle whether the live result is visible to students.
8. Produce a per-session report on demand (HTML view + CSV export).
9. Be fully usable in **English** and **Turkish**, with every UI string translatable via locale files.
10. Run on a standard cPanel / shared-hosting environment with PHP 8.2+ and MySQL 8 / MariaDB 10.6+.

## 9. Non-Goals (MVP — must NOT ship)

The first version explicitly does **not** include:

- WebSocket / Socket.IO real-time push. MVP uses HTTP polling at 2–3 second intervals.
- Student accounts, logins, or persistent identity across sessions.
- Grading, gradebook, or LMS integration.
- AI-powered open-ended-response summarization (planned for Phase 11).
- Mobile native apps. The student side is mobile-web only.
- Payment, licensing, or multi-tenant billing.
- Video / audio / drawing question types.
- Offline mode.
- Multi-instructor course ownership.
- Public sign-up. Admins create accounts via `bin/user-add.php`.

These may be added in later phases — see `TASKS.md` §11.

## 10. Success Metrics

How we judge whether the MVP is working in real classrooms:

| Metric | Target |
| --- | --- |
| Time for a student to scan QR and submit first answer | < 30 seconds |
| Instructor's time to create a session and publish first question | < 60 seconds |
| Participation rate (answers per question / attendees) | > 70 % |
| Crashes or 5xx errors per 1,000 answers | < 1 |
| Instructor satisfaction (post-semester survey, 1–5) | ≥ 4.0 |
| Number of languages with ≥ 95 % translation coverage | ≥ 2 at launch (en + tr) |
| Sessions reaching successful "report exported" state | > 95 % |

## 11. Key Feature Roadmap

### MVP

- Instructor login
- Course management
- Live session creation + QR display
- Student join (nickname-only)
- `multiple_choice`, `open_text`, `yes_no`, `likert_5` questions
- Live result display + projector view
- Session report + CSV export
- English + Turkish UI

### Near-term (Phase 11)

- PDF export
- Report anonymization (already shipped in MVP per `FR-70`)
- Question templates
- Session archive
- Add `de`, `fr` locales
- Rate limiting & abuse prevention hardening

### Long-term

- AI-assisted open-ended-response summarization
- Word cloud / theme extraction
- Quiz mode with scoring
- Light gamification (badges, streaks)
- Course-level analytics across sessions
- LMS integration (Moodle, Canvas)
- Multi-instructor / organizational tenancy
- RTL locales (Arabic, Hebrew, Farsi)
- WebSocket-based real-time

## 12. Scope Boundaries

eduQR is a **live polling and post-session analytics tool**. It is intentionally *not*:

- An LMS (Moodle, Canvas, etc.) — though it may export reports an LMS could ingest.
- A graded-exam tool — see the existing `TestMaker / OptikFormApp` system for that.
- A standalone survey product (SurveyMonkey-style) — eduQR's value depends on the QR-to-classroom flow.

Whenever a feature request edges toward LMS, exam grading, or general-purpose surveying, it is out of scope unless explicitly added to `TASKS.md`.

## 13. Guiding Principles

When two design choices conflict, apply these in order:

1. **Privacy by default.** Collect the least data possible. Anonymize aggressively.
2. **Sub-30-second join.** Anything that adds friction for the student is wrong.
3. **One instructor, one screen.** The live panel must be usable while teaching, not just from a desk.
4. **i18n is not optional.** No hardcoded strings, ever. See `I18N_SPEC.md`.
5. **Self-host friendly.** If a feature requires an exotic dependency that a shared-hosting cPanel cannot run, defer it.
6. **Reports beat charts.** A clear CSV and a printable HTML report are worth more than animated dashboards.
7. **Small over clever.** Plain PHP code beats elegant abstractions for MVP.

## 14. Out-of-the-Box Assumptions

If any of these is wrong for a deployment, halt and confirm before coding around it:

- The instructor has a modern desktop browser (Chrome / Firefox / Safari, last 2 major versions).
- The student has a smartphone with a camera and a modern mobile browser.
- The classroom has a projector connected to the instructor's laptop.
- The classroom Wi-Fi (or student mobile data) supports HTTPS and ~2 KB / answer payloads.
- The server has PHP 8.2+, MySQL 8 / MariaDB 10.6+, and HTTPS enabled.
- Instructor accounts are created out-of-band by an admin via `bin/user-add.php`.

## 15. Positioning Statement

eduQR is a multilingual, self-hostable, privacy-aware QR-based classroom polling and learning-analytics platform that helps instructors transform passive lectures into interactive, data-informed learning sessions — without forcing students to create accounts or sending classroom data outside institutional infrastructure.
