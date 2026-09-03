<?php

declare(strict_types=1);

namespace EduQR\Controllers\Api;

use EduQR\Container;
use EduQR\Contracts\AuditLogRepositoryInterface;
use EduQR\Controllers\ApiController;
use EduQR\Middleware\AuthMiddleware;
use EduQR\Middleware\CsrfMiddleware;
use EduQR\Services\SessionService;
use EduQR\Support\Url;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;

final class SessionController extends ApiController
{
    private SessionService $service;
    private AuditLogRepositoryInterface $auditLog;

    public function __construct()
    {
        $this->service = Container::sessionService();
        $this->auditLog = Container::auditLogRepository();
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
        }

        // Audit: FR-90
        try {
            $this->auditLog->write('instructor', (int) $user['id'], 'session.create', 'session', (int) $result['id']);
        } catch (\Throwable) {
        }

        $this->json(201, [
            'success' => true,
            'data' => $result,
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

        $joinUrl = Url::absolute('/join/' . $session['short_code']);

        $this->json(200, [
            'success' => true,
            'data' => $this->sessionPayload($session, $joinUrl),
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
        }

        $this->json(200, [
            'success' => true,
            'data' => null,
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
            'data' => null,
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
            'data' => null,
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

        // Audit: FR-90
        try {
            $this->auditLog->write('instructor', (int) $user['id'], 'session.close', 'session', $id);
        } catch (\Throwable) {
        }

        $this->json(200, [
            'success' => true,
            'data' => null,
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

        $size = max(128, min(1024, (int) ($_GET['size'] ?? 512)));
        $joinUrl = Url::absolute('/join/' . $session['short_code']);

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

    // ── POST /api/v1/sessions/{id}/anonymize (T-906) ─────────────────────────

    public function anonymize(int $id): void
    {
        $user = AuthMiddleware::require();
        CsrfMiddleware::verify();

        try {
            $this->service->anonymizeSession($id, (int) $user['id']);
        } catch (\RuntimeException $e) {
            $this->handleRuntimeException($e);
        }

        // Audit: FR-90
        try {
            $this->auditLog->write('instructor', (int) $user['id'], 'session.anonymize', 'session', $id);
        } catch (\Throwable) {
        }

        $this->json(200, [
            'success' => true,
            'data' => null,
            'message' => t('session.anonymized'),
        ]);
    }

    // ── DELETE /api/v1/sessions/{id} (T-907) ─────────────────────────────────

    public function delete(int $id): void
    {
        $user = AuthMiddleware::require();
        CsrfMiddleware::verify();

        try {
            $this->service->requestDeletion($id, (int) $user['id']);
        } catch (\RuntimeException $e) {
            $this->handleRuntimeException($e);
        }

        // Audit: FR-90
        try {
            $this->auditLog->write('instructor', (int) $user['id'], 'session.delete_request', 'session', $id);
        } catch (\Throwable) {
        }

        $this->json(200, [
            'success' => true,
            'data' => null,
            'message' => t('session.delete_requested'),
        ]);
    }

    // ── GET /api/v1/sessions/{id}/participants/count ──────────────────────────

    /**
     * The one polled endpoint with no version query of its own: API_SPEC.md
     * §1.9 says its version is "the count itself", and the count is also the
     * whole body. So it is read once — after getParticipantCount() has proved
     * the caller may see this session — and used for both (NFR-76).
     */
    public function participantCount(int $id): void
    {
        $user = AuthMiddleware::require();

        try {
            $count = $this->service->getParticipantCount($id, (int) $user['id']);
        } catch (\RuntimeException $e) {
            $this->handleRuntimeException($e);
        }

        $this->etagOrNotModified('participants|' . $id . '|' . $count);

        $this->json(200, [
            'success' => true,
            'data' => ['count' => $count],
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function sessionPayload(array $session, string $joinUrl): array
    {
        return [
            'id' => (int) $session['id'],
            'course_id' => (int) $session['course_id'],
            'title' => $session['title'],
            'short_code' => $session['short_code'],
            'status' => $session['status'],
            'language' => $session['language'],
            'show_results_to_students' => (bool) $session['show_results_to_students'],
            'moderation_mode' => (bool) $session['moderation_mode'],
            'exam_mode' => (bool) $session['exam_mode'],
            'is_quiz' => (bool) $session['is_quiz'],
            'join_url' => $joinUrl,
            'qr_url' => Url::path('/api/v1/sessions/' . (int) $session['id'] . '/qr.png'),
            'started_at' => $session['started_at'],
            'paused_at' => $session['paused_at'],
            'closed_at' => $session['closed_at'],
            'created_at' => $session['created_at'],
        ];
    }
}
