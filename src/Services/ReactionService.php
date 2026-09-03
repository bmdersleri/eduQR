<?php

declare(strict_types=1);

namespace EduQR\Services;

use EduQR\Contracts\CourseRepositoryInterface;
use EduQR\Contracts\ParticipantRepositoryInterface;
use EduQR\Contracts\QuestionRepositoryInterface;
use EduQR\Contracts\ReactionRepositoryInterface;
use EduQR\Contracts\SessionRepositoryInterface;
use EduQR\Exceptions\AuthenticationException;
use EduQR\Exceptions\DomainException;
use EduQR\Exceptions\ForbiddenException;
use EduQR\Exceptions\NotFoundException;
use EduQR\Exceptions\ValidationException;

/**
 * Phase 11 — Student comprehension reactions (T-1105, FR-48)
 *
 * A live comprehension pulse: a student signals "got it" or "lost" on the
 * currently open question. Aggregates are instructor-only.
 *
 * Validation chain for POST /api/v1/reactions (mirrors AnswerService::submit):
 *  1. Participant cookie is present and participant exists
 *  2. Question exists and is currently active
 *  3. Session is active
 *  4. Participant belongs to the question's session
 *  5. Reaction value is one of the allowed enum members
 *  6. Upsert — replaces any reaction the participant already holds
 *
 * Reactions are neither results nor correctness, so exam_mode,
 * show_results_to_students and per-question show_results are deliberately
 * NOT consulted here — the student never receives the counts.
 */
final class ReactionService
{
    /** Allowed values of question_reactions.reaction */
    public const REACTIONS = ['got_it', 'lost'];

    public function __construct(
        private readonly ReactionRepositoryInterface    $reactions,
        private readonly QuestionRepositoryInterface    $questions,
        private readonly SessionRepositoryInterface     $sessions,
        private readonly ParticipantRepositoryInterface $participants,
        private readonly CourseRepositoryInterface      $courses,
    ) {
    }

    // ── Student: send a reaction ───────────────────────────────────────────────

    /**
     * @param  int    $participantId  Resolved from cookie by the controller
     * @param  array  $body           Decoded JSON body: question_id, reaction
     * @return string                 The stored reaction value
     *
     * @throws \InvalidArgumentException  Validation failure (400 / 422)
     * @throws DomainException          Not-found / state errors (404 / 422)
     *
     * @requirement FR-48
     */
    public function react(int $participantId, array $body): string
    {
        // ── 1. Resolve and validate participant ───────────────────────────────
        $participant = $this->participants->findById($participantId);
        if ($participant === null) {
            throw new AuthenticationException('participant_not_found', 401, 'not_joined');
        }

        // ── 2. Resolve and validate question ──────────────────────────────────
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

        // ── 3. Resolve and validate session ───────────────────────────────────
        $session = $this->sessions->findById((int) $question['session_id']);
        if ($session === null) {
            throw new NotFoundException('session_not_found');
        }

        if ($session['status'] !== 'active') {
            $code = $session['status'] === 'paused' ? 'session_paused' : 'session_closed';

            throw new ValidationException($code);
        }

        // ── 4. Participant belongs to the question's session ──────────────────
        if ((int) $participant['session_id'] !== (int) $session['id']) {
            throw new ForbiddenException('forbidden');
        }

        // ── 5. Validate the reaction value ────────────────────────────────────
        $reaction = $this->validateReaction($body);

        // ── 6. Upsert — one reaction per participant per question ─────────────
        $this->reactions->upsert(
            (int) $session['id'],
            (int) $question['id'],
            $participantId,
            $reaction
        );

        return $reaction;
    }

    // ── Instructor: aggregate counts per question ──────────────────────────────

    /**
     * Per-question got_it / lost counts for one session.
     * Every question in the session is returned, zero-filled when unreacted.
     *
     * @return list<array{question_id:int,got_it:int,lost:int}>
     *
     * @throws DomainException  session_not_found | course_not_found | forbidden
     *
     * @requirement FR-48
     */
    public function aggregatesForSession(int $sessionId, int $userId): array
    {
        $this->requireSession($sessionId, $userId);

        $counts = [];
        foreach ($this->reactions->aggregateBySession($sessionId) as $row) {
            $counts[(int) $row['question_id']] = [
                'got_it' => (int) $row['got_it'],
                'lost' => (int) $row['lost'],
            ];
        }

        $out = [];
        foreach ($this->questions->findBySession($sessionId) as $question) {
            $questionId = (int) $question['id'];
            $out[] = [
                'question_id' => $questionId,
                'got_it' => $counts[$questionId]['got_it'] ?? 0,
                'lost' => $counts[$questionId]['lost'] ?? 0,
            ];
        }

        return $out;
    }

    // ── Validation ─────────────────────────────────────────────────────────────

    /**
     * @throws \InvalidArgumentException  missing or unknown reaction value
     */
    public function validateReaction(array $body): string
    {
        if (! isset($body['reaction']) || ! is_string($body['reaction']) || $body['reaction'] === '') {
            throw new \InvalidArgumentException('reaction:required');
        }

        if (! in_array($body['reaction'], self::REACTIONS, true)) {
            throw new \InvalidArgumentException('reaction:invalid');
        }

        return $body['reaction'];
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    /**
     * Access check — identical to the other instructor session endpoints: the
     * caller must own or co-instruct the course the session belongs to (FR-97).
     */
    private function requireSession(int $sessionId, int $userId): array
    {
        $session = $this->sessions->findById($sessionId);
        if ($session === null) {
            throw new NotFoundException('session_not_found');
        }

        $courseId = (int) $session['course_id'];
        $course = $this->courses->findById($courseId);
        if ($course === null) {
            throw new NotFoundException('course_not_found');
        }
        // Owner or co-instructor (FR-97).
        if ($this->courses->roleFor($courseId, $userId) === null) {
            throw new ForbiddenException('forbidden');
        }

        return $session;
    }
}
