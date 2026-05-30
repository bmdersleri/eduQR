<?php

declare(strict_types=1);

namespace EduQR\Services;

use EduQR\Contracts\CourseRepositoryInterface;
use EduQR\Contracts\OptionRepositoryInterface;
use EduQR\Contracts\QuestionRepositoryInterface;
use EduQR\Contracts\SessionRepositoryInterface;
use EduQR\Support\Database;
use PDO;

/**
 * Phase 8 — Live Results (T-801, T-802)
 *
 * Provides two aggregate queries:
 *  - aggregate()          counts + percentages per option (T-801)
 *  - openTextAnswers()    answer list with nickname + timestamp (T-802)
 *
 * Also guards student visibility via show_results flags (T-804).
 */
final class ReportService
{
    public function __construct(
        private readonly SessionRepositoryInterface  $sessions,
        private readonly QuestionRepositoryInterface $questions,
        private readonly OptionRepositoryInterface   $options,
        private readonly CourseRepositoryInterface   $courses,
        private readonly ?PDO                        $pdo = null,
    ) {
    }

    // ── Instructor: get full results for a question (T-803) ───────────────────

    /**
     * Returns aggregated results that an instructor can always see.
     *
     * @throws \RuntimeException  session_not_found | forbidden | question_not_found
     */
    public function getResults(int $sessionId, int $userId, ?int $questionId): array
    {
        $session = $this->requireSession($sessionId, $userId);

        if ($questionId === null) {
            // Return results for every question in the session
            $qs = $this->questions->findBySession($sessionId);
        } else {
            $q = $this->questions->findById($questionId);
            if ($q === null || (int) $q['session_id'] !== $sessionId) {
                throw new \RuntimeException('question_not_found');
            }
            $qs = [$q];
        }

        return array_map(fn (array $q) => $this->buildQuestionResults($q, false), $qs);
    }

    // ── Student: get results gated by show_results flags (T-804) ─────────────

    /**
     * Returns results only when the session AND question both allow it.
     *
     * @throws \RuntimeException  session_not_found | results_hidden | question_not_found
     */
    public function getStudentResults(string $shortCode, ?int $questionId): array
    {
        $session = $this->sessions->findByShortCode($shortCode);
        if ($session === null) {
            throw new \RuntimeException('session_not_found');
        }

        if (! (bool) $session['show_results_to_students']) {
            throw new \RuntimeException('results_hidden');
        }

        if ($questionId === null) {
            $qs = $this->questions->findBySession((int) $session['id']);
        } else {
            $q = $this->questions->findById($questionId);
            if ($q === null || (int) $q['session_id'] !== (int) $session['id']) {
                throw new \RuntimeException('question_not_found');
            }
            $qs = [$q];
        }

        // Filter: only questions that have show_results = 1
        $qs = array_filter($qs, fn (array $q) => (bool) $q['show_results']);

        return array_map(fn (array $q) => $this->buildQuestionResults($q, true), array_values($qs));
    }

    // ── Aggregate: counts + percentages per option (T-801) ────────────────────

    /**
     * Returns answer counts and percentages for option-based questions,
     * or a list of text answers for open_text questions.
     */
    public function aggregate(int $questionId): array
    {
        $question = $this->questions->findById($questionId);
        if ($question === null) {
            throw new \RuntimeException('question_not_found');
        }

        return $this->buildQuestionResults($question, false);
    }

    // ── Open-text answers with nickname + timestamp (T-802) ───────────────────

