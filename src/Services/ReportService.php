<?php

declare(strict_types=1);

namespace EduQR\Services;

use EduQR\Contracts\CourseRepositoryInterface;
use EduQR\Contracts\OpenTextThemeExtractionServiceInterface;
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
 *  - buildWordCloud()     deterministic word cloud for open-text answers (FR-66)
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
        private readonly ?OpenTextThemeExtractionServiceInterface $themeExtractor = null,
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

        $language = (string) ($session['language'] ?? 'en');

        return array_map(fn (array $q) => $this->buildQuestionResults($q, false, $language), $qs);
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

        $language = (string) ($session['language'] ?? 'en');

        return array_map(fn (array $q) => $this->buildQuestionResults($q, true, $language), array_values($qs));
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

        $session = $this->sessions->findById((int) $question['session_id']) ?? ['language' => 'en'];

        return $this->buildQuestionResults($question, false, (string) ($session['language'] ?? 'en'));
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

    // ── AI-assisted theme extraction (FR-65) ─────────────────────────────────

    /**
     * Builds a set of AI-assisted themes from the visible open-text answers of a question.
     *
     * @throws \RuntimeException question_not_found | question_not_open_text | forbidden | llm_unavailable | invalid_llm_response
     */
    public function extractThemes(int $questionId, int $userId): array
    {
        $question = $this->requireQuestion($questionId, $userId);
        if (($question['question_type'] ?? '') !== 'open_text') {
            throw new \RuntimeException('question_not_open_text');
        }

        $answers = $this->openTextAnswers($questionId, false);
        $visibleAnswers = array_map(static fn (array $row): array => [
            'answer_text' => $row['text'],
            'nickname' => $row['nickname'],
            'created_at' => $row['created_at'],
        ], $answers);

        $session = $this->sessions->findById((int) $question['session_id']);
        $language = (string) ($session['language'] ?? 'en');

        $themeExtractor = $this->themeExtractor ?? OpenTextThemeExtractionService::fromConfig();

        return [
            'question_id' => $questionId,
            'question_text' => $question['question_text'],
            'answer_count' => count($visibleAnswers),
            'themes' => $themeExtractor->extractThemes(
                (string) $question['question_text'],
                $visibleAnswers,
                $language
            ),
        ];
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    private function buildQuestionResults(array $question, bool $studentView, string $language = 'en'): array
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
                'word_cloud' => $this->buildWordCloud($rows, $language),
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
                $questionResults[] = array_merge($base, [
                    'answers' => $answers,
                    'word_cloud' => $this->buildWordCloud($rows, (string) ($session['language'] ?? 'en')),
                ]);
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
                'is_quiz' => (bool) ($session['is_quiz'] ?? 0),
            ],
            'summary' => [
                'participant_count' => $participantCount,
                'question_count' => $questionCount,
                'answer_count' => $totalAnswers,
                'participation_rate' => $participationRate,
            ],
            'questions' => $questionResults,
        ];

        if ((bool) ($session['is_quiz'] ?? 0)) {
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

    /**
     * Builds a deterministic word cloud from open-text answers.
     *
     * @param array<int,array<string,mixed>> $answers
     * @return array<int,array{term:string,count:int,weight:float}>
     */
    private function buildWordCloud(array $answers, string $language): array
    {
        $counts = [];
        $stopWords = array_fill_keys($this->wordCloudStopWords($language), true);

        foreach ($answers as $row) {
            $text = mb_strtolower(trim((string) ($row['text'] ?? $row['answer_text'] ?? '')), 'UTF-8');
            if ($text === '') {
                continue;
            }

            $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
            if ($normalized === null || trim($normalized) === '') {
                continue;
            }

            $tokens = preg_split('/\s+/u', trim($normalized), -1, PREG_SPLIT_NO_EMPTY);
            if ($tokens === false) {
                continue;
            }

            foreach ($tokens as $token) {
                $token = trim($token, "'’`-_");
                if ($token === '' || mb_strlen($token, 'UTF-8') < 2) {
                    continue;
                }
                if (preg_match('/^\p{N}+$/u', $token) === 1) {
                    continue;
                }
                if (isset($stopWords[$token])) {
                    continue;
                }

                $counts[$token] = ($counts[$token] ?? 0) + 1;
            }
        }

        if ($counts === []) {
            return [];
        }

        uksort($counts, static function (string $left, string $right) use ($counts): int {
            $countCompare = $counts[$right] <=> $counts[$left];
            if ($countCompare !== 0) {
                return $countCompare;
            }

            return strcmp($left, $right);
        });

        $counts = array_slice($counts, 0, 12, true);
        $max = max($counts);

        $cloud = [];
        foreach ($counts as $term => $count) {
            $cloud[] = [
                'term' => $term,
                'count' => (int) $count,
                'weight' => $max > 0 ? round((float) $count / $max, 2) : 0.0,
            ];
        }

        return $cloud;
    }

    /**
     * @return array<int,string>
     */
    private function wordCloudStopWords(string $language): array
    {
        $language = strtolower(substr($language, 0, 2));

        if ($language === 'tr') {
            return [
                've', 'veya', 'ile', 'bir', 'bu', 'şu', 'o', 'için', 'gibi', 'ama',
                'de', 'da', 'mi', 'mı', 'mu', 'mü', 'çok', 'daha', 'en', 'kadar', 'olarak',
                'ben', 'sen', 'biz', 'siz', 'onlar', 'ne', 'neden', 'nasıl', 'hangi', 'şey',
                'biraz', 'şimdi', 'burada', 'orada', 'yani', 'değil', 'hem', 'hemde', 'çünkü',
            ];
        }

        return [
            'a', 'an', 'and', 'are', 'as', 'at', 'be', 'been', 'but', 'by', 'can', 'could',
            'did', 'do', 'does', 'for', 'from', 'had', 'has', 'have', 'he', 'her', 'here',
            'him', 'his', 'i', 'if', 'in', 'into', 'is', 'it', 'its', 'me', 'my', 'not',
            'of', 'on', 'or', 'our', 'out', 'she', 'so', 'that', 'the', 'their', 'them',
            'there', 'these', 'they', 'this', 'those', 'to', 'too', 'us', 'very', 'was',
            'we', 'were', 'what', 'when', 'where', 'which', 'who', 'will', 'with', 'you',
            'your',
        ];
    }

    /**
     * Builds a course-level analytics view across every session in the course.
     *
     * @throws \RuntimeException course_not_found | forbidden
     */
    public function buildCourseAnalytics(int $courseId, int $userId): array
    {
        $course = $this->requireCourse($courseId, $userId);
        $sessions = $this->sessions->listByCourse($courseId);

        $sessionAnalytics = [];
        $questionTypeCounts = [
            'multiple_choice' => 0,
            'open_text' => 0,
            'yes_no' => 0,
            'likert_5' => 0,
        ];

        $closedSessionCount = 0;
        $participantCount = 0;
        $questionCount = 0;
        $answerCount = 0;
        $participationTotal = 0.0;
        $lastSessionAt = null;

        foreach ($sessions as $session) {
            $report = $this->buildReport((int) $session['id'], $userId, false);
            $summary = $report['summary'];

            if (($session['status'] ?? '') === 'closed') {
                $closedSessionCount++;
            }

            $participantCount += (int) $summary['participant_count'];
            $questionCount += (int) $summary['question_count'];
            $answerCount += (int) $summary['answer_count'];
            $participationTotal += (float) $summary['participation_rate'];

            foreach ($report['questions'] as $question) {
                $type = $question['type'];
                if (array_key_exists($type, $questionTypeCounts)) {
                    $questionTypeCounts[$type]++;
                }
            }

            $candidateLastSessionAt = $session['started_at'] ?: $session['created_at'];
            if ($candidateLastSessionAt !== null && ($lastSessionAt === null || strcmp((string) $candidateLastSessionAt, (string) $lastSessionAt) > 0)) {
                $lastSessionAt = $candidateLastSessionAt;
            }

            $sessionAnalytics[] = [
                'session_id' => (int) $session['id'],
                'title' => $session['title'],
                'short_code' => $session['short_code'],
                'status' => $session['status'],
                'started_at' => $session['started_at'],
                'closed_at' => $session['closed_at'],
                'participant_count' => (int) $summary['participant_count'],
                'question_count' => (int) $summary['question_count'],
                'answer_count' => (int) $summary['answer_count'],
                'participation_rate' => (float) $summary['participation_rate'],
                'anonymized' => (bool) $session['anonymized'],
                'is_quiz' => (bool) ($session['is_quiz'] ?? false),
            ];
        }

        $sessionCount = count($sessionAnalytics);

        return [
            'course' => [
                'id' => (int) $course['id'],
                'title' => $course['title'],
                'code' => $course['code'],
                'semester' => $course['semester'],
                'status' => $course['status'],
            ],
            'summary' => [
                'session_count' => $sessionCount,
                'closed_session_count' => $closedSessionCount,
                'participant_count' => $participantCount,
                'question_count' => $questionCount,
                'answer_count' => $answerCount,
                'average_participation_rate' => $sessionCount > 0 ? round($participationTotal / $sessionCount, 4) : 0.0,
                'last_session_at' => $lastSessionAt,
            ],
            'question_type_breakdown' => array_map(
                static fn (string $type, int $count): array => ['type' => $type, 'count' => $count],
                array_keys($questionTypeCounts),
                array_values($questionTypeCounts)
            ),
            'sessions' => $sessionAnalytics,
        ];
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
        $this->requireCourse((int) $session['course_id'], $userId);

        return $session;
    }

    private function requireCourse(int $courseId, int $userId): array
    {
        $course = $this->courses->findById($courseId);
        if ($course === null) {
            throw new \RuntimeException('course_not_found');
        }
        if ((int) $course['instructor_id'] !== $userId) {
            throw new \RuntimeException('forbidden');
        }

        return $course;
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
}
