<?php

declare(strict_types=1);

namespace EduQR\Controllers\Api;

use EduQR\Middleware\AuthMiddleware;
use EduQR\Middleware\CsrfMiddleware;
use EduQR\Repositories\AuditLogRepository;
use EduQR\Repositories\CourseRepository;
use EduQR\Repositories\OptionRepository;
use EduQR\Repositories\QuestionRepository;
use EduQR\Repositories\SessionRepository;
use EduQR\Services\QuestionService;

final class QuestionController
{
    private QuestionService $service;
    private AuditLogRepository $auditLog;

    public function __construct()
    {
        $this->service = new QuestionService(
            new QuestionRepository(),
            new OptionRepository(),
            new SessionRepository(),
            new CourseRepository(),
        );
        $this->auditLog = new AuditLogRepository();
    }

    // ── GET /api/v1/sessions/{id}/questions ───────────────────────────────────

    public function index(int $sessionId): void
    {
        $user = AuthMiddleware::require();

        try {
            $questions = $this->service->list($sessionId, (int) $user['id']);
        } catch (\RuntimeException $e) {
            $this->handleRuntimeException($e);
        }

        $this->json(200, ['success' => true, 'data' => $questions]);
    }

    // ── POST /api/v1/sessions/{id}/questions ──────────────────────────────────

    public function create(int $sessionId): void
    {
        $user = AuthMiddleware::require();
        CsrfMiddleware::verify();

        $body = $this->jsonBody();

        try {
            $id = $this->service->create($sessionId, (int) $user['id'], $body);
        } catch (\RuntimeException $e) {
            $this->handleRuntimeException($e);
        } catch (\InvalidArgumentException $e) {
            $this->handleValidationException($e);
        }

        $this->json(201, [
            'success' => true,
            'data' => ['id' => $id],
            'message' => t('question.created'),
        ]);
    }

    // ── POST /api/v1/sessions/{id}/questions/import ─────────────────────────

    public function import(int $sessionId): void
    {
        $user = AuthMiddleware::require();
        CsrfMiddleware::verify();

        $body = $this->jsonBody();
        $items = $this->normalizeImportPayload($body);

        try {
            $ids = $this->service->createMany($sessionId, (int) $user['id'], $items);
        } catch (\RuntimeException $e) {
            $this->handleRuntimeException($e);
        } catch (\InvalidArgumentException $e) {
            $this->handleValidationException($e);
        }

        $this->json(201, [
            'success' => true,
            'data' => ['ids' => $ids, 'count' => count($ids)],
            'message' => t('question.imported'),
        ]);
    }

    /**
     * Supports two formats:
     * 1) {"questions":[...]}
     * 2) {
     *      "course_name":"...",
     *      "topic_name":"...",
     *      "sections":{
     *        "opening":[...],
     *        "middle":[...],
     *        "closing":[...]
     *      }
     *    }
     *
     * Questions are ordered as opening -> middle -> closing.
     */
    private function normalizeImportPayload(array $body): array
    {
        $opening = [];
        $middle = [];
        $closing = [];
        $hasQuestions = isset($body['questions']);
        $hasSections = isset($body['sections']);

        if (! $hasQuestions && ! $hasSections) {
            throw new \InvalidArgumentException('import:invalid_payload');
        }

        if ($hasQuestions) {
            if (! is_array($body['questions'])) {
                throw new \InvalidArgumentException('import:invalid_payload');
            }

            foreach ($body['questions'] as $row) {
                if (! is_array($row)) {
                    throw new \InvalidArgumentException('import:invalid_payload');
                }

                $stage = $row['stage'] ?? 'middle';
                if (! in_array($stage, ['opening', 'middle', 'closing'], true)) {
                    $stage = 'middle';
                }
                $row['stage'] = $stage;
                if ($stage === 'opening') {
                    $opening[] = $row;
                } elseif ($stage === 'middle') {
                    $middle[] = $row;
                } else {
                    $closing[] = $row;
                }
            }
        }

        if ($hasSections) {
            $sections = $body['sections'];
            if (! is_array($sections)) {
                throw new \InvalidArgumentException('import:invalid_payload');
            }

            foreach (array_keys($sections) as $key) {
                if (! in_array($key, ['opening', 'middle', 'closing'], true)) {
                    throw new \InvalidArgumentException('import:invalid_payload');
                }
            }

            if ((isset($sections['opening']) && ! is_array($sections['opening'])) ||
                (isset($sections['middle']) && ! is_array($sections['middle'])) ||
                (isset($sections['closing']) && ! is_array($sections['closing']))) {
                throw new \InvalidArgumentException('import:invalid_payload');
            }

            $courseName = trim((string) ($body['course_name'] ?? ''));
            $topicName = trim((string) ($body['topic_name'] ?? ''));

            $ordered = [
                'opening' => $sections['opening'] ?? [],
                'middle' => $sections['middle'] ?? [],
                'closing' => $sections['closing'] ?? [],
            ];

            foreach ($ordered as $phase => $rows) {
                foreach ($rows as $row) {
                    if (! is_array($row)) {
                        throw new \InvalidArgumentException('import:invalid_payload');
                    }

                    $text = trim((string) ($row['question_text'] ?? ''));
                    if ($text === '') {
                        throw new \InvalidArgumentException('question_text:required');
                    }

                    $prefixParts = [];
                    if ($courseName !== '') {
                        $prefixParts[] = $courseName;
                    }
                    if ($topicName !== '') {
                        $prefixParts[] = $topicName;
                    }
                    $phaseLabel = match ($phase) {
                        'opening' => 'Acilis',
                        'middle' => 'Orta',
                        default => 'Kapanis',
                    };
                    $prefixParts[] = $phaseLabel;

                    $row['question_text'] = '[' . implode(' | ', $prefixParts) . '] ' . $text;
                    $row['stage'] = $phase;

                    if ($phase === 'opening') {
                        $opening[] = $row;
                    } elseif ($phase === 'middle') {
                        $middle[] = $row;
                    } else {
                        $closing[] = $row;
                    }
                }
            }
        }

        $result = array_merge($opening, $middle, $closing);
        if (count($result) === 0) {
            throw new \InvalidArgumentException('questions:required');
        }

        return $result;
    }

