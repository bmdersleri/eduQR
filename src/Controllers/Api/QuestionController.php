<?php

declare(strict_types=1);

namespace EduQR\Controllers\Api;

use EduQR\Container;
use EduQR\Contracts\AuditLogRepositoryInterface;
use EduQR\Controllers\ApiController;
use EduQR\Exceptions\ValidationException;
use EduQR\Middleware\AuthMiddleware;
use EduQR\Middleware\CsrfMiddleware;
use EduQR\Services\QuestionService;

final class QuestionController extends ApiController
{
    private QuestionService $service;
    private AuditLogRepositoryInterface $auditLog;

    public function __construct(
        ?QuestionService $service = null,
        ?AuditLogRepositoryInterface $auditLog = null
    ) {
        $this->service = $service ?? Container::questionService();
        $this->auditLog = $auditLog ?? Container::auditLogRepository();
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

        try {
            $items = $this->normalizeImportPayload($body);
            $ids = $this->service->createMany($sessionId, (int) $user['id'], $items);
        } catch (\RuntimeException $e) {
            $this->handleRuntimeException($e);
        }

        $this->json(201, [
            'success' => true,
            'data' => ['ids' => $ids, 'count' => count($ids)],
            'message' => t('question.imported'),
        ]);
    }

    /**
     * Normalizes and validates the question import payload.
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
            throw new ValidationException('invalid_import_payload', 400, 'invalid_import_payload');
        }

        if ($hasQuestions) {
            if (! is_array($body['questions'])) {
                throw new ValidationException('invalid_import_payload', 400, 'invalid_import_payload');
            }

            foreach ($body['questions'] as $row) {
                if (! is_array($row)) {
                    throw new ValidationException('invalid_import_payload', 400, 'invalid_import_payload');
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
                throw new ValidationException('invalid_import_payload', 400, 'invalid_import_payload');
            }

            foreach (array_keys($sections) as $key) {
                if (! in_array($key, ['opening', 'middle', 'closing'], true)) {
                    throw new ValidationException('invalid_import_payload', 400, 'invalid_import_payload');
                }
            }

            if ((isset($sections['opening']) && ! is_array($sections['opening'])) ||
                (isset($sections['middle']) && ! is_array($sections['middle'])) ||
                (isset($sections['closing']) && ! is_array($sections['closing']))) {
                throw new ValidationException('invalid_import_payload', 400, 'invalid_import_payload');
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
                        throw new ValidationException('invalid_import_payload', 400, 'invalid_import_payload');
                    }

                    $text = trim((string) ($row['question_text'] ?? ''));
                    if ($text === '') {
                        throw new ValidationException('required', 400, 'missing_fields', 'question_text');
                    }

                    $prefixParts = [];
                    if ($courseName !== '') {
                        $prefixParts[] = $courseName;
                    }
                    if ($topicName !== '') {
                        $prefixParts[] = $topicName;
                    }
                    $phaseLabel = match ($phase) {
                        'opening' => t('question.stage.opening'),
                        'middle' => t('question.stage.middle'),
                        default => t('question.stage.closing'),
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
            throw new ValidationException('required', 400, 'missing_fields', 'questions');
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
        }

        $this->json(200, ['success' => true, 'data' => null]);
    }

}
