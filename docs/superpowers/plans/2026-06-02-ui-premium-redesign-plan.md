# eduQR Premium UI Redesign — Implementation Plan

Date: 2026-06-02  
Owner: Codex (architecture/QA brain)  
Executor: agykit (implementation worker)

## 1) Objective

Make the existing eduQR experience feel modern, premium, and consistent across:
- Public landing and login
- Student join, waiting, answered, batch, and play screens
- Admin dashboard, course pages, and session control pages
- Live projector / results views

This is a visual and interaction-layer pass only. It must not change the core classroom flow, question model, or polling-based realtime behavior.

## 2) Design Direction

The UI direction is fixed:
- Pastel palette instead of flat utility-default styling
- Glass / soft-surface cards with stronger spacing and hierarchy
- Icon-led navigation, metrics, and empty states
- Better mobile presentation without a separate mobile-only code path
- Dark mode retained and visually aligned with the same premium system

## 3) Requirement Mapping

This work sits on top of existing product requirements:
- FR-45 active-question polling for students
- FR-54 live polling on results screens
- FR-80 translated UI strings and locale files
- FR-81 Turkish locale coverage
- FR-88 language switcher and locale selection
- NFR-21 responsive and reliable classroom UX
- NFR-52 tests for changed behavior

No new functional requirement is introduced by this redesign.

## 4) Work Breakdown

### WP-1 Shared Visual System

Scope:
- Replace the shared CSS with a premium tokenized palette.
- Add reusable shells, cards, chips, stat cards, and projector surfaces.
- Introduce a small inline icon helper for consistent iconography without adding a new frontend dependency.

Deliverables:
- `public/assets/css/app.css`
- `public/assets/css/projector.css`
- `src/helpers.php` icon helper
- Layout shell updates in `templates/layouts/*.php`

Acceptance:
- Public, admin, and projector pages share the same visual language.
- Theme toggle and language switcher still work.

### WP-2 Student Experience

Scope:
- Modernize the student landing, join, wait, answered, play, and batch-answer screens.
- Keep the existing join/poll/answer behavior unchanged.
- Use icon-led cards, better spacing, and clearer empty states.

Deliverables:
- `templates/home.php`
- `templates/auth/login.php`
- `templates/student/join.php`
- `templates/student/play.php`
- `templates/student/wait.php`
- `templates/student/answered.php`
- `templates/student/batch.php`

Acceptance:
- Student flow feels like one coherent product.
- No regression in join, polling, answer submission, or duplicate-answer handling.

### WP-3 Admin Experience

Scope:
- Restyle the dashboard and course/session management pages.
- Convert tables into more premium card rows where it helps scanning.
- Keep existing controls, IDs, and form behavior intact.

Deliverables:
- `templates/admin/dashboard.php`
- `templates/admin/courses/list.php`
- `templates/admin/courses/detail.php`
- `templates/admin/sessions/detail.php`
- `templates/admin/sessions/results.php`
- `templates/layouts/admin.php`

Acceptance:
- Instructor pages remain functional and become easier to scan.
- Session controls, QR display, and live links stay accessible.

### WP-4 Live Results / Projector

Scope:
- Improve the projector result screen with stronger hierarchy and motion.
- Keep polling behavior unchanged.
- Make option bars and open-text answers feel like presentation surfaces rather than admin widgets.

Deliverables:
- `templates/live/results.php`
- `templates/layouts/projector.php`

Acceptance:
- Live results look readable from a distance.
- Option distribution and open-text answers update without changing API shape.

### WP-5 Localization, Accessibility, and QA

Scope:
- Add translation keys for every new user-visible string.
- Preserve keyboard and screen-reader usability.
- Verify syntax, locale coverage, and lint after each batch.

Deliverables:
- `locales/en.json`
- `locales/tr.json`
- PHPUnit / lint verification

Acceptance:
- Turkish locale coverage stays at or above 95%.
- `composer lint` passes.
- `php -l` passes on edited PHP files.

## 5) Execution Rules for agykit

1. Keep each work packet small and reviewable.
2. Do not change core flow logic while doing visual work.
3. Preserve existing route names, IDs, and JS hooks unless the change is explicitly about the UI shell.
4. Add locale keys before using any new visible text.
5. Validate after every packet with syntax and lint checks.

## 6) Progress Log

- 2026-06-02: Shared premium visual system completed.
- 2026-06-02: Student surfaces restyled.
- 2026-06-02: Admin surfaces restyled.
- 2026-06-02: Projector/live results surfaces restyled.
- 2026-06-02: Locale coverage and lint checks passed.

## 7) Current Status

This rollout is implemented. The document remains as the canonical record of what was changed and why.