    // ── PATCH /api/v1/questions/{id} ──────────────────────────────────────────

    public function update(int $questionId): void
    {
        $user = AuthMiddleware::require();
        CsrfMiddleware::verify();

        $body = $this->jsonBody();

        try {
            $this->service->update($questionId, (int) $user['id'], $body);
        } catch (\RuntimeException $e) {
            $this->handleRuntimeException($e);
        } catch (\InvalidArgumentException $e) {
            $this->handleValidationException($e);
        }

        $this->json(200, ['success' => true, 'data' => null, 'message' => t('common.success')]);
    }

    // ── POST /api/v1/questions/{id}/activate ──────────────────────────────────

    public function activate(int $questionId): void
    {
        $user = AuthMiddleware::require();
        CsrfMiddleware::verify();

        try {
            $this->service->activate($questionId, (int) $user['id']);
        } catch (\RuntimeException $e) {
            $this->handleRuntimeException($e);
        }

        // Audit: FR-90
        try {
            $this->auditLog->write('instructor', (int) $user['id'], 'question.activate', 'question', $questionId);
        } catch (\Throwable) {
        }

        $this->json(200, [
            'success' => true,
            'data' => null,
            'message' => t('question.activated'),
        ]);
    }

    // ── POST /api/v1/questions/{id}/close ─────────────────────────────────────

    public function close(int $questionId): void
    {
        $user = AuthMiddleware::require();
        CsrfMiddleware::verify();

        try {
            $this->service->close($questionId, (int) $user['id']);
        } catch (\RuntimeException $e) {
            $this->handleRuntimeException($e);
        }

        $this->json(200, [
            'success' => true,
            'data' => null,
            'message' => t('question.closed'),
        ]);
    }

    // ── DELETE /api/v1/questions/{id} ─────────────────────────────────────────

    public function destroy(int $questionId): void
    {
        $user = AuthMiddleware::require();
        CsrfMiddleware::verify();

        try {
            $this->service->delete($questionId, (int) $user['id']);
        } catch (\RuntimeException $e) {
            $this->handleRuntimeException($e);
        }

        $this->json(200, ['success' => true, 'data' => null]);
    }

    // ── POST /api/v1/sessions/{id}/questions/reorder ──────────────────────────

    public function reorder(int $sessionId): void
    {
        $user = AuthMiddleware::require();
        CsrfMiddleware::verify();

        $body = $this->jsonBody();

        try {
            $this->service->reorder($sessionId, (int) $user['id'], $body['order'] ?? []);
        } catch (\RuntimeException $e) {
            $this->handleRuntimeException($e);
        } catch (\InvalidArgumentException $e) {
            $this->handleValidationException($e);
        }

        $this->json(200, ['success' => true, 'data' => null]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function handleRuntimeException(\RuntimeException $e): never
    {
        match ($e->getMessage()) {
            'question_not_found' => $this->error(404, 'question_not_found', t('error.question_not_found')),
            'session_not_found' => $this->error(404, 'session_not_found', t('error.session_not_found')),
            'forbidden' => $this->error(403, 'forbidden', t('error.forbidden')),
            'question_not_draft' => $this->error(422, 'invalid_state_transition', t('error.invalid_state_transition')),
            'invalid_state_transition' => $this->error(422, 'invalid_state_transition', t('error.invalid_state_transition')),
            'session_not_active' => $this->error(422, 'session_not_active', t('error.session_not_active')),
            default => $this->error(500, 'server_error', t('error.server_error')),
        };
    }

    private function handleValidationException(\InvalidArgumentException $e): never
    {
        match ($e->getMessage()) {
            'question_text:required' => $this->error(400, 'missing_fields', t('validation.required'), 'question_text'),
            'question_text:too_long' => $this->error(400, 'validation_error', t('validation.text_too_long'), 'question_text'),
            'question_type:invalid' => $this->error(422, 'invalid_question_type', t('common.error')),
            'options:invalid_count' => $this->error(422, 'invalid_option_count', t('validation.invalid_option_count'), 'options'),
            'options:empty_text' => $this->error(400, 'validation_error', t('validation.required'), 'options'),
            'options:text_too_long' => $this->error(400, 'validation_error', t('validation.text_too_long'), 'options'),
            'order:required' => $this->error(400, 'missing_fields', t('validation.required'), 'order'),
            'questions:required' => $this->error(400, 'missing_fields', t('validation.required'), 'questions'),
            'import:invalid_payload' => $this->error(400, 'invalid_import_payload', t('error.invalid_import_payload')),
            'stage:invalid' => $this->error(422, 'invalid_stage', t('common.error')),
            default => $this->error(400, 'validation_error', t('common.error')),
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
