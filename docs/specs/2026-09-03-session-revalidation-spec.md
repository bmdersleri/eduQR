# Session Revalidation Spec — a deactivated account loses access immediately

**Date:** 2026-09-03
**Status:** approved for implementation
**Requirements introduced:** NFR-87
**Tasks:** T-1136 (NFR-87)

## 1. Why now

`users.is_active` is read in exactly one place on the sign-in path:
`AuthService::login()` refuses credentials when the flag is `0` (DATA_MODEL.md §2.1:
"`is_active = 0` disables login without deleting the row"). Nothing reads it afterwards.

`AuthService::startSession()` copies `id`, `email`, `role` and `display_name` into
`$_SESSION`, and `AuthService::currentUser()` — the single source of identity for
`AuthMiddleware::require()` and `AuthMiddleware::requireRole()`, and therefore for every
authenticated API route and every admin HTML page — returns those copies without ever
looking at the `users` row again. Consequences today:

1. Deactivating an account does not end the session it already has. Someone signed in when
   `is_active` flips to `0` keeps full access until they log out or the session cookie
   expires, which for `lifetime = 0` means until they close the browser. The one control
   the data model offers for withdrawing access does not withdraw access.
2. Deleting the `users` row leaves the same session working: `currentUser()` never asks
   whether the row exists.
3. A role change does not take effect. Demoting an admin to `instructor` leaves the
   admin-only routes (`AuthMiddleware::requireRole('admin')`) open for the rest of that
   session; promoting has the mirror-image problem.
4. FR-99 widened the reach of the bug rather than causing it. `CourseRepository::isAdmin()`
   now grants co-instructor access to every course on `users.role = 'admin'`, and that
   query does not filter on `is_active`, so a deactivated admin row still elevates.

This is the security half of the three gaps found in the release-hardening review
(T-1136, T-1137, T-1138) and the most valuable of the three.

## 2. Requirement

### NFR-87 — an authenticated session never outlives the account's authorization state

Every authenticated request MUST resolve the caller's identity, role and active flag from
the caller's `users` row, not from values copied into the session at sign-in. A request
whose user row is missing, or whose `is_active` is `0`, MUST be answered exactly as an
unauthenticated request is — `401 not_authenticated` for a JSON caller, `302` to `/login`
for an HTML caller — and the session MUST be destroyed so the next request starts clean. A
change to `users.role` MUST take effect on the caller's next request, in both directions.
The `admin` elevation of FR-99 MUST NOT be granted to an inactive account. No response body
may reveal which of the three conditions ended the session.

## 3. Scope

**In scope**

- `UserRepositoryInterface` / `UserRepository` gain a `findById()` lookup.
- `AuthService` gains a revalidation step: session claims in, fresh user row or `null` out,
  with the session destroyed on `null` and the session copies refreshed on success.
- `AuthMiddleware::require()` — the one chokepoint both API controllers and
  `HtmlController::requireUser()` pass through — uses it.
- `CourseRepository::isAdmin()` filters on `is_active = 1` as defence in depth, so the
  FR-99 elevation cannot outlive deactivation even if a future caller bypasses the
  middleware.
- Docs: `PRODUCT_REQUIREMENTS.md` (NFR-87), `SECURITY_PRIVACY.md`, `DATA_MODEL.md` §2.1,
  `TASKS.md`.

**Out of scope — deliberately**

- Admin user-management endpoints. `API_SPEC.md` §6 documents
  `GET|POST /api/v1/admin/users` and `PATCH /api/v1/admin/users/{id}` (which sets
  `is_active`), and none of the three exists in the code. That is a separate doc/code gap of
  the same family as T-1137 and gets its own task; deactivation today happens through SQL or
  a CLI script, and this change makes either take effect immediately.
- Idle-timeout or absolute session lifetime. Unrelated to the account's state.
- Any change to how `login()` treats `is_active`.

## 4. Design decisions

**Revalidate on every authenticated request, with no throttle.** The alternative — a
`$_SESSION['revalidated_at']` stamp and a configurable interval — buys one primary-key
lookup per request and costs a window in which a deactivated account still works. The
lookup is a single indexed `SELECT` on a table with one row per instructor, against a
connection the request has already opened; nothing in the request path is cheaper than the
queries it already runs. Student polling (NFR-76), the only high-frequency path, is
unauthenticated and is not touched at all. Revisit only if a measurement says otherwise.

**Keep the failure indistinguishable from "not signed in".** Missing row, inactive row and
no session all produce the same 401 / redirect. A distinct "account disabled" message would
tell an attacker holding a stolen cookie that the account exists.

**Refresh the session copies rather than only reading through them.** After a successful
revalidation the session's `user_role`, `user_email` and `user_name` are overwritten with
the row's values, so anything still reading `AuthService::currentUser()` directly — the
logout audit entry, for one — sees current data.

**Split the decision from the session plumbing.** The rule ("row missing or inactive ⇒ no
access") lives in a pure method that takes session claims and returns a user row or `null`;
the `$_SESSION` reading, the session destruction and the copy refresh sit in a thin wrapper
around it. The rule is then unit-testable without starting a PHP session.

## 5. Acceptance criteria

1. An authenticated JSON request whose user row has `is_active = 0` is answered
   `401 not_authenticated` with the shared envelope, and the session is empty afterwards.
2. The same request from an HTML caller is answered `302 Location: /login`.
3. An authenticated request whose user row no longer exists is answered the same way.
4. A session created while the row said `role = 'admin'` no longer passes
   `AuthMiddleware::requireRole('admin')` once the row says `instructor`, and the reverse
   holds without a re-login.
5. `CourseRepository::roleFor()` returns `null`, not `co_instructor`, for an admin whose
   row is inactive.
6. An active user's request behaves exactly as it does today, with the returned array
   carrying the row's current `email`, `role` and `display_name`.
7. The suite is green (baseline 649 tests / 2871 assertions, plus the new tests),
   php-cs-fixer reports no changes, and `bin/locale-check.php tr` stays at 100%.