    /**
     * Returns open-text answers for a question.
     * If $hiddenOnly is false (default), returns all non-hidden answers.
     * If $includeHidden is true (instructor), returns all answers with is_hidden flag.
     */
    public function openTextAnswers(int $questionId, bool $includeHidden = false): array
    {
        $pdo = $this->pdo ?? Database::connection();

        $hiddenClause = $includeHidden ? '' : 'AND a.is_hidden = 0';

        $stmt = $pdo->prepare(
            "SELECT
                a.id,
                a.answer_text  AS text,
                a.is_hidden,
                a.created_at,
                p.nickname
             FROM answers a
             JOIN participants p ON p.id = a.participant_id
             WHERE a.question_id = ? {$hiddenClause}
             ORDER BY a.created_at ASC"
        );
        $stmt->execute([$questionId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    private function buildQuestionResults(array $question, bool $studentView): array
    {
        $qId = (int) $question['id'];
        $qType = $question['question_type'];

        $base = [
            'question_id' => $qId,
            'question_text' => $question['question_text'],
            'question_type' => $qType,
            'status' => $question['status'],
        ];

        if ($qType === 'open_text') {
            $rows = $this->openTextAnswers($qId, ! $studentView);

            return array_merge($base, [
                'answer_count' => count($rows),
                'answers' => array_map(fn (array $r) => [
                    'id' => (int) $r['id'],
                    'text' => $r['text'],
                    'nickname' => $r['nickname'],
                    'is_hidden' => (bool) $r['is_hidden'],
                    'created_at' => $r['created_at'],
                ], $rows),
            ]);
        }

        // Option-based: aggregate counts (T-801)
        return array_merge($base, $this->aggregateOptions($qId));
    }

    /**
     * Counts and percentage for each option of a question.
     *
     * Returns:
     *   answer_count  — total answers (excluding hidden)
     *   options       — [{id, text, value, order_no, count, percent}]
     */
    private function aggregateOptions(int $questionId): array
    {
        $pdo = $this->pdo ?? Database::connection();

        // Count per option (excluding hidden answers)
        $stmt = $pdo->prepare(
            'SELECT
                o.id,
                o.option_text,
                o.option_value,
                o.order_no,
                o.is_correct,
                COUNT(a.id) AS cnt
             FROM options o
             LEFT JOIN answers a
                    ON a.selected_option_id = o.id
                   AND a.is_hidden = 0
             WHERE o.question_id = ?
             GROUP BY o.id
             ORDER BY o.order_no ASC'
        );
        $stmt->execute([$questionId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $total = array_sum(array_column($rows, 'cnt'));

        $options = array_map(function (array $row) use ($total): array {
            $count = (int) $row['cnt'];
            $percent = $total > 0
                ? (float) round($count / $total * 100, 1)
                : 0.0;

            return [
                'id' => (int) $row['id'],
                'text' => $row['option_text'],
                'value' => $row['option_value'],
                'order_no' => (int) $row['order_no'],
                'is_correct' => (bool) $row['is_correct'],
                'count' => $count,
                'percent' => $percent,
            ];
        }, $rows);

        return [
            'answer_count' => (int) $total,
            'options' => $options,
        ];
    }

    // ── Full session report (T-900) ───────────────────────────────────────────

    /**
     * Builds a complete post-session report.
     *
     * @param  bool $anonymize  Replace nicknames with "Participant N" (FR-70)
     * @throws \RuntimeException  session_not_found | forbidden
     */
    public function buildReport(int $sessionId, int $userId, bool $anonymize = false): array
    {
        $session = $this->requireSession($sessionId, $userId);
        $course = $this->courses->findById((int) $session['course_id']);
        $pdo = $this->pdo ?? \EduQR\Support\Database::connection();

        // Participant count
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM participants WHERE session_id = ?');
        $stmt->execute([$sessionId]);
        $participantCount = (int) $stmt->fetchColumn();

        // All questions (any status — report shows closed history)
        $questions = $this->questions->findBySession($sessionId);

        // Total answer count across all questions
        $totalAnswers = 0;
        foreach ($questions as $q) {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM answers WHERE question_id = ? AND is_hidden = 0'
            );
            $stmt->execute([(int) $q['id']]);
            $totalAnswers += (int) $stmt->fetchColumn();
        }

        // Participation rate: avg(answerers per question / participant_count)
        // Simplified: total_answers / (question_count * participant_count), capped at 1.0
        $questionCount = count($questions);
        $participationRate = ($questionCount > 0 && $participantCount > 0)
            ? round(min(1.0, $totalAnswers / ($questionCount * $participantCount)), 4)
            : 0.0;

        // Build nickname → "Participant N" map for anonymization
        $nicknameMap = [];
        $counter = 1;

        $questionResults = [];
        foreach ($questions as $q) {
            $qId = (int) $q['id'];
            $qType = $q['question_type'];

            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM answers WHERE question_id = ? AND is_hidden = 0'
            );
            $stmt->execute([$qId]);
            $qAnswerCount = (int) $stmt->fetchColumn();

            $base = [
                'id' => $qId,
                'type' => $qType,
                'text' => $q['question_text'],
                'status' => $q['status'],
                'answer_count' => $qAnswerCount,
            ];

            if ($qType === 'open_text') {
                $rows = $this->openTextAnswers($qId, true); // instructor = include hidden
                $answers = [];
                foreach ($rows as $r) {
                    $nick = $r['nickname'];
                    if ($anonymize) {
                        if (! isset($nicknameMap[$nick])) {
                            $nicknameMap[$nick] = $counter++;
                        }
                        $nick = 'Participant ' . $nicknameMap[$nick];
                    }
                    $answers[] = [
                        'nickname' => $nick,
                        'answer_text' => $r['text'],
                        'is_hidden' => (bool) $r['is_hidden'],
                        'created_at' => $r['created_at'],
                    ];
                }
                $questionResults[] = array_merge($base, ['answers' => $answers]);
            } else {
                $agg = $this->aggregateOptions($qId);
                $questionResults[] = array_merge($base, [
                    'distribution' => array_map(fn (array $o) => [
                        'option_text' => $o['text'],
                        'count' => $o['count'],
                        'percentage' => $o['percent'],
                    ], $agg['options']),
                ]);
            }
        }

        $report = [
            'session' => [
                'id' => (int) $session['id'],
                'title' => $session['title'],
                'course_title' => $course['title'] ?? '',
                'language' => $session['language'],
                'started_at' => $session['started_at'],
                'closed_at' => $session['closed_at'],
                'anonymized' => (bool) $session['anonymized'],
                'is_quiz' => (bool) $session['is_quiz'],
            ],
            'summary' => [
                'participant_count' => $participantCount,
                'question_count' => $questionCount,
                'answer_count' => $totalAnswers,
                'participation_rate' => $participationRate,
            ],
            'questions' => $questionResults,
        ];

        if ((bool)$session['is_quiz']) {
            $scores = $this->computeScores($sessionId, $pdo);
            if ($anonymize) {
                foreach ($scores as &$scoreRow) {
                    $nick = $scoreRow['nickname'];
                    if (! isset($nicknameMap[$nick])) {
                        $nicknameMap[$nick] = $counter++;
                    }
                    $scoreRow['nickname'] = 'Participant ' . $nicknameMap[$nick];
                }
                unset($scoreRow);
            }
            $report['scores'] = $scores;
        }

        return $report;
    }

    private function computeScores(int $sessionId, PDO $pdo): array
    {
        $stmt = $pdo->prepare(
            'SELECT p.id AS participant_id, p.nickname, COUNT(o.id) AS score
             FROM participants p
             LEFT JOIN answers a ON a.participant_id = p.id
             LEFT JOIN options o ON o.id = a.selected_option_id AND o.is_correct = 1
             WHERE p.session_id = ?
             GROUP BY p.id, p.nickname
             ORDER BY score DESC'
        );
        $stmt->execute([$sessionId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $scores = [];
        $rank = 0;
        $prevScore = null;
        for ($i = 0; $i < count($rows); $i++) {
            $row = $rows[$i];
            $score = (int) $row['score'];
            if ($score !== $prevScore) {
                $rank = $i + 1;
                $prevScore = $score;
            }
            $scores[] = [
                'participant_id' => (int) $row['participant_id'],
                'nickname' => $row['nickname'],
                'score' => $score,
                'rank' => $rank,
            ];
        }

        return $scores;
    }

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
}
