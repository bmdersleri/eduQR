<?php

declare(strict_types=1);

namespace EduQR\Services;

use EduQR\Contracts\CourseRepositoryInterface;
use EduQR\Contracts\QuestionRepositoryInterface;
use EduQR\Contracts\SessionRepositoryInterface;
use EduQR\Exceptions\DomainException;
use EduQR\Exceptions\ForbiddenException;
use EduQR\Exceptions\NotFoundException;
use EduQR\Exceptions\ValidationException;
use PDO;

/**
 * What a polled endpoint's answer depends on, cheaply — NFR-76.
 *
 * Five screens re-ask the same five endpoints every two to five seconds, and in
 * a lecture hall most of those answers are byte-for-byte the answer the browser
 * already has. NFR-76 says such a poll is answered `304 Not Modified` *without
 * recomputing aggregates*, which only helps if deciding costs less than
 * answering. So each method here returns an opaque version string built from
 * exactly the columns API_SPEC.md §1.9 names for that endpoint — counts,
 * maximum ids and timestamps — and never assembles the row set the body is
 * made of.
 *
 * Two rules shape the class:
 *
 * 1. **Authorization happens before the version is computed, not after.** Every
 *    method below runs the same guard the service behind the endpoint runs, and
 *    throws the same typed failure. A caller who would be answered 403 or 404
 *    must be answered 403 or 404 whatever `If-None-Match` they send; a `304`
 *    that leaks "this session exists and is unchanged" is still a leak.
 * 2. **No cache.** The version is recomputed per request. A cache would need
 *    invalidation, and invalidation is the problem a version query avoids.
 *
 * The session and course guards duplicate the ones in QuestionService and
 * ResultsService rather than sharing them, for the reason NFR-82 gives: the
 * split units keep their own copies so that one of them changing its rule does
 * not silently change everyone else's.
 *
 * @requirement NFR-76
 */
final class PollVersionService
{
    public function __construct(
        private readonly SessionRepositoryInterface $sessions,
        private readonly QuestionRepositoryInterface $questions,
        private readonly CourseRepositoryInterface $courses,
        private readonly PDO $pdo,
    ) {
    }

    // ── GET /api/v1/sessions/{short_code}/active-question ─────────────────────

    /**
     * The session's status and `updated_at`, plus the active question's id,
     * status, `activated_at` and `updated_at` (API_SPEC.md §1.9).
     *
     * No new SQL: both rows are already single-row lookups by short code, and
     * the columns §1.9 names are columns those two lookups return. What the
     * version skips is the third query — the options — and the payload the
     * service assembles from all three.
     *
     * The version is not participant-specific because the body is not. The
     * `already_answered` member is hardcoded `false` today; if it ever becomes
     * the truth, the participant belongs in this string too.
     *
     * @throws DomainException session_not_found | session_closed
     */
    public function activeQuestionVersion(string $shortCode): string
    {
        $session = $this->sessions->findByShortCode($shortCode);

        if ($session === null) {
            throw new NotFoundException('session_not_found');
        }

        if ($session['status'] === 'closed') {
            throw new ValidationException('session_closed', 410);
        }

        $question = $this->questions->findActiveBySessionCode($shortCode);

        return self::join([
            'session',
            (string) $session['status'],
            (string) ($session['updated_at'] ?? ''),
            'question',
            $question === null ? 'none' : (string) (int) $question['id'],
            $question === null ? '' : (string) ($question['status'] ?? ''),
            $question === null ? '' : (string) ($question['activated_at'] ?? ''),
            $question === null ? '' : (string) ($question['updated_at'] ?? ''),
        ]);
    }

    // ── GET /api/v1/sessions/{id}/results ─────────────────────────────────────

