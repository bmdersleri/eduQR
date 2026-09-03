<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit\Services;

use EduQR\Contracts\PasswordResetRepositoryInterface;
use EduQR\Contracts\UserRepositoryInterface;
use EduQR\Exceptions\ValidationException;
use EduQR\I18n\I18nService;
use EduQR\Services\PasswordResetService;
use PHPUnit\Framework\TestCase;

final class PasswordResetServiceTest extends TestCase
{
    protected function setUp(): void
    {
        I18nService::init(dirname(__DIR__, 3) . '/locales', 'en');
    }

    private function makeUserRepo(?array $user = null): UserRepositoryInterface
    {
        return new class ($user) implements UserRepositoryInterface {
            public array $updated = [];

            public function __construct(private ?array $user)
            {
            }

            public function findByEmail(string $email): ?array
            {
                if ($this->user === null) {
                    return null;
                }

                return mb_strtolower(trim($email)) === $this->user['email'] ? $this->user : null;
            }

            public function create(string $email, string $passwordHash, string $displayName, string $role, string $preferredLanguage): int
            {
                return 1;
            }

            public function touchLastLogin(int $id): void
            {
            }

            public function updatePassword(int $id, string $passwordHash): void
            {
                $this->updated = [$id, $passwordHash];
            }
        };
    }

    private function makeResetRepo(?array $row = null): PasswordResetRepositoryInterface
    {
        return new class ($row) implements PasswordResetRepositoryInterface {
            public array $created = [];
            public array $deleted = [];
            public array $used = [];

            public function __construct(private ?array $row)
            {
            }

            public function deleteForUser(int $userId): void
            {
                $this->deleted[] = $userId;
            }

            public function create(int $userId, string $email, string $tokenHash, string $expiresAt): void
            {
                $this->created = compact('userId', 'email', 'tokenHash', 'expiresAt');
            }

            public function findByTokenHash(string $tokenHash): ?array
            {
                if ($this->row === null) {
                    return null;
                }

                return $this->row['token_hash'] === $tokenHash ? $this->row : null;
            }

            public function markUsed(int $id): void
            {
                $this->used[] = $id;
            }
        };
    }

    public function testRequestResetCreatesTokenAndSendsMail(): void
    {
        $user = [
            'id' => 7,
            'email' => 'instructor@example.com',
            'display_name' => 'Demo Instructor',
            'role' => 'instructor',
            'preferred_language' => 'en',
            'is_active' => '1',
        ];
        $users = $this->makeUserRepo($user);
        $resets = $this->makeResetRepo();
        $sent = [];

        $service = new PasswordResetService(
            $users,
            $resets,
            function (string $to, string $subject, string $body) use (&$sent): bool {
                $sent = compact('to', 'subject', 'body');

                return true;
            },
            fn (): string => 'fixed-reset-token'
        );

        $service->requestReset('Instructor@Example.com');

        $this->assertSame([7], $resets->deleted);
        $this->assertSame(7, $resets->created['userId']);
        $this->assertSame('instructor@example.com', $resets->created['email']);
        $this->assertSame(hash('sha256', 'fixed-reset-token'), $resets->created['tokenHash']);
        $this->assertNotEmpty($resets->created['expiresAt']);
        $this->assertSame('instructor@example.com', $sent['to']);
        $this->assertStringContainsString('/reset-password/fixed-reset-token', $sent['body']);
    }

    public function testResetPasswordUpdatesHashAndMarksTokenUsed(): void
    {
        $token = 'fixed-reset-token';
        $row = [
            'id' => 9,
            'user_id' => 7,
            'email' => 'instructor@example.com',
            'token_hash' => hash('sha256', $token),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
            'used_at' => null,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ];

        $users = $this->makeUserRepo([
            'id' => 7,
            'email' => 'instructor@example.com',
            'display_name' => 'Demo Instructor',
            'role' => 'instructor',
            'preferred_language' => 'en',
            'is_active' => '1',
        ]);
        $resets = $this->makeResetRepo($row);
        $service = new PasswordResetService($users, $resets, null, fn (): string => $token);

        $service->resetPassword($token, 'NewPassw0rd!');

        $this->assertSame(7, $users->updated[0]);
        $this->assertTrue(password_verify('NewPassw0rd!', $users->updated[1]));
        $this->assertSame([9], $resets->used);
    }

    public function testResetPasswordRejectsExpiredToken(): void
    {
        $token = 'fixed-reset-token';
        $row = [
            'id' => 9,
            'user_id' => 7,
            'email' => 'instructor@example.com',
            'token_hash' => hash('sha256', $token),
            'expires_at' => gmdate('Y-m-d H:i:s', time() - 3600),
            'used_at' => null,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ];

        $service = new PasswordResetService($this->makeUserRepo([
            'id' => 7,
            'email' => 'instructor@example.com',
            'display_name' => 'Demo Instructor',
            'role' => 'instructor',
            'preferred_language' => 'en',
            'is_active' => '1',
        ]), $this->makeResetRepo($row), null, fn (): string => $token);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid_reset_token');
        $service->resetPassword($token, 'NewPassw0rd!');
    }

    public function testResetPasswordRejectsWeakPassword(): void
    {
        $token = 'fixed-reset-token';
        $row = [
            'id' => 9,
            'user_id' => 7,
            'email' => 'instructor@example.com',
            'token_hash' => hash('sha256', $token),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
            'used_at' => null,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ];

        $service = new PasswordResetService($this->makeUserRepo([
            'id' => 7,
            'email' => 'instructor@example.com',
            'display_name' => 'Demo Instructor',
            'role' => 'instructor',
            'preferred_language' => 'en',
            'is_active' => '1',
        ]), $this->makeResetRepo($row), null, fn (): string => $token);

        try {
            $service->resetPassword($token, 'weakpass!!');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('password_too_weak', $e->getErrorCode());
            $this->assertSame(400, $e->getStatus());
            $this->assertSame('validation_error', $e->getPublicCode());
            $this->assertSame('password', $e->getField());
        }
    }
}
