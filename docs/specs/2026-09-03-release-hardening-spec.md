# Release Hardening Spec — schema provenance, uncaught-failure envelope, admin access

**Date:** 2026-09-03
**Status:** approved for implementation
**Requirements introduced:** NFR-85, NFR-86, FR-99
**Tasks:** T-1133 (NFR-86), T-1134 (NFR-85), T-1135 (FR-99)

## 1. Why now

Every functional task in `TASKS.md` phases 0–11 is shipped except the two deliberately
deferred locale tasks (T-1108, T-1109). What remains are three gaps between what the
project *claims* and what the code *does*. Each one is small, bounded, and blocks either a
deploy or a written promise:

1. Migrations 0001–0019 have never been executed against a real MySQL server. The unit
   suite runs on in-memory SQLite with hand-written table fragments, so a syntax error or a
   broken foreign key in a migration would only surface on the production host. Migration
   0019 creates `course_instructors`, which is now the single authorization primitive
   (`CourseRepositoryInterface::roleFor()`); if it has not run, every course request 403s.
2. `Bootstrap::registerErrorHandlers()` answers every uncaught throwable with HTML
   (`templates/errors/500.php`, or a `<pre>` trace when `APP_DEBUG=true`). An API client
   that triggers a failure outside a controller's `try` block — in middleware, the router,
   or the container — receives HTML where NFR-79 promises a JSON envelope.
3. `SECURITY_PRIVACY.md` §6 states "Admins may access all courses and manage user
   accounts." No admin elevation exists anywhere in the service or repository layer: an
   admin is authorized exactly like any other instructor, so an admin cannot open a course
   they do not co-instruct.

## 2. Requirements

### NFR-86 — schema provenance is verifiable on demand

The schema that `database/migrations/*.sql` produces MUST be reproducible against a real
MySQL 8.4 server by a single documented command, without touching the developer's
long-lived database. The command MUST fail with a non-zero exit status if any migration
errors, and MUST report the difference if the resulting schema diverges from the checked-in
reference schema (`database/schema.sql`). `database/schema.sql` MUST describe the same
tables and columns the migrations produce; where the two disagree, the migrations are
authoritative and the reference file is corrected.

### NFR-85 — an uncaught failure answers in the caller's format

A failure that reaches the global error handler MUST be answered in the format the route
family promises. For a request whose path begins with `/api/v1/`, the response MUST be the
shared envelope — `{"success": false, "error": {"code": ..., "message": ...}}` with
`Content-Type: application/json; charset=utf-8` — and MUST NOT contain a stack trace, a
file path, or a class name regardless of `APP_DEBUG`. For any other path the current HTML
behaviour is unchanged. A `DomainException` that reaches the handler MUST keep its status
and published code; anything else MUST be answered `500 server_error`. Fatal errors that
bypass the exception handler (memory exhaustion, `E_ERROR`) MUST be answered the same way
rather than with an empty body. Every failure MUST still be logged to `logs/app.log`
exactly as it is today, with the full trace — the trace is removed from the *response*, not
from the log.

### FR-99 — an admin may access every course

A user whose `users.role` is `admin` MUST have access to every course without being listed
in `course_instructors`, at the same level as a co-instructor: read the course, its
sessions, questions, question bank, reports, analytics, reactions and exports, and create
or edit sessions and questions inside it. Owner-only operations — archive, restore, delete,
and managing the course's instructor list (FR-97) — MUST remain restricted to the row-level
`owner`; the admin role does NOT grant them. An admin's course list MUST show every course,
not only the ones they are listed on. `SECURITY_PRIVACY.md` §6 MUST be rewritten to state
exactly this, including that admin does not confer ownership, and to drop or qualify any
claim the code does not implement.

## 3. Design decisions

**D1 — verification runs in a throwaway container, driven from the host.**
`docker` 29.7.2 and Compose v5.4.0 are available, and the host PHP 8.5 has `pdo_mysql`. The
check starts an ephemeral `mysql:8.4` container on `127.0.0.1:3308` with its own random
root password and no named volume, runs the existing `bin/migrate.php` against it, dumps
the schema with `docker exec ... mysqldump --no-data`, normalizes it, diffs it against a
normalized `database/schema.sql`, and removes the container. `docker compose` and its
`db-data` volume are left alone, so a developer's local data is never at risk.

**D2 — `bin/migrate.php` gains `--env=<path>`.**
The runner currently hard-codes `Config::load($projectRoot . '/.env')`, and `.env` values
win over `getenv()`, so environment variables alone cannot redirect it. A single optional
flag lets the verification script point the runner at a generated env file. Default
behaviour with no flag is unchanged.

