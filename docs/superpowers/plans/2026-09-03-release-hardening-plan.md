# Release Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the three gaps between what eduQR promises and what it does: unverified migrations, HTML answers to failed API requests, and an admin role that grants nothing.

**Architecture:** Three independent changes. (1) A shell script drives an ephemeral MySQL 8.4 container through the existing `bin/migrate.php` and diffs the resulting schema against `database/schema.sql`. (2) A new pure class decides response format and payload for an uncaught throwable; `Bootstrap`'s exception handler and a new shutdown handler both delegate to it. (3) `CourseRepository::roleFor()` and the two course-listing queries elevate a `users.role = 'admin'` caller to co-instructor level.

**Tech Stack:** PHP 8.5 (no framework), PDO (MySQL in production, in-memory SQLite in tests), PHPUnit 11, php-cs-fixer, Docker (mysql:8.4), Git Bash on Windows.

**Spec:** `docs/specs/2026-09-03-release-hardening-spec.md`

## Global Constraints

- PHP and Composer are not on PATH. Every PHP invocation is prefixed: `PATH=/c/tools/php85:$PATH`.
- Test command: `PATH=/c/tools/php85:$PATH php vendor/bin/phpunit --configuration tests/phpunit.xml`. Suite is green at 631 tests / 2833 assertions before this plan starts; it must be green after every task.
- Style: `PATH=/c/tools/php85:$PATH php vendor/bin/php-cs-fixer fix` must report no changes before a commit.
- Locale coverage: `PATH=/c/tools/php85:$PATH php bin/locale-check.php tr` must stay at 100%. Any new user-visible string needs a key in both `locales/en.json` and `locales/tr.json`.
- There is no test database. Unit tests use in-memory SQLite plus fakes, so any SQL added must also parse on SQLite (no `ENUM`, no `ON UPDATE CURRENT_TIMESTAMP` in test fixtures).
- `.serena/` at the repo root must stay untracked. Never `git add -A`; stage explicit paths.
- Commit subjects follow the repo's convention: `type(scope): sentence in lower case [REQ-ID]`, e.g. `feat(api): answer uncaught API failures with the shared envelope [NFR-85]`.
- Every task ends with a commit. Do not push; the user pushes.

---

### Task 1: Register the three requirements and three tasks

**Files:**
- Modify: `PRODUCT_REQUIREMENTS.md` (requirement tables — NFR rows end at NFR-84 near line 257, FR rows end at FR-98 near line 163)
- Modify: `TASKS.md:296-338` (Phase 11 code block, after the `T-1132` line)

**Interfaces:**
- Produces: requirement IDs `NFR-85`, `NFR-86`, `FR-99` and task IDs `T-1133`, `T-1134`, `T-1135` that every later commit message references.

- [ ] **Step 1: Add the FR row**

In `PRODUCT_REQUIREMENTS.md`, directly after the `FR-98` row, add:

```markdown
| FR-99 | MUST | A user with the `admin` role MUST have co-instructor-level access to every course without being listed on it: read the course, its sessions, questions, question bank, reports, analytics and exports, and create or edit sessions and questions. Owner-only operations — archive, restore, delete, and managing the instructor list (FR-97) — MUST remain with the row-level owner; the admin role MUST NOT grant them. An admin's course list MUST contain every course. |
```

- [ ] **Step 2: Add the two NFR rows**

Directly after the `NFR-84` row:

