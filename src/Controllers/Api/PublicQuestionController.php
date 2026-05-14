<?php

declare(strict_types=1);

namespace EduQR\Controllers\Api;

use EduQR\Repositories\CourseRepository;
use EduQR\Repositories\OptionRepository;
use EduQR\Repositories\QuestionRepository;
use EduQR\Repositories\SessionRepository;
use EduQR\Services\QuestionService;

final class PublicQuestionController
{
    private QuestionService $service;

    public function __construct()
    {
        $this->service = new QuestionService(
            new QuestionRepository(),
            new OptionRepository(),
            new SessionRepository(),
            new CourseRepository(),
        );
    }

    // ── GET /api/v1/sessions/{short_code}/active-question ─────────────────────

    public function activeQuestion(string $shortCode): void
    {
        // Require participant cookie (API spec §3.2 — 401 not_joined)
        $participantId = (int) ($_COOKIE['eduqr_participant'] ?? 0);
        if ($participantId <= 0) {
            $this->json(401, [
                'success' => false,
                'error'   => ['code' => 'not_joined', 'message' => t('error.not_joined')],
            ]);
        }

        try {
            $question = $this->service->getActiveQuestionByCode(strtoupper(trim($shortCode)));
        } catch (\RuntimeException $e) {
            match ($e->getMessage()) {
                'session_not_found' => $this->json(404, [
                    'success' => false,
                    'error'   => ['code' => 'session_not_found', 'message' => t('error.session_not_found')],
                ]),
                'session_closed' => $this->json(410, [
                    'success' => false,
                    'error'   => ['code' => 'session_closed', 'message' => t('error.session_closed')],
                ]),
                default => $this->json(500, [
                    'success' => false,
                    'error'   => ['code' => 'server_error', 'message' => t('error.server_error')],
                ]),
            };
        }

        $this->json(200, [
            'success' => true,
            'data'    => ['question' => $question],
        ]);
    }

    private function json(int $status, array $payload): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
}
