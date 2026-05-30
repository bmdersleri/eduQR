<?php

declare(strict_types=1);

namespace EduQR\Services;

use EduQR\Contracts\CourseRepositoryInterface;
use EduQR\Contracts\OptionRepositoryInterface;
use EduQR\Contracts\QuestionRepositoryInterface;
use EduQR\Contracts\SessionRepositoryInterface;

final class QuestionService
{
    private const MAX_TEXT_LEN = 500;
    private const MAX_OPTION_LEN = 200;
    private const MC_MIN_OPTIONS = 2;
    private const MC_MAX_OPTIONS = 8;
    private const ALLOWED_TYPES = ['multiple_choice', 'open_text', 'yes_no', 'likert_5'];

    public function __construct(
        private readonly QuestionRepositoryInterface $questions,
        private readonly OptionRepositoryInterface   $options,
        private readonly SessionRepositoryInterface  $sessions,
        private readonly CourseRepositoryInterface   $courses,
    ) {
    }

    // ── Create (T-603–T-606) ───────────────────────────────────────────────────

    public function create(int $sessionId, int $userId, array $body): int
    {
        $session = $this->requireSession($sessionId, $userId);
        $language = $session['language'];

        $text = $this->validateText($body['question_text'] ?? null);
        $type = $this->validateType($body['question_type'] ?? null);
        $showResults = (bool) ($body['show_results'] ?? false);
        $allowMulti = (bool) ($body['allow_multiple_answers'] ?? false);

        $opts = $this->buildOptions($type, $body['options'] ?? [], $language);

        $questionId = $this->questions->create($sessionId, $text, $type, $showResults, $allowMulti);

        if (! empty($opts)) {
            $this->options->createBulk($questionId, $opts);
        }

        return $questionId;
    }

    // ── Update (draft only) ────────────────────────────────────────────────────

    public function update(int $questionId, int $userId, array $body): void
    {
        $question = $this->requireQuestion($questionId, $userId);

        if ($question['status'] !== 'draft') {
            throw new \RuntimeException('question_not_draft');
        }

        $fields = [];

        if (isset($body['question_text'])) {
            $fields['question_text'] = $this->validateText($body['question_text']);
        }
        if (array_key_exists('show_results', $body)) {
            $fields['show_results'] = $body['show_results'] ? 1 : 0;
        }
        if (array_key_exists('allow_multiple_answers', $body)) {
            $fields['allow_multiple_answers'] = $body['allow_multiple_answers'] ? 1 : 0;
        }

        if (! empty($fields)) {
            $this->questions->update($questionId, $fields);
        }

        if (isset($body['options']) && $question['question_type'] === 'multiple_choice') {
            $opts = $this->buildMultipleChoiceOptions((array) $body['options']);
            $this->options->deleteByQuestion($questionId);
            $this->options->createBulk($questionId, $opts);
        }
    }

    // ── Activate — one-active-question rule (FR-33) ───────────────────────────

    public function activate(int $questionId, int $userId): void
    {
        $question = $this->requireQuestion($questionId, $userId);

        if ($question['status'] === 'closed') {
            throw new \RuntimeException('invalid_state_transition');
        }

        $session = $this->sessions->findById((int) $question['session_id']);
        if ($session === null) {
            throw new \RuntimeException('session_not_found');
        }
        if ($session['status'] !== 'active') {
            throw new \RuntimeException('session_not_active');
        }

        $this->questions->activate($questionId, (int) $question['session_id']);
    }

    // ── Close ──────────────────────────────────────────────────────────────────

    public function close(int $questionId, int $userId): void
    {
        $question = $this->requireQuestion($questionId, $userId);

        if ($question['status'] !== 'active') {
            throw new \RuntimeException('invalid_state_transition');
        }

        $this->questions->close($questionId);
    }

    // ── Delete (draft only) ────────────────────────────────────────────────────

    public function delete(int $questionId, int $userId): void
    {
        $question = $this->requireQuestion($questionId, $userId);

        if ($question['status'] !== 'draft') {
            throw new \RuntimeException('question_not_draft');
        }

        $this->questions->delete($questionId);
    }

    // ── List ───────────────────────────────────────────────────────────────────

    public function list(int $sessionId, int $userId): array
    {
        $this->requireSession($sessionId, $userId);
        $qs = $this->questions->findBySession($sessionId);

        return array_map(function (array $q): array {
            $opts = $this->options->findByQuestion((int) $q['id']);

            return $this->questionPayload($q, $opts);
        }, $qs);
    }

    // ── Reorder (FR-35) ────────────────────────────────────────────────────────

    public function reorder(int $sessionId, int $userId, array $orderedIds): void
    {
        $this->requireSession($sessionId, $userId);

        if (empty($orderedIds)) {
            throw new \InvalidArgumentException('order:required');
        }

        $this->questions->reorder($sessionId, array_map('intval', $orderedIds));
    }

    // ── Public: get active question (FR-45) ────────────────────────────────────

