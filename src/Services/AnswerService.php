<?php

declare(strict_types=1);

namespace EduQR\Services;

use EduQR\Contracts\AnswerRepositoryInterface;
use EduQR\Contracts\OptionRepositoryInterface;
use EduQR\Contracts\ParticipantRepositoryInterface;
use EduQR\Contracts\QuestionRepositoryInterface;
use EduQR\Contracts\SessionRepositoryInterface;
use EduQR\Exceptions\AuthenticationException;
use EduQR\Exceptions\DomainException;
use EduQR\Exceptions\ForbiddenException;
use EduQR\Exceptions\NotFoundException;
use EduQR\Exceptions\ValidationException;
use PDOException;

/**
 * Phase 7 — Answer Collection (T-702..T-708)
 *
 * Validation chain for POST /api/v1/answers:
 *  1. Participant cookie is present and participant exists          (T-704)
 *  2. Question exists and is currently active                       (T-705)
 *  3. Session is active                                             (T-705)
 *  4. Participant belongs to the question's session                 (T-704)
 *  5. Answer shape matches question type                            (T-702)
 *     - option-based: selected_option_id present, belongs to question (T-706)
 *     - open_text:    answer_text present, sanitized, <= 2000 chars (T-707)
 *  6. Insert; catch SQLSTATE 23000 → 409 DuplicateAnswerException  (T-708)
 */
final class AnswerService
{
    private const MAX_OPEN_TEXT_LEN = 2000;

    // Question types that require a selected_option_id
    private const OPTION_TYPES = ['multiple_choice', 'yes_no', 'likert_5'];

    public function __construct(
        private readonly AnswerRepositoryInterface      $answers,
        private readonly QuestionRepositoryInterface    $questions,
        private readonly SessionRepositoryInterface     $sessions,
        private readonly ParticipantRepositoryInterface $participants,
        private readonly OptionRepositoryInterface      $options,
    ) {
    }

    // ── Submit answer ──────────────────────────────────────────────────────────

    /**
     * @param  int        $participantId    Resolved from cookie by the controller
     * @param  array      $body             Decoded JSON body: question_id, selected_option_id?, answer_text?
     * @return int                          New answer ID
     *
     * @throws \InvalidArgumentException    Validation failure (400 / 422)
     * @throws DuplicateAnswerException     Already answered this question (409)
     * @throws DomainException              Not-found / state errors (404 / 422)
     */
    public function submit(int $participantId, array $body): int
    {
        // ── 1. Resolve and validate participant (T-704) ────────────────────────
        $participant = $this->participants->findById($participantId);
        if ($participant === null) {
            throw new AuthenticationException('participant_not_found', 401, 'not_joined');
        }

        // ── 2. Resolve and validate question (T-705) ──────────────────────────
        $questionId = isset($body['question_id']) ? (int) $body['question_id'] : 0;
        if ($questionId <= 0) {
            throw new \InvalidArgumentException('question_id:required');
        }

        $question = $this->questions->findById($questionId);
        if ($question === null) {
            throw new NotFoundException('question_not_found');
        }

        if ($question['status'] !== 'active') {
            throw new ValidationException('question_not_active', 422, 'question_closed');
        }

        // ── 3. Resolve and validate session (T-705) ───────────────────────────
        $session = $this->sessions->findById((int) $question['session_id']);
        if ($session === null) {
            throw new NotFoundException('session_not_found');
        }

        if ($session['status'] !== 'active') {
            // Distinguish paused vs closed for better UX messages
            $code = $session['status'] === 'paused' ? 'session_paused' : 'session_closed';

            throw new ValidationException($code);
        }

        // ── 4. Participant belongs to the question's session (T-704) ──────────
        if ((int) $participant['session_id'] !== (int) $session['id']) {
            throw new ForbiddenException('forbidden');
        }

        // ── 5. Validate answer shape per question type (T-702, T-706, T-707) ──
        [$selectedOptionId, $answerText] = $this->validateAnswerShape(
            $question,
            $body
        );

        // ── 6. Insert; catch UNIQUE violation → 409 (T-708) ──────────────────
        try {
            return $this->answers->insert(
                (int) $question['id'],
                $participantId,
                $selectedOptionId,
                $answerText
            );
        } catch (PDOException $e) {
            // SQLSTATE 23000 = Integrity constraint violation (duplicate unique key)
            if ($e->getCode() === '23000') {
                throw new DuplicateAnswerException('duplicate_answer');
            }

            throw $e;
        }
    }

    // ── Public helper: check if participant already answered ───────────────────

    public function hasAnswered(int $participantId, int $questionId): bool
    {
        return $this->answers->existsByParticipantAndQuestion($participantId, $questionId);
    }

    // ── Validate answer shape (T-702) ──────────────────────────────────────────

    /**
     * Returns [?int $selectedOptionId, ?string $answerText] ready for insert.
     *
     * @throws \InvalidArgumentException  shape mismatch or constraint violation
     * @throws ValidationException       invalid_option (option not found / wrong question)
     */
    public function validateAnswerShape(array $question, array $body): array
    {
        $type = $question['question_type'];

        if (in_array($type, self::OPTION_TYPES, true)) {
            return $this->validateOptionAnswer($question, $body);
        }

        if ($type === 'open_text' || $type === 'fill_in_the_blank') {
            return $this->validateOpenTextAnswer($body);
        }

        // Unreachable for known types, but guard anyway
        throw new \InvalidArgumentException('answer:invalid_shape');
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    /**
     * Validate option-based answer types (multiple_choice, yes_no, likert_5).
     * Ensures selected_option_id is non-null and belongs to this question (T-706).
     */
    private function validateOptionAnswer(array $question, array $body): array
    {
        if (empty($body['selected_option_id'])) {
            throw new \InvalidArgumentException('selected_option_id:required');
        }

        $optionId = (int) $body['selected_option_id'];

        // Verify option belongs to this question (T-706)
        $option = $this->options->findById($optionId);
        if ($option === null || (int) $option['question_id'] !== (int) $question['id']) {
            throw new ValidationException('invalid_option');
        }

        // answer_text must be absent for option-based types
        if (! empty($body['answer_text'])) {
            throw new \InvalidArgumentException('answer:invalid_shape');
        }

        return [$optionId, null];
    }

    /**
     * Validate open-text answer: strip tags, enforce 2000-char cap (T-707, SEC §10).
     */
    private function validateOpenTextAnswer(array $body): array
    {
        if (! isset($body['answer_text'])) {
            throw new \InvalidArgumentException('answer_text:required');
        }

        // Strip HTML tags and trim whitespace (SEC §10)
        $text = trim(strip_tags((string) $body['answer_text']));

        if ($text === '') {
            throw new \InvalidArgumentException('answer_text:required');
        }

        if (mb_strlen($text, 'UTF-8') > self::MAX_OPEN_TEXT_LEN) {
            throw new \InvalidArgumentException('answer_text:too_long');
        }

        // selected_option_id must be absent for open_text
        if (! empty($body['selected_option_id'])) {
            throw new \InvalidArgumentException('answer:invalid_shape');
        }

        return [null, $text];
    }
}
