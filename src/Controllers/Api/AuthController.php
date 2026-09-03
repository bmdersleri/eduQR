<?php

declare(strict_types=1);

namespace EduQR\Controllers\Api;

use EduQR\Container;
use EduQR\Contracts\AuditLogRepositoryInterface;
use EduQR\Controllers\ApiController;
use EduQR\Exceptions\DomainException;
use EduQR\Middleware\AuthMiddleware;
use EduQR\Middleware\CsrfMiddleware;
use EduQR\Services\AuthService;
use EduQR\Services\PasswordResetService;

final class AuthController extends ApiController
{
    private AuthService $auth;
    private PasswordResetService $passwordResets;
    private AuditLogRepositoryInterface $auditLog;

    public function __construct()
    {
        $this->auth = Container::authService();
        $this->passwordResets = Container::passwordResetService();
        $this->auditLog = Container::auditLogRepository();
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
        } catch (DomainException $e) {
            $this->failFromDomain($e);
        } catch (\RuntimeException $e) {
            // Intentionally vague — never reveal whether the email exists (FR-08).
            // Only untyped failures land here; `invalid_credentials` and
            // `too_many_attempts` are typed and answered by the mapper above.
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
            // `invalid_reset_token` carries its own 400, `token` field and
            // message key; anything untyped keeps answering the same way.
            $this->handleRuntimeException($e);
        }

        $this->json(200, [
            'success' => true,
            'message' => t('auth.reset.success'),
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

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
