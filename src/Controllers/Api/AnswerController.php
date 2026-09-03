<?php

declare(strict_types=1);

namespace EduQR\Controllers\Api;

use EduQR\Container;
use EduQR\Controllers\ApiController;
use EduQR\Exceptions\DuplicateAnswerException;
use EduQR\Middleware\RateLimitMiddleware;
use EduQR\Services\AnswerService;

/**
 * POST /api/v1/answers — student submits an answer (T-703)
 *
 * Auth: participant cookie (eduqr_participant), not instructor session.
 */
final class AnswerController extends ApiController
{
    private AnswerService $service;

    public function __construct()
    {
        $this->service = Container::answerService();
    }

    // ── POST /api/v1/answers ──────────────────────────────────────────────────

    public function submit(): void
    {
        // No CSRF token required for student JSON API — they are not logged-in instructors.
        // The participant cookie + duplicate-check already provide replay protection.

        // Rate limit: max 60 answer submissions per IP per minute (SEC §14)
        $ipHash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|answer');
        RateLimitMiddleware::check("answer:{$ipHash}", 60, 60);

        $participantId = $this->resolveParticipant();
        $body = $this->jsonBody();

        try {
            $answerId = $this->service->submit($participantId, $body);
        } catch (DuplicateAnswerException) {
            $this->error(409, 'duplicate_answer', t('error.duplicate_answer'));
        } catch (\RuntimeException $e) {
            $this->handleRuntimeException($e);
        } catch (\InvalidArgumentException $e) {
            $this->handleValidationException($e);
        }

        $this->json(201, [
            'success' => true,
            'data' => ['id' => $answerId],
            'message' => t('student.answer.submitted'),
        ]);
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

    private function handleValidationException(\InvalidArgumentException $e): never
    {
        match ($e->getMessage()) {
            'question_id:required' => $this->error(400, 'missing_fields', t('validation.required'), 'question_id'),
            'selected_option_id:required' => $this->error(400, 'missing_fields', t('validation.required'), 'selected_option_id'),
            'answer_text:required' => $this->error(400, 'missing_fields', t('validation.required'), 'answer_text'),
            'answer_text:too_long' => $this->error(400, 'validation_error', t('validation.text_too_long'), 'answer_text'),
            'answer:invalid_shape' => $this->error(422, 'invalid_answer_shape', t('error.invalid_answer_shape')),
            default => $this->error(400, 'validation_error', t('common.error')),
        };
    }
}
