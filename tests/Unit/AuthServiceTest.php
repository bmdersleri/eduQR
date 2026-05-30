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

            public function create(string $e, string $h, string $n, string $r, string $l): int
            {
                return 999;
            }

            public function touchLastLogin(int $id): void
            {
                $this->touched[] = $id;
            }

            public function touchedIds(): array
            {
                return $this->touched;
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
}
