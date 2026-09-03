# ADR-0007: A Composition Root, Typed Domain Errors, and Controllers for Every Route

**Status:** Accepted
**Date:** 2026-09-03

## Context

ADR-0001 chose plain PHP over a framework. That decision stands. What it did not
decide is how objects get built, how a failure travels from a service to an HTTP
response, and where request handling ends and rendering begins. Without a
framework supplying answers, those answers have to be written down — and they were
not. Five years of small, individually reasonable choices produced the following
state, measured on 2026-09-03:

- **No composition root.** `new CourseService(new CourseRepository(), new UserRepository())`
  appears in ten places: six templates, three controllers, and one script. When
  `CourseService` gained its `UserRepository` parameter during the FR-97 work, all
  ten had to be edited by hand. Nothing but a passing test suite prevented one from
  being missed.

- **Domain failures are magic strings.** Services signal failure with
  `throw new \RuntimeException('course_not_found')`. `SYSTEM_ARCHITECTURE.md` §9 has
  always specified typed domain exceptions; the code never had them. The HTTP layer
  therefore compares message text to decide a status code.

- **Every controller re-implements the HTTP envelope.** Across seventeen API
  controllers, `json()` is defined thirteen times, `jsonBody()` seven times,
  `handleRuntimeException()` six times, and the error-code-to-status table five
  times. The copies have already diverged: `ReportController` returns a
  `[status, message]` tuple where the other four call a helper. Nothing keeps two
  endpoints from disagreeing about what `course_not_found` means.

- **HTML routes have no controller.** `src/Controllers/Admin/` and
  `src/Controllers/Public/` contain only `.gitkeep`. Eleven templates authenticate
  the request, construct services, query repositories, choose HTTP status codes,
  and handle domain exceptions before emitting any markup. The layer table in §2.1
  describes a separation that does not exist for half the surface area.

- **`ReportService` carries five responsibilities.** 1090 lines, 22 methods: live
  result aggregation, session report assembly, word-cloud construction, course
  analytics, LMS export, and score computation. Every one of its consumers loads
  all six.

Each of these is survivable alone. Together they mean the cost of a change scales
with the number of places a pattern was copied to, rather than with the size of the
change — which is the definition of an architecture that resists work.

## Decision

Four rules, and one decomposition.

1. **One composition root.** `EduQR\Container` is the only place allowed to
   construct a service or a repository. Every other site resolves collaborators
   from it. Adding a dependency changes one definition. (NFR-80)

2. **Typed domain errors.** A service throws `NotFoundException`,
   `ForbiddenException`, `ValidationException`, or `ConflictException`. Each
   carries the stable machine-readable code that `API_SPEC.md` §12 already
   publishes. The HTTP layer maps by type, never by message text. This implements
   §9 as it was always written, rather than changing it. (NFR-78)

3. **One HTTP envelope.** `ApiController` owns the success envelope, the error
   envelope, request-body decoding, and the exception-to-status mapping. Controllers
   inherit it and define no copy. (NFR-79)

4. **Controllers handle every route, HTML included.** `src/Controllers/Admin/` and
   `src/Controllers/Public/` gain real classes. A controller authenticates,
   resolves services, prepares a view model, and hands it to a template. A template
   receives data and renders it — nothing else. (NFR-81)

5. **`ReportService` splits by responsibility** into results, report, analytics,
   export, and scoring units, each behind its own interface. (NFR-82)

## Consequences

**Accepted costs.** This is a large refactor of working, shipped code, spread over
five tasks (T-1126 … T-1130) and as many commits as Law 5 requires. It changes no
behaviour: the API contract, the rendered HTML, and the acceptance criteria are all
unchanged, and the existing test suite is the safety net that proves it. Any test
that has to change to accommodate the refactor is a signal to stop and look, not a
test to edit.

The container is a hand-written map, not a reflection-based autowiring container.
Autowiring would be less code to write and more magic to debug on a host where
Xdebug may not be available; an explicit map is greppable, and grep is the tool that
survives shared hosting.

**What this does not do.** Three findings from the same review are deliberately left
open, because none of them is currently costing anything measurable:

- There is still no logging abstraction; `error_log()` is called directly, with no
  levels and no structured context.
- The router scans all routes linearly and depends on registration order, which four
  `// /new must come before /{id}` comments in `Bootstrap.php` document but do not
  enforce.
- `Content-Security-Policy` still carries `script-src 'unsafe-inline'`, because every
  template embeds inline `<script>`. Moving to nonces is real security work and
  deserves its own task rather than a corner of this one.

**A fourth finding, deferred with a requirement of its own.** Forty-four throw
sites across eight services still signal validation failure with
`\InvalidArgumentException` and a `field:reason` message string, which every API
controller translates through a private table. Folding them into
`ValidationException` was scoped into NFR-79 and taken back out, because it is
not a refactor: `QuestionBankService::copyToSession()` calls
`QuestionService::create()`, so one throw site is read by two controllers whose
tables disagree on four codes — `question_type:invalid` differs by `field`,
`correct_answer:required` and `correct_answer:too_long` differ by message,
and `stage:invalid` differs by status (422 against 400). A single exception
carrying a single status cannot produce both responses, so unifying them means
deciding which response is correct and publishing that decision. That is
NFR-83 / T-1131.


One construction site survived the composition root on purpose.
`ReportService::extractThemes()` still falls back to
`OpenTextThemeExtractionService::fromConfig()` when no extractor was injected,
which is a service building a service and so an NFR-80 violation in its own
right. Passing the extractor in from the container would make it eager for
every report request, including the ones that never touch an open-text
question. The split in NFR-82 gives theme extraction its own unit with its own
interface, at which point the collaborator becomes a constructor parameter and
the fallback disappears without the eagerness. Fixing it twice was not worth
one line, so it waits for T-1130.

A third finding is cosmetic until it is not. Eleven pages hand `render()` a
title they have already escaped, and all three layouts escape `$pageTitle`
again. Five are in the panel — `/admin/courses/{id}`,
`/admin/courses/{id}/analytics`, `/admin/sessions/{id}`,
`/admin/sessions/{id}/results` and `/admin/sessions/{id}/report` — and six are
student- or projector-facing: `/live/{short_code}`,
`/live/{short_code}/results`, `/join/{short_code}`, `/join/{short_code}/wait`,
`/play/{short_code}` and `/play/{short_code}/answered`. A course titled
`Ölçme & Değerlendirme` reaches the browser's title bar as
`Ölçme &amp;amp; Değerlendirme`. NFR-81 moved those
titles from the templates into the controllers unchanged and deliberately: the
double escape predates the move, and undoing it changes rendered bytes for
every title containing an ampersand or a quote. Correcting it route by route
would leave the codebase in a state where two neighbouring pages escape
differently, which is harder to reason about than the bug. So it is written as
NFR-84 / T-1132 and applied everywhere at once.

`/play/{short_code}/batch` is the exception that proves the rule: its title is
a bare `t('student.batch.title')` with no escape at all. That is safe today
because a locale string is not user input, but it means two neighbouring
student pages already escape differently — which is the state T-1132 exists to
end rather than to spread.

While cataloguing them, one more asymmetry turned up among the panel five:
`/admin/courses/{id}/analytics` ends its title with the course title rather
than with `t('app.name')`, so it is the only page in the panel whose title bar
does not identify the application. That is a product decision rather than a
defect, and T-1132 is where it gets made.

Recording them here means the next person to read this file does not have to
rediscover them to know they were seen.