    public function getActiveQuestionByCode(string $shortCode): ?array
    {
        $session = $this->sessions->findByShortCode($shortCode);
        if ($session === null) {
            throw new \RuntimeException('session_not_found');
        }
        if ($session['status'] === 'closed') {
            throw new \RuntimeException('session_closed');
        }

        $question = $this->questions->findActiveBySessionCode($shortCode);
        if ($question === null) {
            return null;
        }

        $opts = $this->options->findByQuestion((int) $question['id']);

        return [
            'id' => (int) $question['id'],
            'type' => $question['question_type'],
            'text' => $question['question_text'],
            'options' => array_map(fn ($o) => [
                'id' => (int) $o['id'],
                'text' => $o['option_text'],
            ], $opts),
            'activated_at' => $question['activated_at'],
            'already_answered' => false, // Phase 7: filled from answers table
        ];
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    private function requireSession(int $sessionId, int $userId): array
    {
        $session = $this->sessions->findById($sessionId);
        if ($session === null) {
            throw new \RuntimeException('session_not_found');
        }
        $course = $this->courses->findById((int) $session['course_id']);
        if ($course === null || (int) $course['instructor_id'] !== $userId) {
            throw new \RuntimeException('forbidden');
        }

        return $session;
    }

    private function requireQuestion(int $questionId, int $userId): array
    {
        $question = $this->questions->findById($questionId);
        if ($question === null) {
            throw new \RuntimeException('question_not_found');
        }
        $this->requireSession((int) $question['session_id'], $userId);

        return $question;
    }

    private function validateText(mixed $raw): string
    {
        $text = trim((string) $raw);
        if ($text === '') {
            throw new \InvalidArgumentException('question_text:required');
        }
        if (mb_strlen($text, 'UTF-8') > self::MAX_TEXT_LEN) {
            throw new \InvalidArgumentException('question_text:too_long');
        }

        return $text;
    }

    private function validateType(mixed $raw): string
    {
        $type = (string) $raw;
        if (! in_array($type, self::ALLOWED_TYPES, true)) {
            throw new \InvalidArgumentException('question_type:invalid');
        }

        return $type;
    }

    private function buildOptions(string $type, mixed $rawOptions, string $language): array
    {
        return match ($type) {
            'multiple_choice' => $this->buildMultipleChoiceOptions((array) $rawOptions),
            'yes_no' => $this->buildYesNoOptions($language),
            'likert_5' => $this->buildLikertOptions($language),
            default => [], // open_text
        };
    }

    private function buildMultipleChoiceOptions(array $rawOptions): array
    {
        $count = count($rawOptions);
        if ($count < self::MC_MIN_OPTIONS || $count > self::MC_MAX_OPTIONS) {
            throw new \InvalidArgumentException('options:invalid_count');
        }

        $result = [];
        foreach ($rawOptions as $i => $opt) {
            $text = trim((string) ($opt['option_text'] ?? ''));
            if ($text === '') {
                throw new \InvalidArgumentException('options:empty_text');
            }
            if (mb_strlen($text, 'UTF-8') > self::MAX_OPTION_LEN) {
                throw new \InvalidArgumentException('options:text_too_long');
            }
            $result[] = [
                'option_text' => $text,
                'option_value' => (string) ($i + 1),
                'is_correct' => 0,
                'order_no' => $i + 1,
            ];
        }

        return $result;
    }

    private function buildYesNoOptions(string $language): array
    {
        $labels = [
            'tr' => ['Evet', 'Hayır'],
            'en' => ['Yes', 'No'],
        ];
        [$yes, $no] = $labels[$language] ?? $labels['en'];

        return [
            ['option_text' => $yes, 'option_value' => 'yes', 'is_correct' => 0, 'order_no' => 1],
            ['option_text' => $no,  'option_value' => 'no',  'is_correct' => 0, 'order_no' => 2],
        ];
    }

    private function buildLikertOptions(string $language): array
    {
        $labels = [
            'tr' => [
                'Kesinlikle katılmıyorum',
                'Katılmıyorum',
                'Kararsızım',
                'Katılıyorum',
                'Kesinlikle katılıyorum',
            ],
            'en' => [
                'Strongly disagree',
                'Disagree',
                'Neutral',
                'Agree',
                'Strongly agree',
            ],
        ];
        $texts = $labels[$language] ?? $labels['en'];
        $result = [];

        foreach ($texts as $i => $text) {
            $result[] = [
                'option_text' => $text,
                'option_value' => (string) ($i + 1),
                'is_correct' => 0,
                'order_no' => $i + 1,
            ];
        }

        return $result;
    }

    private function questionPayload(array $question, array $options): array
    {
        return [
            'id' => (int) $question['id'],
            'question_text' => $question['question_text'],
            'question_type' => $question['question_type'],
            'status' => $question['status'],
            'order_no' => (int) $question['order_no'],
            'show_results' => (bool) $question['show_results'],
            'allow_multiple_answers' => (bool) $question['allow_multiple_answers'],
            'options' => array_map(fn ($o) => [
                'id' => (int) $o['id'],
                'option_text' => $o['option_text'],
                'option_value' => $o['option_value'],
                'order_no' => (int) $o['order_no'],
            ], $options),
            'activated_at' => $question['activated_at'],
            'closed_at' => $question['closed_at'],
            'created_at' => $question['created_at'],
        ];
    }
}