    /**
     * Per question in scope: its status and `updated_at`; over its answers,
     * `COUNT(*)`, `MAX(id)` and `SUM(is_hidden)` (API_SPEC.md §1.9).
     *
     * `SUM(is_hidden)` is not redundant. The answers table has `created_at` and
     * no `updated_at`, so moderating an answer out of view changes neither the
     * count nor the maximum id — without the sum, an instructor who hid an
     * answer would keep being handed the response that still contains it.
     *
     * Two statements, neither of which joins: the questions in scope by their
     * cheap columns, then one grouped read over their answers. The body builds
     * per-option aggregates, word clouds and themes for every one of them.
     *
     * @throws DomainException session_not_found | course_not_found | forbidden | question_not_found
     */
    public function resultsVersion(int $sessionId, int $userId, ?int $questionId): string
    {
        $this->requireSession($sessionId, $userId);

        $questions = $questionId === null
            ? $this->questionVersionsInSession($sessionId)
            : [$this->questionVersion($questionId, $sessionId)];

        $answers = $this->answerVersionsFor(array_map(
            static fn (array $q): int => (int) $q['id'],
            $questions,
        ));

        $parts = [];

        foreach ($questions as $question) {
            $id = (int) $question['id'];
            $counts = $answers[$id] ?? ['answers' => 0, 'max_id' => 0, 'hidden' => 0];

            $parts[] = self::join([
                (string) $id,
                (string) ($question['status'] ?? ''),
                (string) ($question['updated_at'] ?? ''),
                (string) $counts['answers'],
                (string) $counts['max_id'],
                (string) $counts['hidden'],
            ]);
        }

        return implode(';', $parts);
    }

    // ── Version queries ───────────────────────────────────────────────────────

    /**
     * The cheap columns of every question in a session, in id order.
     *
     * Read straight from the table rather than through
     * QuestionRepository::findBySession(), which selects `*`: the point of a
     * version query is not to be the body query.
     *
     * @return list<array{id: int, status: string|null, updated_at: string|null}>
     */
    private function questionVersionsInSession(int $sessionId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, status, updated_at FROM questions WHERE session_id = ? ORDER BY id'
        );
        $statement->execute([$sessionId]);

        /** @var list<array{id: int, status: string|null, updated_at: string|null}> */
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The same columns for one question, which must belong to this session.
     *
     * The session is part of the WHERE clause rather than a check afterwards so
     * that a question id from another session is indistinguishable from a
     * question id that does not exist — which is what getResults() answers.
     *
     * @return array{id: int, status: string|null, updated_at: string|null}
     *
     * @throws DomainException question_not_found
     */
    private function questionVersion(int $questionId, int $sessionId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, status, updated_at FROM questions WHERE id = ? AND session_id = ?'
        );
        $statement->execute([$questionId, $sessionId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new NotFoundException('question_not_found');
        }

        /** @var array{id: int, status: string|null, updated_at: string|null} */
        return $row;
    }

    /**
     * `COUNT(*)`, `MAX(id)` and `SUM(is_hidden)` over the answers of each
     * question, keyed by question id.
     *
     * One statement for the whole set, with one placeholder per id (Law 2).
     *
     * @param list<int> $questionIds
     *
     * @return array<int, array{answers: int, max_id: int, hidden: int}>
     */
    private function answerVersionsFor(array $questionIds): array
    {
        if ($questionIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, \count($questionIds), '?'));

        $statement = $this->pdo->prepare(
            'SELECT question_id,
                    COUNT(*)        AS answer_count,
                    MAX(id)         AS max_id,
                    SUM(is_hidden)  AS hidden_count
               FROM answers
              WHERE question_id IN (' . $placeholders . ')
              GROUP BY question_id'
        );
        $statement->execute($questionIds);

        $versions = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $versions[(int) $row['question_id']] = [
                'answers' => (int) $row['answer_count'],
                'max_id' => (int) $row['max_id'],
                'hidden' => (int) $row['hidden_count'],
            ];
        }

        return $versions;
    }

    // ── Guards (NFR-82: deliberately duplicated) ──────────────────────────────

    /**
     * @return array<string, mixed>
     *
     * @throws DomainException session_not_found | course_not_found | forbidden
     */
    private function requireSession(int $sessionId, int $userId): array
    {
        $session = $this->sessions->findById($sessionId);

        if ($session === null) {
            throw new NotFoundException('session_not_found');
        }

        $this->requireCourse((int) $session['course_id'], $userId);

        return $session;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws DomainException course_not_found | forbidden
     */
    private function requireCourse(int $courseId, int $userId): array
    {
        $course = $this->courses->findById($courseId);

        if ($course === null) {
            throw new NotFoundException('course_not_found');
        }

        // Owner or co-instructor (FR-97), the same rule ResultsService applies.
        if ($this->courses->roleFor($courseId, $userId) === null) {
            throw new ForbiddenException('forbidden');
        }

        return $course;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * The version string is opaque, so the separator only has to be a character
     * no status or timestamp contains.
     *
     * @param list<string> $parts
     */
    private static function join(array $parts): string
    {
        return implode('|', $parts);
    }
}
