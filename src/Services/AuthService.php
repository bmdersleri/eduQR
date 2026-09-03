<?php

declare(strict_types=1);

namespace EduQR\Services;

use EduQR\Config;
use EduQR\Contracts\LoginAttemptRepositoryInterface;
use EduQR\Contracts\UserRepositoryInterface;
use EduQR\Exceptions\ForbiddenException;
use EduQR\Exceptions\NotFoundException;

final class AuthService
{
    private const BCRYPT_COST = 12;

    // Rate limiting: 5 failures within 15-minute window → locked
    private const RATE_MAX = 5;
    private const RATE_LOCK = 900; // 15 minutes in seconds

    // Dummy bcrypt hash for constant-time comparison when user is not found.
    // Prevents timing attacks that would reveal whether an email is registered.
    private const DUMMY_HASH = '$2y$12$u9GwnD2qPr.8SxqXxBbmHOC2nHgFdJNt5C.BNcjBbBGzOkmJfDXji';

    private UserRepositoryInterface $users;
    private LoginAttemptRepositoryInterface $attempts;

    public function __construct(
        UserRepositoryInterface $users,
        LoginAttemptRepositoryInterface $attempts
    ) {
        $this->users = $users;
        $this->attempts = $attempts;
    }

    /**
     * Attempt instructor login. Returns the user row on success.
     * Throws a typed domain exception carrying a stable error code on failure:
     *   'too_many_attempts' | 'invalid_credentials'
     */
    public function login(string $email, string $password): array
    {
        $email = mb_strtolower(trim($email));

        if ($this->isRateLimited($email)) {
            throw new ForbiddenException('too_many_attempts', 429);
        }

        $user = $this->users->findByEmail($email);
        $hashToCheck = ($user !== null) ? $user['password_hash'] : self::DUMMY_HASH;
        $valid = password_verify($password, $hashToCheck);

        if (! $valid || $user === null || ! (bool) $user['is_active']) {
            $this->attempts->record($email, $this->ipHash(), false);

            throw new NotFoundException('invalid_credentials', 401);
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST])) {
            // Rehash stored in UserRepository update — handled by caller when needed.
            // AuthService cannot update without the plain-text password; AuthController does this.
        }

        $this->attempts->record($email, $this->ipHash(), true);
        $this->users->touchLastLogin((int) $user['id']);

        return $user;
    }

    // ── Session management (T-210) ─────────────────────────────────────────────

    public function startSession(array $user): void
    {
        self::ensureSessionStarted();
        session_regenerate_id(true);

        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_name'] = $user['display_name'];
        $_SESSION['logged_in_at'] = time();
    }

    public function destroySession(): void
    {
        self::ensureSessionStarted();
        $_SESSION = [];
        session_destroy();

        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 3600,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    public static function currentUser(): ?array
    {
        self::ensureSessionStarted();

        if (empty($_SESSION['user_id'])) {
            return null;
        }

        return [
            'id' => (int) $_SESSION['user_id'],
            'email' => (string) ($_SESSION['user_email'] ?? ''),
            'role' => (string) ($_SESSION['user_role'] ?? ''),
            'display_name' => (string) ($_SESSION['user_name'] ?? ''),
        ];
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);
    }

    // ── Internal helpers ───────────────────────────────────────────────────────

    private function isRateLimited(string $email): bool
    {
        return $this->attempts->countRecentFailures($email, self::RATE_LOCK) >= self::RATE_MAX;
    }

    private function ipHash(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;

        return $ip !== null ? hash('sha256', $ip) : null;
    }

    // T-210: session cookie flags — HttpOnly + Secure + SameSite=Lax
    private static function ensureSessionStarted(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name('eduqr_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => Config::bool('COOKIE_SECURE', true),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}
