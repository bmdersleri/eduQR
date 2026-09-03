# Session Revalidation Implementation Plan (T-1136 / NFR-87)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A deactivated, deleted or demoted account loses access on its next request instead of keeping it until the browser closes.

**Architecture:** One lookup is added to the user repository (`findById`). `AuthService` gains a pure decision method (`reauthenticate()`: session claims in, fresh user row or `null` out) and a thin session wrapper (`authenticatedUser()`) that destroys the session on `null` and refreshes the session copies on success. `AuthMiddleware::require()` — the single chokepoint for every API controller and for `HtmlController::requireUser()` — calls the wrapper instead of the session-only `AuthService::currentUser()`. Independently, `CourseRepository::isAdmin()` filters on `is_active = 1` so the FR-99 admin elevation cannot outlive deactivation.

**Tech Stack:** PHP 8.5 (no framework), PDO (MySQL in production, in-memory SQLite in tests), PHPUnit 11, php-cs-fixer.

**Spec:** `docs/specs/2026-09-03-session-revalidation-spec.md`

## Global Constraints

- PHP and Composer are not on PATH. Every PHP invocation is prefixed: `PATH=/c/tools/php85:$PATH`.
- Test command: `PATH=/c/tools/php85:$PATH php vendor/bin/phpunit --configuration tests/phpunit.xml`. The suite is green at 649 tests / 2871 assertions before this plan starts; it must be green after every task.
- Style: `PATH=/c/tools/php85:$PATH php vendor/bin/php-cs-fixer fix --allow-risky=yes` must report no changes before a commit.
- Locale coverage: `PATH=/c/tools/php85:$PATH php bin/locale-check.php tr` must stay at 100%. This change adds no user-visible string — it reuses `error.not_authenticated` — so no locale key should be needed. If one is, add it to both `locales/en.json` and `locales/tr.json`.
- There is no test database. Unit tests use in-memory SQLite plus fakes, so any SQL added must also parse on SQLite (no `ENUM`, no `ON UPDATE CURRENT_TIMESTAMP` in fixtures).
- TDD: every behaviour step writes the failing test first, watches it fail for the stated reason, then makes it pass.
- `.serena/` at the repo root must stay untracked. Never `git add -A`; stage explicit paths.
- Commit subjects follow the repo's convention: `type(scope): sentence in lower case [REQ-ID]`, e.g. `feat(auth): revalidate the session against the users row [NFR-87]`.
- Every task ends with a commit. Do not push; the user pushes.

---

### Task 1: Register NFR-87 and the spec

**Files:**
- Modify: `PRODUCT_REQUIREMENTS.md` (NFR table — the `NFR-86` row is the last one, near line 260)
- Modify: `TASKS.md:337` (the `T-1136` line, to carry the new requirement ID)

**Interfaces:**
- Produces: requirement ID `NFR-87`, referenced by every later commit message in this plan.

- [ ] **Step 1: Add the NFR row**

In `PRODUCT_REQUIREMENTS.md`, directly after the `NFR-86` row:

```markdown
| NFR-87 | MUST | An authenticated session MUST NOT outlive the account's authorization state. Every authenticated request MUST resolve the caller's identity, role and active flag from the caller's `users` row rather than from values copied into the session at sign-in. A request whose user row is missing or whose `is_active` is `0` MUST be answered exactly as an unauthenticated request is, and the session MUST be destroyed; a change to `users.role` MUST take effect on the next request, in both directions. The `admin` elevation of FR-99 MUST NOT be granted to an inactive account. The response MUST NOT reveal which condition ended the session. |
```

- [ ] **Step 2: Point T-1136 at it**

In `TASKS.md`, replace the `T-1136` line with:

```text
[ ] T-1136  Deactivating a user does not end their live session; is_active is checked only at login [NFR-87]
```

(The `[x]` comes in the last task, once the behaviour is in.)

- [ ] **Step 3: Commit**

```bash
git add PRODUCT_REQUIREMENTS.md TASKS.md docs/specs/2026-09-03-session-revalidation-spec.md docs/superpowers/plans/2026-09-03-session-revalidation-plan.md
git commit -m "docs(spec): register the session revalidation requirement [NFR-87]"
```

---

### Task 2: `findById()` on the user repository

**Files:**
- Modify: `src/Contracts/UserRepositoryInterface.php` (add the method)
- Modify: `src/Repositories/UserRepository.php` (implement it, next to `findByEmail()`)
- Modify: `tests/Unit/AuthServiceTest.php:18`, `tests/Unit/CourseServiceTest.php:159`, `tests/Unit/Services/PasswordResetServiceTest.php:23` — the three anonymous classes that implement the interface and will otherwise fatal
- Create: `tests/Unit/Repositories/UserRepositoryTest.php`

