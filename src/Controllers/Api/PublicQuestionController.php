<?php

declare(strict_types=1);

namespace EduQR\Controllers\Api;

use EduQR\Controllers\ApiController;
use EduQR\Repositories\CourseRepository;
use EduQR\Repositories\OptionRepository;
use EduQR\Repositories\QuestionRepository;
use EduQR\Repositories\SessionRepository;
use EduQR\Services\QuestionService;

final class PublicQuestionController extends ApiController
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
            $this->error(401, 'not_joined', t('error.not_joined'));
        }

        try {
            $question = $this->service->getActiveQuestionByCode(strtoupper(trim($shortCode)));
        } catch (\RuntimeException $e) {
            $this->handleRuntimeException($e);
        }

        $this->json(200, [
            'success' => true,
            'data' => ['question' => $question],
        ]);
    }
}
