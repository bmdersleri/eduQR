<?php

declare(strict_types=1);

namespace EduQR\Services;

use EduQR\Contracts\CourseRepositoryInterface;
use EduQR\Contracts\OptionRepositoryInterface;
use EduQR\Contracts\QuestionBankRepositoryInterface;
use EduQR\Contracts\QuestionGenerationServiceInterface;
use EduQR\Contracts\QuestionRepositoryInterface;
use EduQR\Contracts\SessionRepositoryInterface;

final class QuestionBankService
{
    public function __construct(
        private readonly QuestionBankRepositoryInterface $bankItems,
        private readonly QuestionRepositoryInterface $questions,
        private readonly OptionRepositoryInterface $options,
        private readonly SessionRepositoryInterface $sessions,
        private readonly CourseRepositoryInterface $courses,
        private readonly QuestionService $questionService,
        private readonly QuestionGenerationServiceInterface $generator,
    ) {
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function list(int $courseId, int $userId): array
    {
        $this->requireCourse($courseId, $userId);

        return $this->bankItems->findByCourse($courseId);
    }

    public function saveQuestion(int $questionId, int $userId): int
    {
        $question = $this->requireQuestion($questionId, $userId);
        $session = $this->requireSession((int) $question['session_id'], $userId);
        $course = $this->requireCourse((int) $session['course_id'], $userId);

        return $this->bankItems->create(
            (int) $course['id'],
            $userId,
            'session_question',
            $this->questionPayload($question, $this->options->findByQuestion($questionId)),
            $session['title']
        );
    }

    /**
     * @return array{ids: array<int,int>, count: int}
     */
    public function generateFromNotes(int $courseId, int $userId, array $body): array
    {
        $course = $this->requireCourse($courseId, $userId);

        $lectureNotes = trim((string) ($body['lecture_notes'] ?? ''));
        if ($lectureNotes === '') {
            throw new \InvalidArgumentException('lecture_notes:required');
        }
        if (mb_strlen($lectureNotes, 'UTF-8') > 20000) {
            throw new \InvalidArgumentException('lecture_notes:too_long');
        }

        $sourceTitle = trim((string) ($body['source_title'] ?? ''));
        $topicName = trim((string) ($body['topic_name'] ?? ''));

        $generated = $this->generator->generateFromNotes(
            $course['title'],
            $topicName !== '' ? $topicName : null,
            $lectureNotes,
            (string) ($course['default_language'] ?? 'en')
        );

        $ids = [];
        foreach ($generated as $payload) {
            $ids[] = $this->bankItems->create(
                $courseId,
                $userId,
                'lecture_notes',
                $payload,
                $sourceTitle !== '' ? $sourceTitle : null
            );
        }

        return [
            'ids' => $ids,
            'count' => count($ids),
        ];
    }

    /**
     * @param array<int,int> $bankQuestionIds
     * @return array{ids: array<int,int>, count: int}
     */
    public function copyToSession(int $sessionId, int $userId, array $bankQuestionIds): array
    {
        $session = $this->requireSession($sessionId, $userId);

        if (empty($bankQuestionIds)) {
            throw new \InvalidArgumentException('bank_question_ids:required');
        }

        $requestedIds = array_values(array_filter(array_map('intval', $bankQuestionIds), static fn (int $id): bool => $id > 0));
        $items = $this->bankItems->findByIds((int) $session['course_id'], $requestedIds);
        if (count($items) !== count($requestedIds)) {
            throw new \RuntimeException('question_bank_not_found');
        }

        $byId = [];
        foreach ($items as $item) {
            $byId[(int) $item['id']] = $item;
        }

        $ids = [];
        foreach ($requestedIds as $bankId) {
            if (! isset($byId[$bankId])) {
                throw new \RuntimeException('question_bank_not_found');
            }

            $ids[] = $this->questionService->create($sessionId, $userId, $byId[$bankId]['question']);
        }

        return [
            'ids' => $ids,
            'count' => count($ids),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function requireCourse(int $courseId, int $userId): array
    {
        $course = $this->courses->findById($courseId);
        if ($course === null) {
            throw new \RuntimeException('course_not_found');
        }
        // Owner or co-instructor (FR-97).
        if ($this->courses->roleFor($courseId, $userId) === null) {
            throw new \RuntimeException('forbidden');
        }

        return $course;
    }

    /**
     * @return array<string,mixed>
     */
    private function requireSession(int $sessionId, int $userId): array
    {
        $session = $this->sessions->findById($sessionId);
        if ($session === null) {
            throw new \RuntimeException('session_not_found');
        }
        $this->requireCourse((int) $session['course_id'], $userId);

        return $session;
    }

    /**
     * @return array<string,mixed>
     */
    private function requireQuestion(int $questionId, int $userId): array
    {
        $question = $this->questions->findById($questionId);
        if ($question === null) {
            throw new \RuntimeException('question_not_found');
        }
        $this->requireSession((int) $question['session_id'], $userId);

        return $question;
    }

    /**
     * @param array<string,mixed> $question
     * @param array<int,array<string,mixed>> $options
     * @return array<string,mixed>
     */
    private function questionPayload(array $question, array $options): array
    {
        return [
            'question_text' => $question['question_text'],
            'question_type' => $question['question_type'],
            'show_results' => (bool) $question['show_results'],
            'allow_multiple_answers' => (bool) $question['allow_multiple_answers'],
            'stage' => $question['stage'] ?? 'middle',
            'options' => array_map(fn (array $option): array => [
                'option_text' => $option['option_text'],
                'option_value' => $option['option_value'],
                'is_correct' => (int) $option['is_correct'],
                'order_no' => (int) $option['order_no'],
            ], $options),
        ];
    }
}
