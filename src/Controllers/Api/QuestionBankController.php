<?php

declare(strict_types=1);

namespace EduQR\Controllers\Api;

use EduQR\Contracts\QuestionGenerationServiceInterface;
use EduQR\Controllers\ApiController;
use EduQR\Middleware\AuthMiddleware;
use EduQR\Middleware\CsrfMiddleware;
use EduQR\Repositories\CourseRepository;
use EduQR\Repositories\OptionRepository;
use EduQR\Repositories\QuestionBankRepository;
use EduQR\Repositories\QuestionRepository;
use EduQR\Repositories\SessionRepository;
use EduQR\Services\QuestionBankService;
use EduQR\Services\QuestionGenerationService;
use EduQR\Services\QuestionService;

final class QuestionBankController extends ApiController
{
    private QuestionBankService $service;

    public function __construct(?QuestionGenerationServiceInterface $generator = null)
    {
        $questionRepo = new QuestionRepository();
        $optionRepo = new OptionRepository();
        $sessionRepo = new SessionRepository();
        $courseRepo = new CourseRepository();
        $questionService = new QuestionService($questionRepo, $optionRepo, $sessionRepo, $courseRepo);

        $this->service = new QuestionBankService(
            new QuestionBankRepository(),
            $questionRepo,
            $optionRepo,
            $sessionRepo,
            $courseRepo,
            $questionService,
            $generator ?? QuestionGenerationService::fromConfig(),
        );
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
        } catch (\InvalidArgumentException $e) {
            $this->handleValidationException($e);
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
        } catch (\InvalidArgumentException $e) {
            $this->handleValidationException($e);
        }

        $this->json(201, [
            'success' => true,
            'data' => $result,
            'message' => t('question.bank.copied'),
        ]);
    }

    private function handleValidationException(\InvalidArgumentException $e): never
    {
        match ($e->getMessage()) {
            'lecture_notes:required' => $this->error(400, 'missing_fields', t('validation.required'), 'lecture_notes'),
            'lecture_notes:too_long' => $this->error(400, 'validation_error', t('validation.text_too_long'), 'lecture_notes'),
            'bank_question_ids:required' => $this->error(400, 'missing_fields', t('validation.required'), 'bank_question_ids'),
            'question_text:required' => $this->error(400, 'missing_fields', t('validation.required'), 'question_text'),
            'question_text:too_long' => $this->error(400, 'validation_error', t('validation.text_too_long'), 'question_text'),
            'question_type:invalid' => $this->error(422, 'invalid_question_type', t('common.error'), 'question_type'),
            'options:invalid_count' => $this->error(422, 'invalid_option_count', t('validation.invalid_option_count'), 'options'),
            'options:empty_text' => $this->error(400, 'validation_error', t('validation.required'), 'options'),
            'options:text_too_long' => $this->error(400, 'validation_error', t('validation.text_too_long'), 'options'),
            default => $this->error(400, 'validation_error', t('common.error')),
        };
    }
}