```markdown
| NFR-85 | MUST | An uncaught failure MUST be answered in the format its route family promises. On a path beginning `/api/v1/`, the global handler MUST emit the shared JSON envelope with the correct status and published code, and MUST NOT leak a stack trace, file path or class name in the response whatever `APP_DEBUG` is; a fatal error that bypasses the exception handler MUST be answered the same way rather than with an empty body. Other paths keep the HTML error page. Logging to `logs/app.log` is unchanged, trace included. |
| NFR-86 | MUST | The schema produced by `database/migrations/*.sql` MUST be reproducible against a real MySQL 8.4 server by one documented command that touches no long-lived database, exits non-zero on the first failing migration, and reports any divergence from `database/schema.sql`. Where the reference schema and the migrations disagree, the migrations are authoritative. |
```

- [ ] **Step 3: Add the three task rows**

In `TASKS.md`, inside the Phase 11 code block, after the `T-1132` line:

```text
[ ] T-1133  Verify migrations 0001-0019 against a real MySQL 8.4 in a throwaway container [NFR-86]
[ ] T-1134  Uncaught failures on /api/v1/ answer with the JSON envelope, fatals included    [NFR-85]
[ ] T-1135  Admin role grants co-instructor access to every course, never ownership         [FR-99]
```

- [ ] **Step 4: Commit**

```bash
git add PRODUCT_REQUIREMENTS.md TASKS.md docs/specs/2026-09-03-release-hardening-spec.md docs/superpowers/plans/2026-09-03-release-hardening-plan.md
git commit -m "docs(spec): register the release-hardening requirements and tasks [NFR-85, NFR-86, FR-99]"
```

---

### Task 2: Let the migration runner take an explicit env file

**Files:**
- Modify: `bin/migrate.php:20-24` (the `Config::load()` call)

**Interfaces:**
- Produces: `php bin/migrate.php [--env=<path>]`. With no flag the behaviour is byte-identical to today. With the flag, config is loaded from `<path>`; a missing path exits 1 with a message naming it.

- [ ] **Step 1: Replace the hard-coded env load**

In `bin/migrate.php`, replace the line `\EduQR\Config::load($projectRoot . '/.env');` with:

```php
// Optional --env=<path> so a verification run can point the runner at a
// throwaway database without touching the developer's .env [NFR-86].
$envPath = $projectRoot . '/.env';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--env=')) {
        $envPath = substr($arg, 6);
    }
}

if (! file_exists($envPath)) {
    fwrite(STDERR, "Config file not found: {$envPath}\n");
    exit(1);
}

\EduQR\Config::load($envPath);
```

- [ ] **Step 2: Prove the flag is read and the default is unchanged**

Run:

```bash
PATH=/c/tools/php85:$PATH php bin/migrate.php --env=/nonexistent/.env
```

Expected: prints `Config file not found: /nonexistent/.env`, exit status 1 (check with `echo $?`).

Then run `PATH=/c/tools/php85:$PATH php bin/migrate.php` with no flag. Expected: it reaches the database connection step and fails there with a PDO connection error (there is no local MySQL) — proving the default path is still `.env` and nothing before the connection changed.

- [ ] **Step 3: Style and commit**

```bash
PATH=/c/tools/php85:$PATH php vendor/bin/php-cs-fixer fix bin/migrate.php
git add bin/migrate.php
git commit -m "feat(db): let the migration runner take an explicit env file [NFR-86]"
```

---

### Task 3: Verify migrations against a real MySQL 8.4

**Files:**
- Create: `bin/verify-migrations.sh`
- Modify: `database/schema.sql` (only if the diff proves it drifted)
- Temporary: `schema-from-migrations.sql` at the repo root during Step 2-3 only; deleted in Step 3
- Modify: `README.md` (near the `php bin/migrate.php` instruction, README.md:16)

**Interfaces:**
- Produces: `bash bin/verify-migrations.sh` — exit 0 on success, non-zero with the failing migration named otherwise. Consumes `bin/migrate.php --env=<path>` from Task 2.

- [ ] **Step 1: Write the script**

Create `bin/verify-migrations.sh`:

```bash
#!/usr/bin/env bash
# Verify that database/migrations/*.sql apply cleanly to a real MySQL 8.4 and
# that the result still matches database/schema.sql. [NFR-86]
#
#   bash bin/verify-migrations.sh
#
# Starts a throwaway mysql:8.4 container on 127.0.0.1:3308 with no volume,
# runs the migration runner against it, dumps the schema, diffs it against the
# reference schema, and removes the container. The docker compose stack and its
# db-data volume are never touched.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP="${PHP_BIN:-php}"
CONTAINER="eduqr-migrate-check"
PORT=3308
DB_NAME="eduqr_migrate_check"
ROOT_PASS="$(head -c 18 /dev/urandom | base64 | tr -d '/+=')"
WORK="$(mktemp -d)"

cleanup() {
    docker rm -f "$CONTAINER" >/dev/null 2>&1 || true
    rm -rf "$WORK"
}
trap cleanup EXIT

if docker ps -a --format '{{.Names}}' | grep -qx "$CONTAINER"; then
    echo "Removing a leftover $CONTAINER container."
    docker rm -f "$CONTAINER" >/dev/null
fi

echo "Starting mysql:8.4 on 127.0.0.1:${PORT} ..."
docker run -d --rm \
    --name "$CONTAINER" \
    -e MYSQL_ROOT_PASSWORD="$ROOT_PASS" \
    -e MYSQL_DATABASE="$DB_NAME" \
    -p "127.0.0.1:${PORT}:3306" \
    mysql:8.4 \
    --character-set-server=utf8mb4 \
    --collation-server=utf8mb4_unicode_ci >/dev/null

echo -n "Waiting for the server "
for _ in $(seq 1 60); do
    if docker exec "$CONTAINER" mysqladmin ping -h 127.0.0.1 -u root -p"$ROOT_PASS" --silent >/dev/null 2>&1; then
        echo " ready."
        break
    fi
    echo -n "."
    sleep 2
done

if ! docker exec "$CONTAINER" mysqladmin ping -h 127.0.0.1 -u root -p"$ROOT_PASS" --silent >/dev/null 2>&1; then
    echo "MySQL did not become ready in 120s." >&2
    exit 1
fi

cat > "$WORK/.env" <<ENVFILE
APP_ENV=testing
APP_DEBUG=true
DB_HOST=127.0.0.1
DB_PORT=${PORT}
DB_NAME=${DB_NAME}
DB_USER=root
DB_PASS=${ROOT_PASS}
ENVFILE

echo "Applying migrations ..."
"$PHP" "$ROOT/bin/migrate.php" --env="$WORK/.env"

APPLIED="$(docker exec "$CONTAINER" mysql -u root -p"$ROOT_PASS" -N -B -e \
    "SELECT COUNT(*) FROM ${DB_NAME}.schema_migrations" 2>/dev/null)"
echo "Applied migrations recorded: ${APPLIED}"

normalize() {
    # Drop dump noise and values that differ per run: comments, AUTO_INCREMENT
    # counters, and blank lines. Sort nothing — table order is part of the diff.
    sed -e 's/ AUTO_INCREMENT=[0-9]*//' \
        -e '/^\/\*!/d' \
        -e '/^--/d' \
        -e '/^$/d' \
        -e 's/[[:space:]]*$//'
}

