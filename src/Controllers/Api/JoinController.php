<?php

declare(strict_types=1);

namespace EduQR\Controllers\Api;

use EduQR\Middleware\RateLimitMiddleware;
use EduQR\Repositories\ParticipantRepository;
use EduQR\Repositories\SessionRepository;
use EduQR\Services\ParticipantService;

final class JoinController
{
    private ParticipantService $service;

    public function __construct()
    {
        $this->service = new ParticipantService(
            new ParticipantRepository(),
            new SessionRepository(),
        );
    }

    // ── POST /api/v1/sessions/{short_code}/join ───────────────────────────────

    public function join(string $shortCode): void
    {
        // Rate limit: max 20 join attempts per IP per 10 minutes (SEC §14)
        $ipHash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|join');
        RateLimitMiddleware::check("join:{$ipHash}", 20, 600);

        // T-505: Ensure eduqr_device persistent cookie exists
        $cookieId = $this->ensureDeviceCookie();

        $body = (array) (json_decode(file_get_contents('php://input') ?: '{}', true) ?? []);
        $rawNickname = trim((string) ($body['nickname'] ?? ''));
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        try {
            $result = $this->service->join($shortCode, $rawNickname, $cookieId, $userAgent);
        } catch (\InvalidArgumentException $e) {
            match ($e->getMessage()) {
                'nickname:required' => $this->error(400, 'nickname_required', t('validation.required'), 'nickname'),
                'nickname:too_long' => $this->error(400, 'nickname_too_long', t('validation.nickname_too_long'), 'nickname'),
                'nickname:invalid_chars' => $this->error(400, 'nickname_invalid_chars', t('student.join.error.invalid'), 'nickname'),
                default => $this->error(400, 'validation_error', t('common.error'), ''),
            };
        } catch (\RuntimeException $e) {
            match ($e->getMessage()) {
                'session_not_found' => $this->error(404, 'session_not_found', t('error.session_not_found')),
                'session_closed' => $this->error(410, 'session_closed', t('error.session_closed')),
                'session_paused' => $this->error(410, 'session_paused', t('error.session_paused')),
                'duplicate_nickname' => $this->error(409, 'duplicate_nickname', t('error.duplicate_nickname'), 'nickname'),
                'profane_nickname' => $this->error(400, 'profane_nickname', t('error.profane_nickname'), 'nickname'),
                default => $this->error(500, 'server_error', t('error.server_error')),
            };
        }

        // Set participant session cookie (session-lifetime, HttpOnly)
        setcookie('eduqr_participant', (string) $result['participant_id'], [
            'expires' => 0,
            'path' => '/',
            'secure' => (bool) ($_SERVER['HTTPS'] ?? false),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        $this->json(201, [
            'success' => true,
            'data' => $result,
            'message' => t('student.joined'),
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /** Ensure the eduqr_device persistent cookie is set; return the cookie value. */
    private function ensureDeviceCookie(): string
    {
        $name = 'eduqr_device';

        if (! empty($_COOKIE[$name]) && strlen($_COOKIE[$name]) >= 32) {
            return $_COOKIE[$name];
        }

        // Generate a new random device ID
        $id = bin2hex(random_bytes(16)); // 32 hex chars
        setcookie($name, $id, [
            'expires' => time() + 365 * 24 * 3600, // 1 year
            'path' => '/',
            'secure' => (bool) ($_SERVER['HTTPS'] ?? false),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        return $id;
    }

    private function json(int $status, array $payload): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }

    private function error(int $status, string $code, string $message, string $field = ''): never
    {
        $error = ['code' => $code, 'message' => $message];
        if ($field !== '') {
            $error['field'] = $field;
        }
        $this->json($status, ['success' => false, 'error' => $error]);
    }
}