**Interfaces:**
- Produces: `UserRepositoryInterface::findById(int $id): ?array` returning `id, email, display_name, role, preferred_language, is_active` or `null`.

- [ ] **Step 1: Write the failing repository test**

Create `tests/Unit/Repositories/UserRepositoryTest.php`, modelled on `tests/Unit/Repositories/CourseRepositoryAdminAccessTest.php` (in-memory SQLite, `PDO::ERRMODE_EXCEPTION`, `FETCH_ASSOC`). Create a `users` table with `id, email, password_hash, display_name, role, preferred_language, is_active`, insert an active instructor and an inactive one, then assert:

- `findById()` returns the row for an existing id, with `email`, `role` and `display_name` on it.
- `findById()` on an inactive user still returns the row, and its `is_active` is falsy — the repository reports state, it does not filter. (The filtering decision is `AuthService`'s.)
- `findById()` returns `null` for an unknown id.

Run the file. It must fail with "Call to undefined method".

- [ ] **Step 2: Add the method to the interface**

In `src/Contracts/UserRepositoryInterface.php`, directly after `findByEmail()`:

```php
    /** The row behind a live session, `is_active` included (NFR-87). */
    public function findById(int $id): ?array;
```

- [ ] **Step 3: Implement it**

In `src/Repositories/UserRepository.php`, directly after `findByEmail()`, mirroring its shape:

```php
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, email, display_name, role,
                    preferred_language, is_active
               FROM users
              WHERE id = ?
              LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }
```

`password_hash` is deliberately absent: nothing on the revalidation path needs it.

- [ ] **Step 4: Teach the three test doubles the new method**

Each anonymous class implementing `UserRepositoryInterface` needs a `findById()`. Give the doubles in `AuthServiceTest` and `CourseServiceTest` a real lookup over their existing `$store` / `$usersByEmail` array (match on `(int) $u['id']`, return `null` when nothing matches) rather than a stub returning `null` — Task 3 uses the `AuthServiceTest` one. `PasswordResetServiceTest`'s double holds a single optional user; returning that user when the id matches and `null` otherwise is enough.

- [ ] **Step 5: Run the suite and commit**

```bash
PATH=/c/tools/php85:$PATH php vendor/bin/phpunit --configuration tests/phpunit.xml
PATH=/c/tools/php85:$PATH php vendor/bin/php-cs-fixer fix --allow-risky=yes
git add src/Contracts/UserRepositoryInterface.php src/Repositories/UserRepository.php tests/Unit/Repositories/UserRepositoryTest.php tests/Unit/AuthServiceTest.php tests/Unit/CourseServiceTest.php tests/Unit/Services/PasswordResetServiceTest.php
git commit -m "feat(auth): look a user up by id for session revalidation [NFR-87]"
```

---

### Task 3: The revalidation rule, as a pure method

**Files:**
- Modify: `src/Services/AuthService.php` (new instance method, after `login()` and before the session block)
- Modify: `tests/Unit/AuthServiceTest.php`

**Interfaces:**
- Produces: `AuthService::reauthenticate(array $claims): ?array` — claims in (`['id' => int, ...]`), the current row as `['id','email','role','display_name']` out, or `null` when access has ended.

- [ ] **Step 1: Write the failing tests**

In `tests/Unit/AuthServiceTest.php`, add a group of tests for `reauthenticate()`, using the existing `makeUserRepo()` double seeded with an active instructor (id 1), an inactive instructor (id 2) and an active admin (id 3):

1. Active user: returns an array whose `id`, `email`, `role` and `display_name` come from the row.
2. `is_active = 0`: returns `null`.
3. Unknown id (row deleted): returns `null`.
4. Claims with no `id`, or `id = 0`: returns `null`, and the repository is never queried.
5. Role changed since sign-in: claims say `instructor`, the row says `admin`; the returned `role` is `admin`. Assert the row wins in both directions (add the mirror case with a demoted admin).

They must fail with "Call to undefined method", not with an assertion failure.

- [ ] **Step 2: Implement it**

In `src/Services/AuthService.php`:

```php
    /**
     * Re-resolves session claims against the `users` row (NFR-87).
     *
     * The session's copy of role and display name is from sign-in time; the row
     * is the truth. Returns null when the account can no longer be used — the
     * row is gone or `is_active` is 0 — and the caller answers that exactly as
     * it answers "not signed in", so a stolen cookie learns nothing.
     *
     * @param array<string, mixed> $claims as stored by {@see self::startSession()}
     *
     * @return array{id:int,email:string,role:string,display_name:string}|null
     */
    public function reauthenticate(array $claims): ?array
    {
        $id = (int) ($claims['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        $user = $this->users->findById($id);
        if ($user === null || ! (bool) ($user['is_active'] ?? 0)) {
            return null;
        }

        return [
            'id' => (int) $user['id'],
            'email' => (string) ($user['email'] ?? ''),
            'role' => (string) ($user['role'] ?? ''),
            'display_name' => (string) ($user['display_name'] ?? ''),
        ];
    }
```

Note the `?? 0` default on `is_active`: unlike `CourseService::addInstructor()`, which defaults a missing flag to *active*, a missing flag here means "cannot confirm the account is active" and access ends.

- [ ] **Step 3: Run the suite and commit**

```bash
PATH=/c/tools/php85:$PATH php vendor/bin/phpunit --configuration tests/phpunit.xml
PATH=/c/tools/php85:$PATH php vendor/bin/php-cs-fixer fix --allow-risky=yes
git add src/Services/AuthService.php tests/Unit/AuthServiceTest.php
git commit -m "feat(auth): resolve session claims against the users row [NFR-87]"
```

---

### Task 4: Wire it into the request path

**Files:**
- Modify: `src/Services/AuthService.php` (the `authenticatedUser()` wrapper, next to `currentUser()`)
- Modify: `src/Middleware/AuthMiddleware.php:22-29` (`require()`)
- Create: `tests/Unit/Services/AuthSessionRevalidationTest.php`

**Interfaces:**
- Produces: `AuthService::authenticatedUser(): ?array`. `AuthMiddleware::require()` returns a row-backed user or answers 401 / redirects, unchanged in shape.
- Consumes: `Container::authService()`, `AuthService::reauthenticate()`.

- [ ] **Step 1: Write the failing wrapper tests**

Create `tests/Unit/Services/AuthSessionRevalidationTest.php`. The wrapper touches `$_SESSION`, so control the session explicitly:

```php
    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }
```

Starting the session in `setUp()` matters: `AuthService::ensureSessionStarted()` returns early when the status is already `PHP_SESSION_ACTIVE`, so the values the test writes into `$_SESSION` survive. Assert:

1. Session holds an active user's id: `authenticatedUser()` returns the row's values, and `$_SESSION['user_role']` is refreshed to the row's role when the two disagree.
2. Session holds an inactive user's id: returns `null` **and** `$_SESSION` is empty afterwards.
3. Session holds a deleted user's id: returns `null`, `$_SESSION` empty.
4. No `user_id` in the session: returns `null` without querying the repository.

If `session_start()` under the CLI SAPI turns out to be unusable in this suite (a fatal, or cross-test pollution that breaks unrelated tests), fall back to `#[RunInSeparateProcess]` on the class. If that is still unworkable, report it rather than deleting the coverage: cases 1–3 must remain observable somewhere.

- [ ] **Step 2: Implement the wrapper**

In `src/Services/AuthService.php`, after `currentUser()`:

```php
    /**
     * The signed-in user, confirmed against the database (NFR-87).
     *
     * {@see self::currentUser()} reports what the session was told at sign-in;
     * this reports what is true now. A session whose account has been
     * deactivated or deleted is destroyed here, so the caller sees exactly what
     * it sees for a request that never signed in.
     *
     * @return array{id:int,email:string,role:string,display_name:string}|null
     */
    public function authenticatedUser(): ?array
    {
        $claims = self::currentUser();
        if ($claims === null) {
            return null;
        }

        $user = $this->reauthenticate($claims);
        if ($user === null) {
            $this->destroySession();

            return null;
        }

        // Keep the session's copies in step with the row, for the few readers
        // that still go through currentUser() (the logout audit entry, chrome).
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_name'] = $user['display_name'];

        return $user;
    }
```

- [ ] **Step 3: Point the middleware at it**

In `src/Middleware/AuthMiddleware.php`, `require()` becomes:

```php
    public static function require(): array
    {
        // Every authenticated request confirms the account against its row, so
        // deactivating or deleting a user ends the session it already has and a
        // role change takes effect at once [NFR-87].
        $user = Container::authService()->authenticatedUser();
        if ($user === null) {
            self::unauthorized();
        }

        return $user;
    }
```

Add `use EduQR\Container;`. `AuthService` may then be an unused import — remove it if so; leave `requireRole()` alone, it reads the role off whatever `require()` returned, which is now the row's role.

- [ ] **Step 4: Check nothing else reads identity around the middleware**

```bash
grep -rn "AuthService::currentUser" --include=*.php src/ public/ templates/
```

The expected remaining callers are `AuthController::logout()` (captures the session before destroying it — correct as is) and `AuthService::authenticatedUser()` itself. If a *route guard* other than `AuthMiddleware` turns up, it needs the same treatment; report it if the fix is not a one-liner.

- [ ] **Step 5: Run the suite and commit**

```bash
PATH=/c/tools/php85:$PATH php vendor/bin/phpunit --configuration tests/phpunit.xml
PATH=/c/tools/php85:$PATH php vendor/bin/php-cs-fixer fix --allow-risky=yes
git add src/Services/AuthService.php src/Middleware/AuthMiddleware.php tests/Unit/Services/AuthSessionRevalidationTest.php
git commit -m "feat(auth): end a deactivated user's live session on the next request [NFR-87]"
```

---

### Task 5: An inactive admin elevates to nothing

**Files:**
- Modify: `src/Repositories/CourseRepository.php:174-180` (`isAdmin()`)
- Modify: `tests/Unit/Repositories/CourseRepositoryAdminAccessTest.php` (the SQLite `users` fixture has no `is_active` column yet)

**Interfaces:**
- Consumes: `CourseRepository::roleFor()`, unchanged in signature.

- [ ] **Step 1: Write the failing test**

In `CourseRepositoryAdminAccessTest`, add `is_active INTEGER NOT NULL DEFAULT 1` to the `users` fixture, insert an admin with `is_active = 0`, and assert `roleFor($courseId, $inactiveAdminId)` is `null`. Keep an active-admin case asserting `co_instructor`, and the owner case asserting `owner`. The new test must fail with `co_instructor` returned.

- [ ] **Step 2: Filter the query**

```php
    private function isAdmin(int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM users WHERE id = ? AND role = 'admin' AND is_active = 1 LIMIT 1"
        );
        $stmt->execute([$userId]);

        return $stmt->fetchColumn() !== false;
    }
```

Defence in depth: `AuthMiddleware` already stops an inactive admin at the door (Task 4), but `roleFor()` is the authorization primitive and must not depend on who called it.

- [ ] **Step 3: Run the suite and commit**

```bash
PATH=/c/tools/php85:$PATH php vendor/bin/phpunit --configuration tests/phpunit.xml
PATH=/c/tools/php85:$PATH php vendor/bin/php-cs-fixer fix --allow-risky=yes
git add src/Repositories/CourseRepository.php tests/Unit/Repositories/CourseRepositoryAdminAccessTest.php
git commit -m "fix(authz): never elevate an inactive admin to co-instructor [NFR-87, FR-99]"
```

---

### Task 6: Say so in the docs, close the task

**Files:**
- Modify: `SECURITY_PRIVACY.md` (the authentication / session section)
- Modify: `DATA_MODEL.md:205` (the `is_active` rule under §2.1)
- Modify: `src/Contracts/CourseRepositoryInterface.php:36-45` (the `roleFor()` docblock names the FR-99 admin rule; it should say the admin must be active)
- Modify: `TASKS.md:337`

**Interfaces:**
- Produces: nothing executable. The written promise now matches the code.

- [ ] **Step 1: `DATA_MODEL.md`**

Replace the `is_active = 0` bullet with:

```markdown
- `is_active = 0` disables login without deleting the row, and ends any session the account
  already has: every authenticated request re-reads this flag (NFR-87).
```

- [ ] **Step 2: `SECURITY_PRIVACY.md`**

Find the section covering authentication and sessions (grep for `session_regenerate_id`, `HttpOnly` or `bcrypt`) and add one paragraph, in that section's voice: an authenticated request resolves identity, role and `is_active` from the `users` row on every request; deactivating, deleting or demoting an account takes effect on the account's next request without waiting for logout or cookie expiry; the response is the ordinary "not signed in" answer, so it does not confirm that the account exists. Reference NFR-87.

- [ ] **Step 3: The `roleFor()` docblock**

In `src/Contracts/CourseRepositoryInterface.php`, the FR-99 paragraph should read that a user whose `users.role` is `admin` **and whose account is active** resolves to `co_instructor`.

- [ ] **Step 4: Close T-1136**

```text
[x] T-1136  Deactivating a user does not end their live session; is_active is checked only at login [NFR-87]
```

- [ ] **Step 5: Full verification, then commit**

```bash
PATH=/c/tools/php85:$PATH php vendor/bin/phpunit --configuration tests/phpunit.xml
PATH=/c/tools/php85:$PATH php vendor/bin/php-cs-fixer fix --allow-risky=yes --dry-run --diff
PATH=/c/tools/php85:$PATH php bin/locale-check.php tr
git add SECURITY_PRIVACY.md DATA_MODEL.md src/Contracts/CourseRepositoryInterface.php TASKS.md
git commit -m "docs: a session ends when the account is deactivated [NFR-87]"
```

Report the final test count and assertion count, and the diff of `git log --oneline` for this plan's commits.