docker exec "$CONTAINER" mysqldump -u root -p"$ROOT_PASS" \
    --no-data --skip-comments --skip-set-charset --compact \
    --ignore-table="${DB_NAME}.schema_migrations" \
    "$DB_NAME" 2>/dev/null | normalize > "$WORK/from-migrations.sql"

echo "Schema dumped: $(wc -l < "$WORK/from-migrations.sql") lines."

if [ "${DUMP_ONLY:-0}" = "1" ]; then
    cp "$WORK/from-migrations.sql" "$ROOT/schema-from-migrations.sql"
    echo "DUMP_ONLY=1 — wrote schema-from-migrations.sql for inspection."
    exit 0
fi

if ! diff -u <(normalize < "$ROOT/database/schema.sql") "$WORK/from-migrations.sql"; then
    echo "database/schema.sql does not match what the migrations produce." >&2
    echo "The migrations are authoritative; correct the reference file." >&2
    exit 1
fi

echo "OK — migrations apply cleanly and schema.sql matches."
```

- [ ] **Step 2: Run it in dump-only mode to see the real schema**

Run:

```bash
PHP_BIN=/c/tools/php85/php.exe DUMP_ONLY=1 bash bin/verify-migrations.sh
```

Expected: the container starts, `Applied migrations recorded: 19`, a dump is written to `schema-from-migrations.sql`, exit 0, and `docker ps -a` afterwards lists no `eduqr-migrate-check`. If a migration fails, the runner prints the failing filename — fix the migration, not the script, and rerun.

- [ ] **Step 3: Reconcile the reference schema**

Compare `schema-from-migrations.sql` against `database/schema.sql`. The migrations are authoritative. Rewrite the parts of `database/schema.sql` that disagree — column types, ENUM members, indexes, foreign keys, table order — keeping the file's existing comment style and section headings. Then delete the scratch dump:

```bash
rm schema-from-migrations.sql
```

- [ ] **Step 4: Run the real check**

```bash
PHP_BIN=/c/tools/php85/php.exe bash bin/verify-migrations.sh
```

Expected: `OK — migrations apply cleanly and schema.sql matches.` and exit 0.

- [ ] **Step 5: Prove failure is detected**

Append a deliberate syntax error to a copy-safe spot — add the line `CREATE TABLE ;` to the end of `database/migrations/0017_session_exam_mode.sql`, rerun the script, and confirm it exits non-zero naming `0017_session_exam_mode.sql`. Then revert:

```bash
git checkout database/migrations/0017_session_exam_mode.sql
```

- [ ] **Step 6: Document the command**

In `README.md`, next to the existing `php bin/migrate.php` instruction, add:

```markdown
Verify that the migrations still produce the documented schema (needs Docker; uses a
throwaway MySQL 8.4 container on port 3308 and never touches your database):

```bash
bash bin/verify-migrations.sh
```
```

- [ ] **Step 7: Commit**

```bash
git add bin/verify-migrations.sh README.md database/schema.sql
git commit -m "test(db): verify the migrations against a real MySQL 8.4 [NFR-86]"
```

- [ ] **Step 8: Tick the task**

In `TASKS.md`, change `[ ] T-1133` to `[x] T-1133`. Commit with the same message convention: `docs(tasks): close T-1133 [NFR-86]`.

---

### Task 4: A pure decision for how an uncaught failure is answered

**Files:**
- Create: `src/Http/FailureResponse.php`
- Create: `tests/Unit/Http/FailureResponseTest.php`

**Interfaces:**
- Produces:
  - `EduQR\Http\FailureResponse::wantsJson(?string $requestUri): bool`
  - `EduQR\Http\FailureResponse::payloadFor(\Throwable $e): array` returning `['status' => int, 'body' => array{success: bool, error: array{code: string, message: string, field?: string}}]`
- Consumes: `EduQR\Controllers\Api\ApiController::domainEnvelope(DomainException $e): array` and `ApiController::messageFor(string $errorCode): string`, both already `public static`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Http/FailureResponseTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use EduQR\Exceptions\NotFoundException;
use EduQR\Http\FailureResponse;
use PHPUnit\Framework\TestCase;

final class FailureResponseTest extends TestCase
{
    public function test_api_paths_want_json_NFR85(): void
    {
        $this->assertTrue(FailureResponse::wantsJson('/api/v1/courses'));
        $this->assertTrue(FailureResponse::wantsJson('/api/v1/sessions/12?since=4'));
    }

    public function test_html_paths_do_not_want_json_NFR85(): void
    {
        $this->assertFalse(FailureResponse::wantsJson('/admin/courses'));
        $this->assertFalse(FailureResponse::wantsJson('/'));
        $this->assertFalse(FailureResponse::wantsJson('/join/ABC123'));
        $this->assertFalse(FailureResponse::wantsJson(null));
        $this->assertFalse(FailureResponse::wantsJson('/apiv1/courses'));
    }

    public function test_unexpected_throwable_becomes_a_500_server_error_NFR85(): void
    {
        $payload = FailureResponse::payloadFor(new \RuntimeException('connection refused at 10.0.0.4'));

        $this->assertSame(500, $payload['status']);
        $this->assertFalse($payload['body']['success']);
        $this->assertSame('server_error', $payload['body']['error']['code']);
    }

    public function test_the_response_never_carries_the_internal_message_NFR85(): void
    {
        $encoded = json_encode(
            FailureResponse::payloadFor(new \RuntimeException('connection refused at 10.0.0.4')),
            JSON_UNESCAPED_UNICODE
        );

        $this->assertStringNotContainsString('connection refused', (string) $encoded);
        $this->assertStringNotContainsString('10.0.0.4', (string) $encoded);
        $this->assertStringNotContainsString('RuntimeException', (string) $encoded);
    }

    public function test_a_domain_exception_keeps_its_status_and_code_NFR85(): void
    {
        $payload = FailureResponse::payloadFor(new NotFoundException('course_not_found'));

        $this->assertSame(404, $payload['status']);
        $this->assertSame('course_not_found', $payload['body']['error']['code']);
    }

    public function test_a_php_error_is_answered_like_any_other_failure_NFR85(): void
    {
        $payload = FailureResponse::payloadFor(new \Error('Call to a member function id() on null'));

        $this->assertSame(500, $payload['status']);
        $this->assertSame('server_error', $payload['body']['error']['code']);
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

```bash
PATH=/c/tools/php85:$PATH php vendor/bin/phpunit --configuration tests/phpunit.xml --filter FailureResponseTest
```

Expected: FAIL — `Class "EduQR\Http\FailureResponse" not found`.

- [ ] **Step 3: Write the class**

Create `src/Http/FailureResponse.php`:

```php
<?php

