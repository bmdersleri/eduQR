<?php

declare(strict_types=1);

namespace EduQR\Controllers\Api;

use EduQR\Container;
use EduQR\Controllers\ApiController;
use EduQR\Middleware\RateLimitMiddleware;
use EduQR\Services\ParticipantService;

final class JoinController extends ApiController
{
    private ParticipantService $service;

    public function __construct()
    {
        $this->service = Container::participantService();
    }

    // ── POST /api/v1/sessions/{short_code}/join ───────────────────────────────

    public function join(string $shortCode): void
    {
        // Rate limit: max 20 join attempts per IP per 10 minutes (SEC §14)
        $ipHash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|join');
        RateLimitMiddleware::check("join:{$ipHash}", 20, 600);

        // T-505: Ensure eduqr_device persistent cookie exists
        $cookieId = $this->ensureDeviceCookie();

        // Not self::jsonBody(): this endpoint coerces a non-object JSON body to
        // an array and answers 400 for the missing nickname, where the shared
        // decoder would let the TypeError become a 500.
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
            // Joining is the 410 side of the session_closed / session_paused
            // divergence (SYSTEM_ARCHITECTURE.md §9.1); the status rides on the
            // exception thrown by ParticipantService, not on a table here.
            $this->handleRuntimeException($e);
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
}
