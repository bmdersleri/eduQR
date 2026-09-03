<?php

declare(strict_types=1);

namespace EduQR\Controllers\Api;

use EduQR\Container;
use EduQR\Contracts\QuestionGenerationServiceInterface;
use EduQR\Controllers\ApiController;
use EduQR\Middleware\AuthMiddleware;
use EduQR\Middleware\CsrfMiddleware;
use EduQR\Services\QuestionBankService;

final class QuestionBankController extends ApiController
{
    private QuestionBankService $service;

    /**
     * A caller-supplied generator is threaded through the container so the whole
     * graph is still assembled in one place; passing null takes the configured
     * one.
     */
    public function __construct(?QuestionGenerationServiceInterface $generator = null)
    {
        $this->service = Container::questionBankService($generator);
    }

    // ── GET /api/v1/courses/{id}/question-bank ───────────────────────────────

    public function index(int $courseId): void
    {
        $user = AuthMiddleware::require();

        try {
            $items = $this->service->list($courseId, (int) $user['id']);
        } catch (
            \RuntimeException $e
        ) {
            $this->handleRuntimeException($e);
        }

        $this->json(200, ['success' => true, 'data' => $items]);
    }

    // ── POST /api/v1/courses/{id}/question-bank/generate ────────────────────

    public function generate(int $courseId): void
    {
        $user = AuthMiddleware::require();
        CsrfMiddleware::verify();

        $body = $this->jsonBody();

        try {
            $result = $this->service->generateFromNotes($courseId, (int) $user['id'], $body);
        } catch (
            \RuntimeException $e
        ) {
            $this->handleRuntimeException($e);
        }

        $this->json(201, [
            'success' => true,
            'data' => $result,
            'message' => t('question.bank.generated'),
        ]);
    }

    // ── POST /api/v1/questions/{id}/bank ────────────────────────────────────

    public function saveQuestion(int $questionId): void
    {
        $user = AuthMiddleware::require();
        CsrfMiddleware::verify();

        try {
            $id = $this->service->saveQuestion($questionId, (int) $user['id']);
        } catch (
            \RuntimeException $e
        ) {
            $this->handleRuntimeException($e);
        }

        $this->json(201, [
            'success' => true,
            'data' => ['id' => $id],
            'message' => t('question.bank.saved'),
        ]);
    }

    // ── POST /api/v1/sessions/{id}/questions/from-bank ──────────────────────

    public function importToSession(int $sessionId): void
    {
        $user = AuthMiddleware::require();
        CsrfMiddleware::verify();

        $body = $this->jsonBody();

        try {
            $result = $this->service->copyToSession($sessionId, (int) $user['id'], $body['bank_question_ids'] ?? []);
        } catch (
            \RuntimeException $e
        ) {
            $this->handleRuntimeException($e);
        }

        $this->json(201, [
            'success' => true,
            'data' => $result,
            'message' => t('question.bank.copied'),
        ]);
    }
}
