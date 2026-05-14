<?php

declare(strict_types=1);

namespace EduQR\Controllers\Api;

use EduQR\Config;
use EduQR\Middleware\AuthMiddleware;
use EduQR\Middleware\CsrfMiddleware;
use EduQR\Repositories\CourseRepository;
use EduQR\Repositories\SessionRepository;
use EduQR\Services\SessionService;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;

final class SessionController
{
    private SessionService $service;

    public function __construct()
    {
        $this->service = new SessionService(new SessionRepository(), new CourseRepository());
    }

    // ── POST /api/v1/courses/{id}/sessions ────────────────────────────────────

    public function create(int $courseId): void
    {
        $user = AuthMiddleware::require();
        CsrfMiddleware::verify();

        $body = $this->jsonBody();

        try {
            $result = $this->service->createSession($courseId, (int) $user['id'], $body);
        } catch (\RuntimeException $e) {
            $this->handleRuntimeException($e);
        } catch (\InvalidArgumentException $e) {
            $this->handleValidationException($e);
        }

        $this->json(201, [
            'success' => true,
            'data'    => $result,
            'message' => t('session.created'),
        ]);
    }

    // ── GET /api/v1/sessions/{id} ─────────────────────────────────────────────

    public function show(int $id): void
    {
        $user = AuthMiddleware::require();

        try {
            $session = $this->service->getSession($id, (int) $user['id']);
        } catch (\RuntimeException $e) {
            $this->handleRuntimeException($e);
        }

        $appUrl  = rtrim(Config::get('APP_URL', ''), '/');
        $joinUrl = $appUrl . '/join/' . $session['short_code'];

        $this->json(200, [
            'success' => true,
            'data'    => $this->sessionPayload($session, $joinUrl),
        ]);
    }

    // ── PATCH /api/v1/sessions/{id} ───────────────────────────────────────────

    public function update(int $id): void
    {
        $user = AuthMiddleware::require();
        CsrfMiddleware::verify();

        $body = $this->jsonBody();

        try {
            $this->service->updateSession($id, (int) $user['id'], $body);
        } catch (\RuntimeException $e) {
            $this->handleRuntimeException($e);
        } catch (\InvalidArgumentException $e) {
            $this->handleValidationException($e);
        }

        $this->json(200, [
            'success' => true,
            'data'    => null,
            'message' => t('common.success'),
        ]);
    }

    // ── POST /api/v1/sessions/{id}/pause ─────────────────────────────────────

    public function pause(int $id): void
    {
        $user = AuthMiddleware::require();
        CsrfMiddleware::verify();

        try {
            $this->service->pauseSession($id, (int) $user['id']);
        } catch (\RuntimeException $e) {
            $this->handleRuntimeException($e);
        }

        $this->json(200, [
            'success' => true,
            'data'    => null,
            'message' => t('session.paused'),
        ]);
    }

    // ── POST /api/v1/sessions/{id}/resume ────────────────────────────────────

    public function resume(int $id): void
    {
        $user = AuthMiddleware::require();
        CsrfMiddleware::verify();

        try {
            $this->service->resumeSession($id, (int) $user['id']);
        } catch (\RuntimeException $e) {
            $this->handleRuntimeException($e);
        }

        $this->json(200, [
            'success' => true,
            'data'    => null,
            'message' => t('session.resumed'),
        ]);
    }

    // ── POST /api/v1/sessions/{id}/close ─────────────────────────────────────

    public function close(int $id): void
    {
        $user = AuthMiddleware::require();
        CsrfMiddleware::verify();

        try {
            $this->service->closeSession($id, (int) $user['id']);
        } catch (\RuntimeException $e) {
            $this->handleRuntimeException($e);
        }

        $this->json(200, [
            'success' => true,
            'data'    => null,
            'message' => t('session.closed'),
        ]);
    }

    // ── GET /api/v1/sessions/{id}/qr.png ─────────────────────────────────────

    public function qrPng(int $id): void
    {
        $user = AuthMiddleware::require();

        try {
            $session = $this->service->getSession($id, (int) $user['id']);
        } catch (\RuntimeException $e) {
            http_response_code($e->getMessage() === 'session_not_found' ? 404 : 403);
            exit;
        }

        $size    = max(128, min(1024, (int) ($_GET['size'] ?? 512)));
        $appUrl  = rtrim(Config::get('APP_URL', ''), '/');
        $joinUrl = $appUrl . '/join/' . $session['short_code'];

        $result = Builder::create()
            ->writer(new PngWriter())
            ->data($joinUrl)
            ->encoding(new Encoding('UTF-8'))
            ->errorCorrectionLevel(ErrorCorrectionLevel::High)
            ->size($size)
            ->margin(10)
            ->build();

        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=3600');
        echo $result->getString();
        exit;
    }

    // ── GET /api/v1/sessions/{id}/participants/count ──────────────────────────

    public function participantCount(int $id): void
    {
        $user = AuthMiddleware::require();

        try {
            $count = $this->service->getParticipantCount($id, (int) $user['id']);
        } catch (\RuntimeException $e) {
            $this->handleRuntimeException($e);
        }

        $this->json(200, [
            'success' => true,
            'data'    => ['count' => $count],
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function sessionPayload(array $session, string $joinUrl): array
    {
        return [
            'id'                       => (int) $session['id'],
            'course_id'                => (int) $session['course_id'],
            'title'                    => $session['title'],
            'short_code'               => $session['short_code'],
            'status'                   => $session['status'],
            'language'                 => $session['language'],
            'show_results_to_students' => (bool) $session['show_results_to_students'],
            'moderation_mode'          => (bool) $session['moderation_mode'],
            'join_url'                 => $joinUrl,
            'qr_url'                   => '/api/v1/sessions/' . (int) $session['id'] . '/qr.png',
            'started_at'               => $session['started_at'],
            'paused_at'                => $session['paused_at'],
            'closed_at'                => $session['closed_at'],
            'created_at'               => $session['created_at'],
        ];
    }

    private function handleRuntimeException(\RuntimeException $e): never
    {
        match ($e->getMessage()) {
            'session_not_found'       => $this->error(404, 'session_not_found',       t('error.session_not_found')),
            'course_not_found'        => $this->error(404, 'course_not_found',         t('error.course_not_found')),
            'forbidden'               => $this->error(403, 'forbidden',                t('error.forbidden')),
            'invalid_state_transition'=> $this->error(422, 'invalid_state_transition', t('error.invalid_state_transition')),
            default                   => $this->error(500, 'server_error',             t('error.server_error')),
        };
    }

    private function handleValidationException(\InvalidArgumentException $e): never
    {
        $parts   = explode(':', $e->getMessage(), 2);
        $field   = $parts[0];
        $key     = $parts[1] ?? 'validation_error';
        $message = match ($key) {
            'required' => t('validation.required'),
            'too_long' => t('validation.text_too_long'),
            'invalid'  => t('validation.invalid_language'),
            default    => t('common.error'),
        };
        $this->json(400, [
            'success' => false,
            'error'   => ['code' => 'validation_error', 'message' => $message, 'field' => $field],
        ]);
    }

    private function jsonBody(): array
    {
        return json_decode((string) file_get_contents('php://input'), true) ?? [];
    }

    private function json(int $status, array $payload): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }

    private function error(int $status, string $code, string $message): never
    {
        $this->json($status, [
            'success' => false,
            'error'   => ['code' => $code, 'message' => $message],
        ]);
    }
}