declare(strict_types=1);

namespace EduQR\Http;

use EduQR\Controllers\Api\ApiController;
use EduQR\Exceptions\DomainException;

/**
 * Decides how a failure that escaped every controller is answered. [NFR-85]
 *
 * The global handlers in Bootstrap own the side effects — logging, status line,
 * headers, echo. Everything decidable is decided here, so it can be tested
 * without a request.
 */
final class FailureResponse
{
    private const API_PREFIX = '/api/v1/';

    /** True when the caller asked for an API route and therefore expects the envelope. */
    public static function wantsJson(?string $requestUri): bool
    {
        if ($requestUri === null || $requestUri === '') {
            return false;
        }

        $path = parse_url($requestUri, PHP_URL_PATH);

        return is_string($path) && str_starts_with($path, self::API_PREFIX);
    }

    /**
     * The envelope for a throwable. A DomainException keeps its published status
     * and code; anything else is a 500 with no detail — the detail belongs in
     * logs/app.log, never in the response.
     *
     * @return array{status:int, body:array<string,mixed>}
     */
    public static function payloadFor(\Throwable $e): array
    {
        if ($e instanceof DomainException) {
            return ApiController::domainEnvelope($e);
        }

        return [
            'status' => 500,
            'body' => [
                'success' => false,
                'error' => [
                    'code' => 'server_error',
                    'message' => self::message('server_error'),
                ],
            ],
        ];
    }

    /**
     * Translations may not be loaded yet — the handler can fire before
     * I18nMiddleware::resolve(). Fall back to the bare code rather than fataling
     * inside the failure path.
     */
    private static function message(string $errorCode): string
    {
        try {
            return ApiController::messageFor($errorCode);
        } catch (\Throwable) {
            return $errorCode;
        }
    }
}
```

- [ ] **Step 4: Run the test and watch it pass**

```bash
PATH=/c/tools/php85:$PATH php vendor/bin/phpunit --configuration tests/phpunit.xml --filter FailureResponseTest
```

Expected: PASS, 6 tests. If `messageFor()` throws because no locale is loaded, the fallback covers it; if the test still fails, load a locale in `setUp()` the way `tests/Unit/Controllers/ApiControllerTest.php` does.

- [ ] **Step 5: Commit**

```bash
PATH=/c/tools/php85:$PATH php vendor/bin/php-cs-fixer fix src/Http/FailureResponse.php tests/Unit/Http/FailureResponseTest.php
git add src/Http/FailureResponse.php tests/Unit/Http/FailureResponseTest.php
git commit -m "feat(api): decide the uncaught-failure response in one testable place [NFR-85]"
```

---

### Task 5: Wire the global handlers to the envelope

**Files:**
- Modify: `src/Bootstrap.php:79-118` (`registerErrorHandlers()`)
- Modify: `tests/Unit/Http/FailureResponseTest.php` (add the shutdown-classification test)

**Interfaces:**
- Consumes: `FailureResponse::wantsJson()`, `FailureResponse::payloadFor()` from Task 4.
- Produces: `FailureResponse::isFatal(?array $lastError): bool` — true for `E_ERROR`, `E_PARSE`, `E_CORE_ERROR`, `E_COMPILE_ERROR`, `E_USER_ERROR`.

- [ ] **Step 1: Write the failing test for fatal classification**

Append to `tests/Unit/Http/FailureResponseTest.php`:

```php
    public function test_fatal_error_types_are_recognised_NFR85(): void
    {
        $this->assertTrue(FailureResponse::isFatal(['type' => E_ERROR, 'message' => 'x', 'file' => 'f', 'line' => 1]));
        $this->assertTrue(FailureResponse::isFatal(['type' => E_PARSE, 'message' => 'x', 'file' => 'f', 'line' => 1]));
        $this->assertFalse(FailureResponse::isFatal(['type' => E_WARNING, 'message' => 'x', 'file' => 'f', 'line' => 1]));
        $this->assertFalse(FailureResponse::isFatal(null));
    }
