<?php

declare(strict_types=1);

namespace EduQR\Controllers\Api;

use EduQR\Container;
use EduQR\Controllers\ApiController;
use EduQR\Services\PollVersionService;
use EduQR\Services\QuestionService;

final class PublicQuestionController extends ApiController
{
    private QuestionService $service;
    private PollVersionService $versions;

    public function __construct()
    {
        $this->service = Container::questionService();
        $this->versions = Container::pollVersionService();
    }

    // ── GET /api/v1/sessions/{short_code}/active-question ─────────────────────

    public function activeQuestion(string $shortCode): void
    {
        // Require participant cookie (API spec §3.2 — 401 not_joined)
        $participantId = (int) ($_COOKIE['eduqr_participant'] ?? 0);
        if ($participantId <= 0) {
            $this->error(401, 'not_joined', t('error.not_joined'));
        }

        $shortCode = strtoupper(trim($shortCode));

        try {
            // The version query repeats the session guards, so a closed or
            // unknown session is still answered 410 or 404 no matter what
            // If-None-Match the phone sends (NFR-76).
            $this->etagOrNotModified($this->versions->activeQuestionVersion($shortCode));

            $question = $this->service->getActiveQuestionByCode($shortCode);
        } catch (\RuntimeException $e) {
            $this->handleRuntimeException($e);
        }

        $this->json(200, [
            'success' => true,
            'data' => ['question' => $question],
        ]);
    }
}
