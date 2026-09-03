<?php

declare(strict_types=1);

namespace EduQR\Controllers\Api;

use EduQR\Container;
use EduQR\Controllers\ApiController;
use EduQR\Middleware\AuthMiddleware;
use EduQR\Middleware\RateLimitMiddleware;
use EduQR\Services\PollVersionService;
use EduQR\Services\ReactionService;

/**
 * Phase 11 — Student comprehension reactions (T-1105, FR-48)
 *
 * POST /api/v1/reactions                 — student sends got_it / lost
 * GET  /api/v1/sessions/{id}/reactions   — instructor reads aggregate counts
 *
 * Auth: the student endpoint uses the participant cookie (eduqr_participant),
 * the instructor endpoint uses the logged-in instructor session.
 */
final class ReactionController extends ApiController
{
    private ReactionService $service;
    private ?PollVersionService $versions;

    public function __construct(?ReactionService $service = null, ?PollVersionService $versions = null)
    {
        $this->service = $service ?? Container::reactionService();
        $this->versions = $versions;
    }

    /**
     * Resolved on use rather than in the constructor: only aggregates() polls,
     * and a student submitting a reaction should not pay for a collaborator
     * that endpoint never touches (NFR-76).
     */
    private function versions(): PollVersionService
    {
        return $this->versions ??= Container::pollVersionService();
    }

    // ── POST /api/v1/reactions ────────────────────────────────────────────────

    public function submit(): void
    {
        // No CSRF token required for student JSON API — they are not logged-in
        // instructors. Same treatment as POST /api/v1/answers.

        // Rate limit: max 60 reactions per IP per minute (SEC §14)
        $ipHash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|reaction');
        RateLimitMiddleware::check("reaction:{$ipHash}", 60, 60);

        $participantId = $this->resolveParticipant();
        $body = $this->jsonBody();

        try {
            $reaction = $this->service->react($participantId, $body);
        } catch (\RuntimeException $e) {
            $this->handleRuntimeException($e);
        }

        $this->json(200, $this->buildStudentPayload($reaction));
    }

    /**
     * Deliberately carries no aggregate counts (FR-48). Because the student
     * never learns the totals, reacting stays allowed regardless of exam_mode,
     * show_results_to_students and per-question show_results — a reaction is
     * neither a result nor a correctness signal.
     *
     * @return array{success:bool,data:array{reaction:string},message:string}
     */
    private function buildStudentPayload(string $reaction): array
    {
        return [
            'success' => true,
            'data' => ['reaction' => $reaction],
            'message' => t('student.reaction.recorded'),
        ];
    }

    // ── GET /api/v1/sessions/{id}/reactions ───────────────────────────────────

    public function aggregates(int $sessionId): void
    {
        $user = AuthMiddleware::require();

        try {
            // Same guards as aggregatesForSession(), run first, so a caller with
            // no role on the course is refused whatever ETag they hold (NFR-76).
            $this->etagOrNotModified($this->versions()->reactionsVersion($sessionId, (int) $user['id']));

            $data = $this->service->aggregatesForSession($sessionId, (int) $user['id']);
        } catch (\RuntimeException $e) {
            $this->handleRuntimeException($e);
        }

        $this->json(200, ['success' => true, 'data' => $data]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Resolve participant from the eduqr_participant cookie.
     * Returns participant ID (int).
     */
    private function resolveParticipant(): int
    {
        $raw = $_COOKIE['eduqr_participant'] ?? '';
        if ($raw === '') {
            $this->error(401, 'not_joined', t('error.not_joined'));
        }

        $id = (int) $raw;
        if ($id <= 0) {
            $this->error(401, 'not_joined', t('error.not_joined'));
        }

        return $id;
    }
}