```

Run the filter command from Task 4 Step 2. Expected: FAIL — `Call to undefined method ... isFatal()`.

- [ ] **Step 2: Add the method**

In `src/Http/FailureResponse.php`, after `wantsJson()`:

```php
    private const FATAL_TYPES = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

    /**
     * True when error_get_last() describes a failure that killed the request
     * before the exception handler could run.
     *
     * @param array{type:int,message:string,file:string,line:int}|null $lastError
     */
    public static function isFatal(?array $lastError): bool
    {
        return $lastError !== null && in_array($lastError['type'], self::FATAL_TYPES, true);
    }
```

Rerun the filter. Expected: PASS, 7 tests.

- [ ] **Step 3: Send the envelope from the exception handler**

In `src/Bootstrap.php`, inside `registerErrorHandlers()`, replace the body of the `set_exception_handler` closure that follows the `error_log(...)` call — that is, everything from `if (! headers_sent()) {` to the end of the closure — with:

```php
            if (headers_sent()) {
                return;
            }

            if (FailureResponse::wantsJson($_SERVER['REQUEST_URI'] ?? null)) {
                $payload = FailureResponse::payloadFor($e);
                http_response_code($payload['status']);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode($payload['body'], JSON_UNESCAPED_UNICODE);

                return;
            }

            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');

            if ($debug) {
                echo '<pre>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</pre>';
            } else {
                include __DIR__ . '/../templates/errors/500.php';
            }
```

Add `use EduQR\Http\FailureResponse;` to the file's imports.

Note the behaviour change in the HTML branch: when headers are already sent the handler now returns instead of echoing a partial page into a half-rendered response. That is deliberate — a JSON envelope appended to sent HTML would corrupt both.

- [ ] **Step 4: Answer fatals too**

Still inside `registerErrorHandlers()`, after the `set_exception_handler(...)` call, add:

```php
        // A fatal (E_ERROR, memory exhaustion, parse error) never reaches the
        // exception handler, and PHP's own output is an empty body for an API
        // client. Answer it in the caller's format. [NFR-85]
        register_shutdown_function(function () use ($logPath): void {
            $last = error_get_last();

            if (! FailureResponse::isFatal($last)) {
                return;
            }

            $msg = sprintf(
                "[eduQR][fatal] %s in %s:%d",
                $last['message'],
                $last['file'],
                $last['line']
            );
            error_log($msg, 3, rtrim($logPath, '/') . '/app.log');

            if (headers_sent()) {
                return;
            }

            if (FailureResponse::wantsJson($_SERVER['REQUEST_URI'] ?? null)) {
                $payload = FailureResponse::payloadFor(new \RuntimeException('fatal'));
                http_response_code($payload['status']);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode($payload['body'], JSON_UNESCAPED_UNICODE);

                return;
            }

            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
            include __DIR__ . '/../templates/errors/500.php';
        });
```

- [ ] **Step 5: Prove it end to end with the built-in server**

The handlers only run for a real request, so drive PHP's built-in server. To be certain the *global* handler answers — rather than a controller's own `try`/`catch` — insert a probe throw first.

1. Add `throw new \RuntimeException('probe');` as the first statement inside `Bootstrap::registerRoutes()`.
2. Start the server: `PATH=/c/tools/php85:$PATH php -S 127.0.0.1:8099 -t public public/index.php &`
3. Run both requests:

```bash
curl -i -s http://127.0.0.1:8099/api/v1/courses | head -20
curl -i -s http://127.0.0.1:8099/admin/courses | head -20
```

Expected: the first shows `HTTP/1.1 500`, `Content-Type: application/json; charset=utf-8`, body `{"success":false,"error":{"code":"server_error",...}}`, and contains none of `probe`, `RuntimeException`, `Bootstrap.php`. The second shows `Content-Type: text/html`. `tail -3 logs/app.log` shows a `[eduQR][exception]` line carrying `probe` and the trace.

4. Stop the server (`kill %1`), remove the probe, and confirm `git diff src/Bootstrap.php` shows only the intended handler changes.

- [ ] **Step 6: Full suite and commit**

```bash
PATH=/c/tools/php85:$PATH php vendor/bin/phpunit --configuration tests/phpunit.xml
PATH=/c/tools/php85:$PATH php vendor/bin/php-cs-fixer fix
git add src/Bootstrap.php src/Http/FailureResponse.php tests/Unit/Http/FailureResponseTest.php
git commit -m "fix(api): answer uncaught API failures with the shared envelope [NFR-85]"
```

Expected: 638 tests or more, all passing.

- [ ] **Step 7: Record the decision and tick the task**

In `docs/adr/0007-composition-root-and-typed-errors.md`, extend the consequences section with two sentences: the global handler now emits the envelope for `/api/v1/` paths through `FailureResponse`, and the response never carries a trace even when `APP_DEBUG=true` while the log always does. In `API_SPEC.md`, in the error-handling section, state that a failure outside a controller returns the same envelope shape with code `server_error`. Tick `[x] T-1134` in `TASKS.md`.

```bash
git add docs/adr/0007-composition-root-and-typed-errors.md API_SPEC.md TASKS.md
git commit -m "docs(api): publish the uncaught-failure envelope and close T-1134 [NFR-85]"
```

---

### Task 6: An admin resolves to co-instructor on every course

**Files:**
- Modify: `src/Repositories/CourseRepository.php:143-152` (`roleFor()`)
- Modify: `src/Contracts/CourseRepositoryInterface.php:36-41` (the `roleFor()` docblock)
- Create: `tests/Unit/Repositories/CourseRepositoryAdminAccessTest.php`

**Interfaces:**
- Produces: `CourseRepository::roleFor()` returns `'owner'` or `'co_instructor'` from `course_instructors` as before; when there is no row and `users.role = 'admin'`, it returns `'co_instructor'`; otherwise `null`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Repositories/CourseRepositoryAdminAccessTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use EduQR\Repositories\CourseRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * FR-99: the admin role reaches every course at co-instructor level.
 * SQLite stands in for MySQL — the queries under test are portable.
 */
final class CourseRepositoryAdminAccessTest extends TestCase
{
    private PDO $pdo;
    private CourseRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->pdo->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL,
            display_name TEXT NOT NULL,
            role TEXT NOT NULL
        )');
        $this->pdo->exec('CREATE TABLE courses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            instructor_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT \'active\',
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )');
        $this->pdo->exec('CREATE TABLE course_instructors (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            course_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            role TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            UNIQUE (course_id, user_id)
        )');

        // 1 = owner instructor, 2 = unrelated instructor, 3 = admin
        $this->pdo->exec("INSERT INTO users (id, email, display_name, role) VALUES
            (1, 'owner@example.edu', 'Owner', 'instructor'),
            (2, 'other@example.edu', 'Other', 'instructor'),
            (3, 'admin@example.edu', 'Admin', 'admin')");
        $this->pdo->exec("INSERT INTO courses (id, instructor_id, title) VALUES (10, 1, 'Fizik 101')");
        $this->pdo->exec("INSERT INTO course_instructors (course_id, user_id, role) VALUES (10, 1, 'owner')");

        $this->repo = new CourseRepository($this->pdo);
    }

    public function test_admin_reaches_a_course_they_are_not_listed_on_FR99(): void
    {
        $this->assertSame('co_instructor', $this->repo->roleFor(10, 3));
    }

    public function test_an_unrelated_instructor_still_has_no_role_FR99(): void
    {
        $this->assertNull($this->repo->roleFor(10, 2));
    }

    public function test_a_listed_owner_keeps_owner_FR99(): void
    {
        $this->assertSame('owner', $this->repo->roleFor(10, 1));
    }

    public function test_an_admin_who_owns_a_course_is_still_its_owner_FR99(): void
    {
        $this->pdo->exec("INSERT INTO courses (id, instructor_id, title) VALUES (11, 3, 'Kimya 101')");
        $this->pdo->exec("INSERT INTO course_instructors (course_id, user_id, role) VALUES (11, 3, 'owner')");

        $this->assertSame('owner', $this->repo->roleFor(11, 3));
    }

    public function test_an_unknown_user_has_no_role_FR99(): void
    {
        $this->assertNull($this->repo->roleFor(10, 999));
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

```bash
PATH=/c/tools/php85:$PATH php vendor/bin/phpunit --configuration tests/phpunit.xml --filter CourseRepositoryAdminAccessTest
```

Expected: FAIL on `test_admin_reaches_a_course_they_are_not_listed_on_FR99` — `Failed asserting that null is identical to 'co_instructor'`. The other four pass.

- [ ] **Step 3: Elevate in the query**

In `src/Repositories/CourseRepository.php`, replace `roleFor()` with:

```php
    public function roleFor(int $courseId, int $userId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT role FROM course_instructors WHERE course_id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$courseId, $userId]);
        $role = $stmt->fetchColumn();

        if ($role !== false) {
            return (string) $role;
        }

        // An admin reaches every course at co-instructor level, and never as its
        // owner — archiving and the instructor list stay with the owner. [FR-99]
        return $this->isAdmin($userId) ? 'co_instructor' : null;
    }

    private function isAdmin(int $userId): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM users WHERE id = ? AND role = 'admin' LIMIT 1");
        $stmt->execute([$userId]);

        return $stmt->fetchColumn() !== false;
    }
