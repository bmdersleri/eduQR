<?php

declare(strict_types=1);

namespace EduQR\Services;

use EduQR\Config;
use EduQR\Contracts\PasswordResetRepositoryInterface;
use EduQR\Contracts\UserRepositoryInterface;

final class PasswordResetService
{
    private const TOKEN_BYTES = 32;
    private const TOKEN_TTL_MINUTES = 60;

    private const PASSWORD_MIN_LENGTH = 10;
    private const PASSWORD_MAX_LENGTH = 128;

    private UserRepositoryInterface $users;
    private PasswordResetRepositoryInterface $resets;
    private \Closure $mailer;
    private \Closure $tokenGenerator;

    public function __construct(
        UserRepositoryInterface $users,
        PasswordResetRepositoryInterface $resets,
        ?\Closure $mailer = null,
        ?\Closure $tokenGenerator = null
    ) {
        $this->users = $users;
        $this->resets = $resets;
        $this->mailer = $mailer ?? $this->defaultMailer();
        $this->tokenGenerator = $tokenGenerator ?? $this->defaultTokenGenerator();
    }

    public function requestReset(string $email): void
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') {
            throw new \InvalidArgumentException('email:required');
        }

        $user = $this->users->findByEmail($email);
        if ($user === null || ! (bool) ($user['is_active'] ?? false)) {
            return;
        }

        $this->resets->deleteForUser((int) $user['id']);

        $token = ($this->tokenGenerator)();
        $this->resets->create(
            (int) $user['id'],
            $email,
            hash('sha256', $token),
            $this->expiresAt()
        );

        $resetUrl = eduqr_url('/reset-password/' . rawurlencode($token));
        $subject = t('auth.reset.email.subject', ['app' => Config::get('APP_NAME', 'eduQR')]);
        $body = t('auth.reset.email.body', [
            'name' => (string) ($user['display_name'] ?? ''),
            'app' => (string) Config::get('APP_NAME', 'eduQR'),
            'url' => $resetUrl,
            'minutes' => self::TOKEN_TTL_MINUTES,
        ]);

        try {
            ($this->mailer)($email, $subject, $body);
        } catch (\Throwable $e) {
            error_log('[eduQR] password reset email failed: ' . $e->getMessage());
        }
    }

    public function resetPassword(string $token, string $password): void
    {
        $token = trim($token);
        if ($token === '') {
            throw new \InvalidArgumentException('token:required');
        }

        $this->validatePassword($password);

        $row = $this->resets->findByTokenHash(hash('sha256', $token));
        if ($row === null || ! empty($row['used_at'])) {
            throw new \RuntimeException('invalid_reset_token');
        }

        if (strtotime((string) $row['expires_at']) < time()) {
            throw new \RuntimeException('invalid_reset_token');
        }

        $this->users->updatePassword((int) $row['user_id'], AuthService::hashPassword($password));
        $this->resets->markUsed((int) $row['id']);
    }

    private function validatePassword(string $password): void
    {
        $length = mb_strlen($password);
        if ($length < self::PASSWORD_MIN_LENGTH) {
            throw new \InvalidArgumentException('password:too_short');
        }

        if ($length > self::PASSWORD_MAX_LENGTH) {
            throw new \InvalidArgumentException('password:too_long');
        }

        $score = 0;
        $score += preg_match('/[a-z]/', $password) ? 1 : 0;
        $score += preg_match('/[A-Z]/', $password) ? 1 : 0;
        $score += preg_match('/\d/', $password) ? 1 : 0;
        $score += preg_match('/[^\p{L}\p{N}\s]/u', $password) ? 1 : 0;

        if ($score < 3) {
            throw new \InvalidArgumentException('password:too_weak');
        }
    }

    private function expiresAt(): string
    {
        $expires = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $expires->modify('+' . self::TOKEN_TTL_MINUTES . ' minutes')->format('Y-m-d H:i:s');
    }

    private function defaultMailer(): \Closure
    {
        return static function (string $to, string $subject, string $body): bool {
            $from = (string) Config::get('MAIL_FROM', 'noreply@localhost');
            $headers = [
                'From: ' . $from,
                'Reply-To: ' . $from,
                'Content-Type: text/plain; charset=UTF-8',
            ];

            return mail($to, $subject, $body, implode("\r\n", $headers));
        };
    }

    private function defaultTokenGenerator(): \Closure
    {
        return static function (): string {
            return bin2hex(random_bytes(self::TOKEN_BYTES));
        };
    }
}
