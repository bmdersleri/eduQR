<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit\Services;

use EduQR\Contracts\LoginAttemptRepositoryInterface;
use EduQR\Contracts\UserRepositoryInterface;
use EduQR\Services\AuthService;
use PHPUnit\Framework\TestCase;

/**
 * Covers {@see AuthService::authenticatedUser()} (NFR-87): the wrapper that
 * re-checks a session's claims against the `users` row on every request and
 * destroys the session outright when the account can no longer be used.
 */
final class AuthSessionRevalidationTest extends TestCase
{
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

    // ── Stubs ──────────────────────────────────────────────────────────────────

    private function makeUserRepo(array $store): UserRepositoryInterface
    {
        return new class ($store) implements UserRepositoryInterface {
            private array $store;
            private array $findByIdCalls = [];

            public function __construct(array $store)
            {
                $this->store = $store;
            }

            public function findByEmail(string $email): ?array
            {
                return null;
            }

            public function findById(int $id): ?array
            {
                $this->findByIdCalls[] = $id;

                foreach ($this->store as $u) {
                    if ((int) $u['id'] === $id) {
                        return $u;
                    }
                }

                return null;
            }

            public function create(string $e, string $h, string $n, string $r, string $l): int
            {
                return 999;
            }

            public function touchLastLogin(int $id): void
            {
            }

            public function updatePassword(int $id, string $passwordHash): void
            {
            }

            public function findByIdCalls(): array
            {
                return $this->findByIdCalls;
            }
        };
    }

    private function makeAttemptRepo(): LoginAttemptRepositoryInterface
    {
        return new class () implements LoginAttemptRepositoryInterface {
            public function record(string $email, ?string $ipHash, bool $succeeded): void
            {
            }

            public function countRecentFailures(string $email, int $windowSeconds): int
            {
                return 0;
            }
        };
    }

    // ── Tests ──────────────────────────────────────────────────────────────────

    public function testAuthenticatedUserReturnsRowAndRefreshesStaleRoleInSession(): void
    {
        $users = $this->makeUserRepo([
            [
                'id' => 1,
                'email' => 'instructor1@example.com',
                'display_name' => 'Instructor One',
                'role' => 'admin',
                'preferred_language' => 'en',
                'is_active' => '1',
            ],
        ]);
        $svc = new AuthService($users, $this->makeAttemptRepo());

        // Session was written at sign-in time, when the account was still an
        // instructor; the row now says admin (promoted since sign-in).
        $_SESSION['user_id'] = 1;
        $_SESSION['user_email'] = 'instructor1@example.com';
        $_SESSION['user_role'] = 'instructor';
        $_SESSION['user_name'] = 'Instructor One';

        $result = $svc->authenticatedUser();

        $this->assertSame([
            'id' => 1,
            'email' => 'instructor1@example.com',
            'role' => 'admin',
            'display_name' => 'Instructor One',
        ], $result);
        $this->assertSame('admin', $_SESSION['user_role']);
    }

    public function testAuthenticatedUserDestroysSessionForInactiveUser(): void
    {
        $users = $this->makeUserRepo([
            [
                'id' => 2,
                'email' => 'instructor2@example.com',
                'display_name' => 'Instructor Two',
                'role' => 'instructor',
                'preferred_language' => 'en',
                'is_active' => '0',
            ],
        ]);
        $svc = new AuthService($users, $this->makeAttemptRepo());

        $_SESSION['user_id'] = 2;
        $_SESSION['user_email'] = 'instructor2@example.com';
        $_SESSION['user_role'] = 'instructor';
        $_SESSION['user_name'] = 'Instructor Two';

        $result = $svc->authenticatedUser();

        $this->assertNull($result);
        $this->assertSame([], $_SESSION);
    }

    public function testAuthenticatedUserDestroysSessionForDeletedUser(): void
    {
        $svc = new AuthService($this->makeUserRepo([]), $this->makeAttemptRepo());

        $_SESSION['user_id'] = 999;
        $_SESSION['user_email'] = 'ghost@example.com';
        $_SESSION['user_role'] = 'instructor';
        $_SESSION['user_name'] = 'Ghost';

        $result = $svc->authenticatedUser();

        $this->assertNull($result);
        $this->assertSame([], $_SESSION);
    }

    public function testAuthenticatedUserReturnsNullWithoutQueryingWhenNoSessionUser(): void
    {
        $users = $this->makeUserRepo([]);
        $svc = new AuthService($users, $this->makeAttemptRepo());

        $result = $svc->authenticatedUser();

        $this->assertNull($result);
        $this->assertSame([], $users->findByIdCalls());
    }
}