**D3 — the error handler branches on the request path, and reuses the existing envelope.**
`ApiController::domainEnvelope(DomainException $e): array` is already `public static` and
returns `['status' => int, 'body' => array]`. The handler calls it for a `DomainException`
and falls back to `['success' => false, 'error' => ['code' => 'server_error', 'message' =>
...]]` otherwise. The API predicate is `str_starts_with($path, '/api/v1/')` computed from
`parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)`, matching how routes are registered in
`Bootstrap::registerRoutes()`. Message lookup goes through `ApiController::messageFor()`
when translations are loaded and falls back to the bare error code when they are not — the
handler can fire before `I18nMiddleware::resolve()`.

**D4 — admin elevation lives in `CourseRepository`, not in a new policy service.**
`CourseRepositoryInterface::roleFor()` documents itself as "the single authorization
primitive; every course-derived permission check in the application goes through it", and
ten services already delegate to it. Elevating there gives every service the rule with one
change and no constructor churn; injecting a new `AccessPolicy` into ten services would
touch ~20 call sites and every service test's construction. `roleFor()` returns
`'co_instructor'` for an admin, which by D5 is exactly the access level FR-99 grants, so
`requireOwner()` keeps rejecting admins from owner-only operations with no extra branch.
The extra `users` lookup only runs when the `course_instructors` lookup misses.

**D5 — admin is co-instructor-equivalent, never owner.**
Least privilege: "may access all courses" is satisfied by read plus authoring access.
Archiving, restoring, deleting a course or rewriting its instructor list are the owner's
acts, and an admin performing them silently would be indistinguishable from the owner in
the audit log.

**D6 — no audit-log entry per elevated access.**
Live pages poll (NFR-76); one audit row per elevated read would flood `audit_logs` and make
the FR-91 viewer useless. Admin elevation is documented in `SECURITY_PRIVACY.md` instead.
Admin *writes* continue to be audited by whatever already audits that write.

## 4. Acceptance criteria

**NFR-86**
- `bin/verify-migrations.sh` exits 0 on a clean tree and prints the applied migration count.
- Introducing a deliberate syntax error into any migration makes it exit non-zero and name
  the failing file. (Verified once during implementation, then reverted.)
- The script leaves no container, no volume, and no file outside `.gitignore`d paths behind,
  including when it fails midway.
- `database/schema.sql` matches the migration-produced schema after normalization.
- `README.md` documents the command next to `php bin/migrate.php`.

**NFR-85**
- A request to `/api/v1/anything` whose handling throws an uncaught `\RuntimeException`
  produces status 500, `Content-Type: application/json; charset=utf-8`, and body
  `{"success":false,"error":{"code":"server_error","message":"..."}}`.
- The same request with `APP_DEBUG=true` produces the identical body — no trace, no file
  path, no class name.
- An uncaught `NotFoundException('course_not_found')` on an `/api/v1/` path produces status
  404 and code `course_not_found`.
- A request to `/admin/courses` that throws still produces HTML, with the debug trace when
  `APP_DEBUG=true`.
- Both cases append a line beginning `[eduQR][exception]` with the trace to `logs/app.log`.
- A fatal error on an `/api/v1/` path produces the JSON envelope, not an empty body.
- Tests cover the format decision and the payload builder as pure functions; they do not
  require a live web server.

**FR-99**
- `roleFor(courseId, adminUserId)` returns `'co_instructor'` for a course the admin is not
  listed on, and still returns `'owner'` where an admin genuinely owns a course.
- An admin can read a course, its sessions, questions and reports that they do not
  co-instruct; a non-admin instructor in the same position still gets 403.
- An admin calling archive, restore, delete, add-instructor or remove-instructor on a course
  they do not own still gets 403 with code `forbidden`.
- An admin's course list contains courses they are not listed on; an instructor's does not.
- `SECURITY_PRIVACY.md` §6 states the elevation, its limits, and that it is not audited
  per-read.
- The rule is proved at repository level against SQLite and at service level through the
  existing mocks.

## 5. Out of scope

- Locale files `de.json`, `fr.json`, RTL and `ar.json` (T-1108, T-1109 — deferred,
  Turkish-first).
- The Turkish native-speaker review of the 68 keys in `docs/tr-review-queue.md`.
- A structured logging abstraction. `error_log()` to `logs/app.log` stays.
- Admin user-account management UI. §6's claim is corrected to match the code, not
  implemented.
- Running migrations against the production host. NFR-86 delivers the tool and the local
  proof; the deploy remains a human act.

## 6. Risks

- **Windows shell.** `bin/verify-migrations.sh` targets Git Bash and Linux CI. It must not
  depend on a host `mysql` client; every MySQL call goes through `docker exec`.
- **Port 3308 in use.** The script must fail with a clear message rather than silently
  migrating some other server. It checks the port before starting.
- **`schema.sql` drift.** The first diff may be large because the reference file was written
  by hand. The correction lands in the same task, and the normalized diff — not the raw one
  — is what must reach zero.
- **Elevation reach.** Making `roleFor()` return `'co_instructor'` for an admin instantly
  changes ten services. The acceptance test list above pins both the granted and the still
  forbidden paths so the blast radius is proved rather than assumed.
