<?php

declare(strict_types=1);

namespace EduQR\Controllers\Api;

use EduQR\Controllers\ApiController;
use EduQR\Middleware\AuthMiddleware;
use EduQR\Repositories\CourseRepository;
use EduQR\Repositories\OptionRepository;
use EduQR\Repositories\QuestionRepository;
use EduQR\Repositories\SessionRepository;
use EduQR\Services\ReportService;

/**
 * Results endpoints — Phase 8 (T-803, T-804)
 *
 * GET /api/v1/sessions/{id}/results?question_id=  — instructor (T-803)
 * GET /api/v1/sessions/{short_code}/results       — student, gated (T-804)
 */
final class ResultsController extends ApiController
{
    private ReportService $service;

    public function __construct()
    {
        $this->service = new ReportService(
            new SessionRepository(),
            new QuestionRepository(),
            new OptionRepository(),
            new CourseRepository(),
        );
    }

    // ── GET /api/v1/sessions/{id}/results (instructor) ────────────────────────

    public function instructorResults(int $sessionId): void
    {
        $user = AuthMiddleware::require();
        $questionId = isset($_GET['question_id']) ? (int) $_GET['question_id'] : null;

        try {
            $data = $this->service->getResults($sessionId, (int) $user['id'], $questionId);
        } catch (\RuntimeException $e) {
            $this->handleRuntimeException($e);
        }

        $this->json(200, ['success' => true, 'data' => $data]);
    }

    // ── GET /api/v1/sessions/{short_code}/student-results (student, gated) ────

    public function studentResults(string $shortCode): void
    {
        $questionId = isset($_GET['question_id']) ? (int) $_GET['question_id'] : null;

        try {
            $data = $this->service->getStudentResults($shortCode, $questionId);
        } catch (\RuntimeException $e) {
            $this->handleRuntimeException($e);
        }

        $this->json(200, ['success' => true, 'data' => $data]);
    }
}