```

- [ ] **Step 4: Run the test and watch it pass**

Rerun the filter from Step 2. Expected: PASS, 5 tests.

- [ ] **Step 5: Update the interface contract**

In `src/Contracts/CourseRepositoryInterface.php`, extend the `roleFor()` docblock:

```php
    /**
     * The user's role on the course: 'owner', 'co_instructor', or null when the
     * user has no access. This is the single authorization primitive; every
     * course-derived permission check in the application goes through it.
     *
     * A user whose users.role is 'admin' resolves to 'co_instructor' on every
     * course they are not listed on, so an admin can read and author everywhere
     * but never passes an owner-only check (FR-99).
     */
```

- [ ] **Step 6: Commit**

```bash
PATH=/c/tools/php85:$PATH php vendor/bin/phpunit --configuration tests/phpunit.xml
PATH=/c/tools/php85:$PATH php vendor/bin/php-cs-fixer fix
git add src/Repositories/CourseRepository.php src/Contracts/CourseRepositoryInterface.php tests/Unit/Repositories/CourseRepositoryAdminAccessTest.php
git commit -m "feat(auth): let an admin reach every course as a co-instructor [FR-99]"
```

---

### Task 7: An admin's course list contains every course

**Files:**
- Modify: `src/Repositories/CourseRepository.php:30-57` (`listByInstructor()`, `countByInstructor()`)
- Modify: `tests/Unit/Repositories/CourseRepositoryAdminAccessTest.php`

**Interfaces:**
- Consumes: the `isAdmin()` helper from Task 6.
- Produces: `listByInstructor()` / `countByInstructor()` return every non-deleted course for an admin, and are unchanged for an instructor.

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Repositories/CourseRepositoryAdminAccessTest.php`:

