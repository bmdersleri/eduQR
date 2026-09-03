<?php

declare(strict_types=1);

namespace EduQR\Controllers\Api;

use EduQR\Container;
use EduQR\Contracts\ResultsServiceInterface;
use EduQR\Controllers\ApiController;
use EduQR\Middleware\AuthMiddleware;

/**
 * Results endpoints — Phase 8 (T-803, T-804)
 *
 * GET /api/v1/sessions/{id}/results?question_id=  — instructor (T-803)
 * GET /api/v1/sessions/{short_code}/results       — student, gated (T-804)
 */
final class ResultsController extends ApiController
{
    private ResultsServiceInterface $service;

    public function __construct()
    {
        $this->service = Container::resultsService();
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
