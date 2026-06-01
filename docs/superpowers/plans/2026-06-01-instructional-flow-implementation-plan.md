# eduQR Instructional Flow — Implementation Plan (Brain + agykit Model)

Date: 2026-06-01  
Owner: Codex (architecture/QA brain)  
Executor: agykit (implementation worker)

## 1) Objective

Implement end-to-end classroom flow:
- Instructor selects course + topic and starts a session.
- Large QR is shown for student join.
- Students answer opening questions, then middle, then closing questions.
- Live answer analytics update continuously on instructor screen.
- Optional projector sharing is available.
- All reports are persisted and reviewable later.

## 2) Requirement Mapping

Primary requirement alignment (existing):
- FR-21 session short code + QR join
- FR-31 question types
- FR-33 one-active-question rule
- FR-44 one-answer-per-question-per-participant
- FR-45 active-question polling for students
- FR-80 reporting/export
- FR-87 translated validation and user messages
- FR-90 audit logging
- NFR-21 session/response performance and reliability
- NFR-24 CSRF protection

Gap note:
- "Opening/Middle/Closing staged flow" is currently implicit. Add explicit FR in `PRODUCT_REQUIREMENTS.md` before final merge if needed.

## 3) Architecture Decisions (for this feature)

1. Keep FR-33 intact: only one active question at a time.
2. Add stage metadata to questions: `opening | middle | closing`.
3. Instructor controls stage progression and per-question activation.
4. Student can continue in same session without re-joining; QR re-show is optional.
5. Live results stay polling-based (no websocket).
6. Reports remain session-scoped and include stage grouping.

## 4) Work Breakdown (for agykit)

### WP-1 Data Model and Migration
Scope:
- Add `topic_name` to sessions.
- Add `stage` to questions enum (`opening`, `middle`, `closing`) default `middle`.
- Indexes for `questions(session_id, stage, order_no)`.

Deliverables:
- New migration file.
- Updated `database/schema.sql`.
- Updated `DATA_MODEL.md`.

Acceptance:
- `php bin/migrate.php` clean.
- Existing sessions/questions remain valid with defaults.

### WP-2 JSON Import v2 Schema
Scope:
- Support import payload:
  - `course_name`
  - `topic_name`
  - `sections.opening[]`
  - `sections.middle[]`
  - `sections.closing[]`
- Persist stage for each imported question.
- Preserve deterministic order: opening -> middle -> closing.

Deliverables:
- Controller/service validation updates.
- Localized error messages for malformed payload.
- API examples in `API_SPEC.md`.

Acceptance:
- Import creates expected count and stage mapping.
- Invalid stage payload returns stable error code.

### WP-3 Instructor Start Session UX
Scope:
- Session start UI includes topic input/select.
- On start, show full-page QR panel and join short code.
- Add quick controls: "Start Opening", "Start Middle", "Start Closing".

Deliverables:
- Template updates under `templates/admin/sessions/*`.
- JS controls for stage-filtered activation list.
- Translation keys in `locales/en.json` and `locales/tr.json`.

Acceptance:
- Instructor can start session and navigate stages without manual route editing.

### WP-4 Student Flow Behavior
Scope:
- Join once; keep participant continuity through full session.
- Optional setting: prevent re-showing already answered question (default true).
- `answered`/`wait` screens transition correctly between stages.

Deliverables:
- Student templates + polling logic updates.
- If setting added: session-level toggle + API propagation.

Acceptance:
- Student does not need to re-join between opening/middle/closing.
- Duplicate answers still blocked.

### WP-5 Live Analytics + Projector
Scope:
- Instructor live page grouped by stage and active question.
- Projector mode can mirror selected live result.
- Keep student identity handling aligned with privacy settings.

Deliverables:
- Results endpoint payload extension for `stage`.
- Admin live template and projector template adjustments.

Acceptance:
- New answers appear in <= polling interval.
- Projector view usable without instructor controls.

### WP-6 Persistent Reporting
Scope:
- Session report groups by stage.
- Include stage in CSV/HTML exports.
- Ensure historical sessions remain reviewable.

Deliverables:
- Report service/controller/template updates.
- API spec/report docs updates.

Acceptance:
- Closed session reports include all stage responses.
- No regression in existing report endpoints.

### WP-7 QA, Security, and Hardening
Scope:
- Unit + integration tests for stage flow and imports.
- CSRF/authz checks on new endpoints.
- i18n coverage validation.

Deliverables:
- Tests under `tests/Unit` and `tests/Integration`.
- `composer test`, `composer lint`, `php bin/locale-check.php tr` pass.

Acceptance:
- No new high/critical issues from `composer audit`.

## 5) agykit Execution Protocol

For each WP:
1. Codex defines exact change boundaries and expected files.
2. agykit implements only that WP.
3. agykit returns:
- Changed files list
- Diff summary
- Test/lint outputs
- Risks/assumptions
4. Codex reviews:
- spec compliance (AGENTS laws)
- security/privacy checks
- translation completeness
- regression risk
5. Only after approval, move to next WP.

## 6) Review Checklist (Codex Gate)

- No hardcoded user-facing strings
- No SQL in controllers/templates
- All new strings in both `en.json` and `tr.json`
- FR/NFR mapping stated in commit message and test names
- Migration append-only rule respected
- API docs updated before endpoint behavior freeze
- Polling intervals unchanged unless explicitly required

## 7) Suggested Delivery Order

1. WP-1
2. WP-2
3. WP-3
4. WP-4
5. WP-5
6. WP-6
7. WP-7

## 9) Progress Log

- 2026-06-01: `WP-1` completed.
  - Added migration `0013_session_topic_and_question_stage.sql`.
  - Added `sessions.topic_name` and `questions.stage` (+ stage index).
  - Updated `database/schema.sql`, `DATA_MODEL.md`, `README.md`.
  - Migration verified with `php bin/migrate.php`.

## 8) First Task Packet to agykit

Packet ID: `AGY-WP1-STAGE-DATA-MODEL`

Task:
- Create migration adding `sessions.topic_name` and `questions.stage`.
- Update schema snapshot and DATA_MODEL documentation.
- Do not modify unrelated files.

Expected files:
- `database/migrations/00NN_add_topic_and_stage.sql`
- `database/schema.sql`
- `DATA_MODEL.md`

Validation commands:
- `php bin/migrate.php`
- `composer test` (or targeted integration if full suite is slow)