```php
    public function test_an_admin_lists_courses_they_are_not_listed_on_FR99(): void
    {
        $this->pdo->exec("INSERT INTO courses (id, instructor_id, title) VALUES (12, 2, 'Biyoloji 101')");
        $this->pdo->exec("INSERT INTO course_instructors (course_id, user_id, role) VALUES (12, 2, 'owner')");

        $titles = array_column($this->repo->listByInstructor(3, 1, 20), 'title');

        sort($titles);
        $this->assertSame(['Biyoloji 101', 'Fizik 101'], $titles);
        $this->assertSame(2, $this->repo->countByInstructor(3));
    }

    public function test_an_instructor_still_lists_only_their_own_FR99(): void
    {
        $this->pdo->exec("INSERT INTO courses (id, instructor_id, title) VALUES (12, 2, 'Biyoloji 101')");
        $this->pdo->exec("INSERT INTO course_instructors (course_id, user_id, role) VALUES (12, 2, 'owner')");

        $this->assertSame(['Fizik 101'], array_column($this->repo->listByInstructor(1, 1, 20), 'title'));
        $this->assertSame(1, $this->repo->countByInstructor(1));
    }
```

Run the filter. Expected: FAIL — the admin's list is empty and the count is 0.

- [ ] **Step 2: Branch both queries on the admin role**

In `src/Repositories/CourseRepository.php`:

```php
    /** Owned or co-instructed courses (FR-97), newest first. An admin sees all (FR-99). */
    public function listByInstructor(int $instructorId, int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;

        $sql = $this->isAdmin($instructorId)
            ? 'SELECT c.* FROM courses c ORDER BY c.created_at DESC LIMIT ? OFFSET ?'
            : 'SELECT c.*
                 FROM courses c
                 JOIN course_instructors ci ON ci.course_id = c.id
                WHERE ci.user_id = ?
                ORDER BY c.created_at DESC
                LIMIT ? OFFSET ?';

        $params = $this->isAdmin($instructorId)
            ? [$perPage, $offset]
            : [$instructorId, $perPage, $offset];

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countByInstructor(int $instructorId): int
    {
        if ($this->isAdmin($instructorId)) {
            return (int) $this->pdo->query('SELECT COUNT(*) FROM courses')->fetchColumn();
        }

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
               FROM courses c
               JOIN course_instructors ci ON ci.course_id = c.id
              WHERE ci.user_id = ?'
        );
        $stmt->execute([$instructorId]);

        return (int) $stmt->fetchColumn();
    }
```

Call `isAdmin()` once per method and reuse the result rather than calling it twice; assign `$isAdmin = $this->isAdmin($instructorId);` at the top of `listByInstructor()`.

- [ ] **Step 3: Run the test and watch it pass**

Rerun the filter. Expected: PASS, 7 tests.

- [ ] **Step 4: Full suite and commit**

```bash
PATH=/c/tools/php85:$PATH php vendor/bin/phpunit --configuration tests/phpunit.xml
PATH=/c/tools/php85:$PATH php vendor/bin/php-cs-fixer fix
git add src/Repositories/CourseRepository.php tests/Unit/Repositories/CourseRepositoryAdminAccessTest.php
git commit -m "feat(auth): show every course in an admin's course list [FR-99]"
```

---

### Task 8: Pin the limits of admin access and publish them

