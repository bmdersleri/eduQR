<?php

declare(strict_types=1);

namespace EduQR\Controllers\Api;

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
final class ResultsController
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
            $this->handleError($e);
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
            $this->handleError($e);
        }

        $this->json(200, ['success' => true, 'data' => $data]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function handleError(\RuntimeException $e): never
    {
        match ($e->getMessage()) {
            'session_not_found' => $this->error(404, 'session_not_found', t('error.session_not_found')),
            'forbidden' => $this->error(403, 'forbidden', t('error.forbidden')),
            'question_not_found' => $this->error(404, 'question_not_found', t('error.question_not_found')),
            'results_hidden' => $this->error(403, 'results_hidden', t('error.results_hidden')),
            default => $this->error(500, 'server_error', t('error.server_error')),
        };
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
        $this->json($status, ['success' => false, 'error' => ['code' => $code, 'message' => $message]]);
    }
}
