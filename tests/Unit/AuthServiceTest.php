<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit;

use EduQR\Contracts\LoginAttemptRepositoryInterface;
use EduQR\Contracts\UserRepositoryInterface;
use EduQR\Services\AuthService;
use PHPUnit\Framework\TestCase;

final class AuthServiceTest extends TestCase
{
    // ── Stubs ──────────────────────────────────────────────────────────────────

    private function makeUserRepo(array $store = []): UserRepositoryInterface
    {
        return new class ($store) implements UserRepositoryInterface {
            private array $store;
            private array $touched = [];
            private array $findByIdCalls = [];

            public function __construct(array $store)
            {
                $this->store = $store;
            }

            public function findByEmail(string $email): ?array
            {
                foreach ($this->store as $u) {
                    if ($u['email'] === mb_strtolower(trim($email))) {
                        return $u;
                    }
                }

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
                $this->touched[] = $id;
            }

            public function updatePassword(int $id, string $passwordHash): void
            {
            }

            public function touchedIds(): array
            {
                return $this->touched;
            }

            public function findByIdCalls(): array
            {
                return $this->findByIdCalls;
            }
        };
    }

    private function makeAttemptRepo(int $recentFailures = 0): LoginAttemptRepositoryInterface
    {
        return new class ($recentFailures) implements LoginAttemptRepositoryInterface {
            private int $failures;
            public array $recorded = [];

            public function __construct(int $failures)
            {
                $this->failures = $failures;
            }

            public function record(string $email, ?string $ipHash, bool $succeeded): void
            {
                $this->recorded[] = compact('email', 'succeeded');
            }

            public function countRecentFailures(string $email, int $windowSeconds): int
            {
                return $this->failures;
            }
        };
    }

    private function activeUser(string $email, string $password): array
    {
        return [
            'id' => 1,
            'email' => $email,
            'password_hash' => AuthService::hashPassword($password),
            'display_name' => 'Test Instructor',
            'role' => 'instructor',
            'preferred_language' => 'en',
            'is_active' => '1',
        ];
    }

    // ── Tests: successful login ────────────────────────────────────────────────

    public function testLoginSucceeds(): void
    {
        $user = $this->activeUser('instructor@example.com', 'CorrectPassw0rd!');
        $users = $this->makeUserRepo([$user]);
        $attempts = $this->makeAttemptRepo(0);

        $svc = new AuthService($users, $attempts);
        $result = $svc->login('instructor@example.com', 'CorrectPassw0rd!');

        $this->assertSame(1, (int) $result['id']);
        $this->assertSame('instructor@example.com', $result['email']);
        $this->assertSame(1, count($attempts->recorded));
        $this->assertTrue($attempts->recorded[0]['succeeded']);
    }

    public function testLoginNormalisesEmailToLowercase(): void
    {
        $user = $this->activeUser('instructor@example.com', 'CorrectPassw0rd!');
        $users = $this->makeUserRepo([$user]);
        $svc = new AuthService($users, $this->makeAttemptRepo(0));

        $result = $svc->login('INSTRUCTOR@EXAMPLE.COM', 'CorrectPassw0rd!');
        $this->assertSame('instructor@example.com', $result['email']);
    }

    // ── Tests: failed login ────────────────────────────────────────────────────

    public function testLoginFailsWithWrongPassword(): void
    {
        $user = $this->activeUser('instructor@example.com', 'CorrectPassw0rd!');
        $users = $this->makeUserRepo([$user]);
        $attempts = $this->makeAttemptRepo(0);

        $svc = new AuthService($users, $attempts);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid_credentials');
        $svc->login('instructor@example.com', 'WrongPassword!');
    }

    public function testLoginFailsForUnknownEmail(): void
    {
        $svc = new AuthService($this->makeUserRepo([]), $this->makeAttemptRepo(0));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid_credentials');
        $svc->login('nobody@example.com', 'AnyPassword1!');
    }

    public function testLoginFailsForInactiveUser(): void
    {
        $user = $this->activeUser('instructor@example.com', 'CorrectPassw0rd!');
        $user['is_active'] = '0';
        $svc = new AuthService($this->makeUserRepo([$user]), $this->makeAttemptRepo(0));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid_credentials');
        $svc->login('instructor@example.com', 'CorrectPassw0rd!');
    }

