<?php

declare(strict_types=1);

namespace EduQR\Controllers\Api;

use EduQR\Middleware\CsrfMiddleware;
use EduQR\Middleware\RateLimitMiddleware;
use EduQR\Repositories\AnswerRepository;
use EduQR\Repositories\OptionRepository;
use EduQR\Repositories\ParticipantRepository;
use EduQR\Repositories\QuestionRepository;
use EduQR\Repositories\SessionRepository;
use EduQR\Services\AnswerService;
use EduQR\Services\DuplicateAnswerException;

/**
 * POST /api/v1/answers — student submits an answer (T-703)
 *
 * Auth: participant cookie (eduqr_participant), not instructor session.
 */
final class AnswerController
{
    private AnswerService $service;

    public function __construct()
    {
        $this->service = new AnswerService(
            new AnswerRepository(),
            new QuestionRepository(),
            new SessionRepository(),
            new ParticipantRepository(),
            new OptionRepository(),
        );
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
        $body          = $this->jsonBody();

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
            'data'    => ['id' => $answerId],
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

    private function handleRuntimeException(\RuntimeException $e): never
    {
        match ($e->getMessage()) {
            'participant_not_found' => $this->error(401, 'not_joined',          t('error.not_joined')),
            'question_not_found'    => $this->error(404, 'question_not_found',   t('error.question_not_found')),
            'question_not_active'   => $this->error(422, 'question_closed',      t('error.question_closed')),
            'session_not_found'     => $this->error(404, 'session_not_found',    t('error.session_not_found')),
            'session_paused'        => $this->error(422, 'session_paused',       t('error.session_paused')),
            'session_closed'        => $this->error(422, 'session_closed',       t('error.session_closed')),
            'forbidden'             => $this->error(403, 'forbidden',            t('error.forbidden')),
            'invalid_option'        => $this->error(422, 'invalid_option',       t('error.invalid_answer_shape')),
            default                 => $this->error(500, 'server_error',         t('error.server_error')),
        };
    }

    private function handleValidationException(\InvalidArgumentException $e): never
    {
        match ($e->getMessage()) {
            'question_id:required'      => $this->error(400, 'missing_fields',   t('validation.required'),  'question_id'),
            'selected_option_id:required' => $this->error(400, 'missing_fields', t('validation.required'),  'selected_option_id'),
            'answer_text:required'      => $this->error(400, 'missing_fields',   t('validation.required'),  'answer_text'),
            'answer_text:too_long'      => $this->error(400, 'validation_error', t('validation.text_too_long'), 'answer_text'),
            'answer:invalid_shape'      => $this->error(422, 'invalid_answer_shape', t('error.invalid_answer_shape')),
            default                     => $this->error(400, 'validation_error', t('common.error')),
        };
    }

    private function jsonBody(): array
    {
        return json_decode((string) file_get_contents('php://input'), true) ?? [];
    }

    private function json(int $status, array $payload): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }

    private function error(int $status, string $code, string $message, string $field = ''): never
    {
        $error = ['code' => $code, 'message' => $message];
        if ($field !== '') {
            $error['field'] = $field;
        }
        $this->json($status, ['success' => false, 'error' => $error]);
    }
}