**Files:**
- Modify: `tests/Unit/CourseServiceTest.php` (append tests; the file's fake repository is defined around lines 40-130)
- Modify: `SECURITY_PRIVACY.md` (§6)
- Modify: `DATA_MODEL.md` (§2.3, the `course_instructors` section)
- Modify: `TASKS.md`

**Interfaces:**
- Consumes: `CourseService::requireOwner()` (`src/Services/CourseService.php:99`) and the existing fake `CourseRepositoryInterface` in `CourseServiceTest`, whose `roleFor()` returns whatever the test seeds.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/CourseServiceTest.php`, following the file's existing arrangement helpers. An admin arrives at the service already elevated to `co_instructor` by the repository (Task 6), so the service sees exactly a co-instructor. These tests use the file's existing helpers — `makeService($repo, $users = null)`, `repoWithCoInstructor($courseId = 1, $status = 'active')` and `makeUsers([...])` — where user `20` is the co-instructor; here it stands for an elevated admin. Add them after `testCoInstructorCanListInstructors_FR97` (around line 435):

```php
    // ── FR-99: elevation reaches the service as a co-instructor, never as owner ─

    public function testElevatedAdminCanReadAnyCourse_FR99(): void
    {
        // 20 arrives as a co-instructor because roleFor() elevated the admin.
        $this->assertSame(
            'Test Course',
            $this->makeService($this->repoWithCoInstructor())->getCourse(1, 20)['title']
        );
    }

    public function testElevatedAdminCannotArchiveCourse_FR99(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('course_owner_only');
        $this->makeService($this->repoWithCoInstructor())->archiveCourse(1, 20);
    }

    public function testElevatedAdminCannotManageInstructors_FR99(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('course_owner_only');
        $service = $this->makeService(
            $this->repoWithCoInstructor(),
            $this->makeUsers(['new@example.org' => 30])
        );
        $service->addInstructor(1, 20, ['email' => 'new@example.org']);
    }
```

- [ ] **Step 2: Run and confirm they pass or fail honestly**

```bash
PATH=/c/tools/php85:$PATH php vendor/bin/phpunit --configuration tests/phpunit.xml --filter CourseServiceTest
```

These tests may pass immediately — Task 6 made the elevation return `co_instructor` precisely so that `requireOwner()` needs no new branch. That is the point: they are regression guards proving an admin never crosses the owner line. If any of them fails, the elevation is granting too much and the fix belongs in `CourseRepository::roleFor()`, not in the test.

- [ ] **Step 3: Rewrite SECURITY_PRIVACY.md §6**

Replace the two access-control bullets in §6 with:

```markdown
- Instructors access **only** the courses they own or co-instruct, and only the sessions
  under those courses (`FR-14`, `FR-97`). Access is resolved in one place —
  `CourseRepository::roleFor()` — and every service asks it.
- A user with the `admin` role reaches **every** course at co-instructor level (`FR-99`):
  they can read the course, its sessions, questions, question bank, reports, analytics and
  exports, and can author sessions and questions. Admin does **not** confer ownership:
  archiving, restoring, deleting a course and managing its instructor list stay with the
  row-level owner in `course_instructors`, and an admin attempting them is refused with
  `403 forbidden`.
- Admin elevation is **not** audited per read. Live pages poll (`NFR-76`), so one audit row
  per elevated read would bury the audit log (`FR-91`); writes are audited exactly as any
  instructor's writes are.
```

Delete the claim that admins "manage user accounts" — there is no user-account management
UI. If the sentence is load-bearing elsewhere in the document, replace it with: "User
accounts are managed directly in the database by the system administrator."

- [ ] **Step 4: Note the elevation in DATA_MODEL.md**

In §2.3, under `course_instructors`, add one line: absence of a row means no access, except
for a user whose `users.role` is `admin`, who is treated as a co-instructor of every course
(FR-99).

- [ ] **Step 5: Full suite, locale check, commit**

```bash
PATH=/c/tools/php85:$PATH php vendor/bin/phpunit --configuration tests/phpunit.xml
PATH=/c/tools/php85:$PATH php bin/locale-check.php tr
PATH=/c/tools/php85:$PATH php vendor/bin/php-cs-fixer fix
git add tests/Unit/CourseServiceTest.php SECURITY_PRIVACY.md DATA_MODEL.md
git commit -m "docs(security): publish what the admin role does and does not grant [FR-99]"
```

- [ ] **Step 6: Tick the task**

In `TASKS.md`, change `[ ] T-1135` to `[x] T-1135`.

```bash
git add TASKS.md
git commit -m "docs(tasks): close T-1135 [FR-99]"
```

---

### Task 9: Final verification

**Files:** none modified unless a check fails.

- [ ] **Step 1: Run everything**

```bash
PATH=/c/tools/php85:$PATH php vendor/bin/phpunit --configuration tests/phpunit.xml
PATH=/c/tools/php85:$PATH php vendor/bin/php-cs-fixer fix --dry-run --diff
PATH=/c/tools/php85:$PATH php bin/locale-check.php tr
PHP_BIN=/c/tools/php85/php.exe bash bin/verify-migrations.sh
```

Expected: suite green (≥ 645 tests), php-cs-fixer reports no diff, locale check 100%, migration check OK.

- [ ] **Step 2: Confirm the tree is clean and nothing stray is staged**

```bash
git status --short
docker ps -a --format '{{.Names}}' | grep eduqr-migrate-check || echo "no leftover container"
```

Expected: only `?? .serena/` untracked, no `schema-from-migrations.sql`, no leftover container.

- [ ] **Step 3: Report**

Summarise for the user: what shipped, the suite count before and after, the migration
verification result, and the two items that remain open and deferred (T-1108, T-1109) plus
the Turkish review queue.