    public function testFailedLoginRecordsAttempt(): void
    {
        $attempts = $this->makeAttemptRepo(0);
        $svc = new AuthService($this->makeUserRepo([]), $attempts);

        try {
            $svc->login('nobody@example.com', 'AnyPassword1!');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertCount(1, $attempts->recorded);
        $this->assertFalse($attempts->recorded[0]['succeeded']);
    }

    // ── Tests: rate limiting ───────────────────────────────────────────────────

    public function testRateLimitBlocksAfterMaxFailures(): void
    {
        $user = $this->activeUser('instructor@example.com', 'CorrectPassw0rd!');
        $users = $this->makeUserRepo([$user]);
        // Simulate 5 prior failures already recorded
        $attempts = $this->makeAttemptRepo(5);
        $svc = new AuthService($users, $attempts);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('too_many_attempts');
        $svc->login('instructor@example.com', 'CorrectPassw0rd!');
    }

    public function testRateLimitAllowsLoginBelowThreshold(): void
    {
        $user = $this->activeUser('instructor@example.com', 'CorrectPassw0rd!');
        $users = $this->makeUserRepo([$user]);
        $attempts = $this->makeAttemptRepo(4); // one below the limit of 5
        $svc = new AuthService($users, $attempts);

        $result = $svc->login('instructor@example.com', 'CorrectPassw0rd!');
        $this->assertSame('instructor@example.com', $result['email']);
    }

    // ── Tests: hashPassword ────────────────────────────────────────────────────

    public function testHashPasswordProducesBcrypt(): void
    {
        $hash = AuthService::hashPassword('MySecureP@ss1');
        $this->assertStringStartsWith('$2y$12$', $hash);
        $this->assertTrue(password_verify('MySecureP@ss1', $hash));
    }

    public function testHashPasswordIsUnique(): void
    {
        $h1 = AuthService::hashPassword('SamePassword1!');
        $h2 = AuthService::hashPassword('SamePassword1!');
        $this->assertNotSame($h1, $h2, 'bcrypt salts must differ between hashes');
    }

    // ── Tests: reauthenticate (NFR-87) ─────────────────────────────────────────

    /** An active instructor (1), an inactive instructor (2) and an active admin (3). */
    private function reauthenticateUsers(): array
    {
        return [
            [
                'id' => 1,
                'email' => 'instructor1@example.com',
                'display_name' => 'Instructor One',
                'role' => 'instructor',
                'preferred_language' => 'en',
                'is_active' => '1',
            ],
            [
                'id' => 2,
                'email' => 'instructor2@example.com',
                'display_name' => 'Instructor Two',
                'role' => 'instructor',
                'preferred_language' => 'en',
                'is_active' => '0',
            ],
            [
                'id' => 3,
                'email' => 'admin3@example.com',
                'display_name' => 'Admin Three',
                'role' => 'admin',
                'preferred_language' => 'en',
                'is_active' => '1',
            ],
        ];
    }

    public function testReauthenticateReturnsCurrentRowForActiveUser(): void
    {
        $svc = new AuthService($this->makeUserRepo($this->reauthenticateUsers()), $this->makeAttemptRepo(0));

        $result = $svc->reauthenticate(['id' => 1, 'role' => 'instructor']);

        $this->assertSame([
            'id' => 1,
            'email' => 'instructor1@example.com',
            'role' => 'instructor',
            'display_name' => 'Instructor One',
        ], $result);
    }

    public function testReauthenticateReturnsNullWhenUserIsInactive(): void
    {
        $svc = new AuthService($this->makeUserRepo($this->reauthenticateUsers()), $this->makeAttemptRepo(0));

        $this->assertNull($svc->reauthenticate(['id' => 2]));
    }

    public function testReauthenticateReturnsNullWhenUserIsDeleted(): void
    {
        $svc = new AuthService($this->makeUserRepo($this->reauthenticateUsers()), $this->makeAttemptRepo(0));

        $this->assertNull($svc->reauthenticate(['id' => 999]));
    }

    public function testReauthenticateReturnsNullAndSkipsTheRepositoryWithoutAnId(): void
    {
        $users = $this->makeUserRepo($this->reauthenticateUsers());
        $svc = new AuthService($users, $this->makeAttemptRepo(0));

        $this->assertNull($svc->reauthenticate([]));
        $this->assertNull($svc->reauthenticate(['id' => 0]));
        $this->assertSame([], $users->findByIdCalls());
    }

    public function testReauthenticateRoleComesFromRowWhenPromotedSinceSignIn(): void
    {
        $svc = new AuthService($this->makeUserRepo($this->reauthenticateUsers()), $this->makeAttemptRepo(0));

        // Session claims say instructor; the row (id 3) is now admin.
        $result = $svc->reauthenticate(['id' => 3, 'role' => 'instructor']);

        $this->assertSame('admin', $result['role']);
    }

    public function testReauthenticateRoleComesFromRowWhenDemotedSinceSignIn(): void
    {
        $svc = new AuthService($this->makeUserRepo($this->reauthenticateUsers()), $this->makeAttemptRepo(0));

        // Session claims say admin; the row (id 1) is now instructor.
        $result = $svc->reauthenticate(['id' => 1, 'role' => 'admin']);

        $this->assertSame('instructor', $result['role']);
    }
}
