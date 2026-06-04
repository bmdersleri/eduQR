<?php

declare(strict_types=1);

namespace EduQR\Controllers\Api;

use EduQR\Middleware\AuthMiddleware;
use EduQR\Middleware\CsrfMiddleware;
use EduQR\Repositories\AuditLogRepository;
use EduQR\Repositories\LoginAttemptRepository;
use EduQR\Repositories\PasswordResetRepository;
use EduQR\Repositories\UserRepository;
use EduQR\Services\AuthService;
use EduQR\Services\PasswordResetService;

final class AuthController
{
    private AuthService $auth;
    private PasswordResetService $passwordResets;
    private AuditLogRepository $auditLog;

    public function __construct()
    {
        $this->auth = new AuthService(new UserRepository(), new LoginAttemptRepository());
        $this->passwordResets = new PasswordResetService(new UserRepository(), new PasswordResetRepository());
        $this->auditLog = new AuditLogRepository();
    }

    // ── POST /api/v1/auth/login ────────────────────────────────────────────────

    public function login(): void
    {
        CsrfMiddleware::verify();

        $body = $this->jsonBody();
        $email = trim((string) ($body['email'] ?? ''));
        $pass = (string) ($body['password'] ?? '');

        if ($email === '' || $pass === '') {
            $this->error(400, 'missing_fields', t('auth.login.error.missing_fields'));
        }

        try {
            $user = $this->auth->login($email, $pass);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'too_many_attempts') {
                $this->error(429, 'too_many_attempts', t('auth.login.error.locked'));
            }
            // Intentionally vague — never reveal whether the email exists (FR-08).
            $this->error(401, 'invalid_credentials', t('auth.login.error.invalid'));
        }

        $this->auth->startSession($user);

        // Audit: FR-90
        try {
            $this->auditLog->write(
                (string) $user['role'],
                (int) $user['id'],
                'user.login',
                'user',
                (int) $user['id'],
            );
        } catch (\Throwable) {
        }

        $this->json(200, [
            'success' => true,
            'data' => ['user' => $this->userPayload($user)],
            'message' => t('auth.login.success'),
        ]);
    }

    // ── POST /api/v1/auth/logout ───────────────────────────────────────────────

    public function logout(): void
    {
        CsrfMiddleware::verify();

        // Capture user before destroying session
        $user = AuthService::currentUser();

        $this->auth->destroySession();

        // Audit: FR-90
        if ($user !== null) {
            try {
                $this->auditLog->write(
                    (string) $user['role'],
                    (int) $user['id'],
                    'user.logout',
                    'user',
                    (int) $user['id'],
                );
            } catch (\Throwable) {
            }
        }

        http_response_code(204);
    }

    // ── GET /api/v1/auth/me ────────────────────────────────────────────────────

    public function me(): void
    {
        $user = AuthMiddleware::require();
        $this->json(200, [
            'success' => true,
            'data' => ['user' => $user],
        ]);
    }

    // ── POST /api/v1/auth/password-reset/request ─────────────────────────────

    public function requestPasswordReset(): void
    {
        CsrfMiddleware::verify();

        $body = $this->jsonBody();
        $email = trim((string) ($body['email'] ?? ''));

        try {
            $this->passwordResets->requestReset($email);
        } catch (\InvalidArgumentException $e) {
            $this->handlePasswordResetValidation($e);
        }

        $this->json(200, [
            'success' => true,
            'message' => t('auth.reset.request.success'),
        ]);
    }

    // ── POST /api/v1/auth/password-reset/confirm ────────────────────────────

    public function confirmPasswordReset(): void
    {
        CsrfMiddleware::verify();

        $body = $this->jsonBody();
        $token = trim((string) ($body['token'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $confirm = (string) ($body['password_confirmation'] ?? '');

        if ($password !== $confirm) {
            $this->error(400, 'validation_error', t('auth.reset.error.mismatch'), 'password_confirmation');
        }

        try {
            $this->passwordResets->resetPassword($token, $password);
        } catch (\InvalidArgumentException $e) {
            $this->handlePasswordResetValidation($e);
        } catch (\RuntimeException $e) {
            $this->error(400, 'invalid_reset_token', t('auth.reset.error.invalid_token'), 'token');
        }

        $this->json(200, [
            'success' => true,
            'message' => t('auth.reset.success'),
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function jsonBody(): array
    {
        $raw = (string) file_get_contents('php://input');

        return json_decode($raw, true) ?? [];
    }

    private function userPayload(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'display_name' => $user['display_name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'preferred_language' => $user['preferred_language'] ?? 'en',
        ];
    }

    private function json(int $status, array $payload): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }

    private function error(int $status, string $code, string $message, ?string $field = null): never
    {
        $payload = [
            'success' => false,
            'error' => ['code' => $code, 'message' => $message],
        ];

        if ($field !== null && $field !== '') {
            $payload['error']['field'] = $field;
        }

        $this->json($status, $payload);
    }

    private function handlePasswordResetValidation(\InvalidArgumentException $e): never
    {
        [$field, $rule] = array_pad(explode(':', $e->getMessage(), 2), 2, 'validation_error');

        $message = match ($field . ':' . $rule) {
            'email:required', 'token:required' => t('validation.required'),
            'password:too_short' => t('validation.password_too_short'),
            'password:too_long' => t('validation.text_too_long'),
            'password:too_weak' => t('validation.password_too_weak'),
            default => t('common.error'),
        };

        $this->error(400, 'validation_error', $message, $field);
    }
}
