<?php

declare(strict_types=1);

namespace EduQR\Services;

use EduQR\Contracts\CourseRepositoryInterface;
use EduQR\Contracts\OpenTextThemeExtractionServiceInterface;
use EduQR\Contracts\OptionRepositoryInterface;
use EduQR\Contracts\QuestionRepositoryInterface;
use EduQR\Contracts\SessionRepositoryInterface;
use EduQR\Exceptions\DomainException;
use EduQR\Exceptions\ForbiddenException;
use EduQR\Exceptions\NotFoundException;
use EduQR\Exceptions\ValidationException;
use EduQR\Support\Database;
use EduQR\Support\TextFold;
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
     * @throws DomainException  session_not_found | forbidden | question_not_found
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
                throw new NotFoundException('question_not_found');
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
     * @throws DomainException  session_not_found | results_hidden | question_not_found
     */
    public function getStudentResults(string $shortCode, ?int $questionId): array
    {
        $session = $this->sessions->findByShortCode($shortCode);
        if ($session === null) {
            throw new NotFoundException('session_not_found');
        }

        if ((bool) ($session['exam_mode'] ?? false) || ! (bool) $session['show_results_to_students']) {
            throw new ForbiddenException('results_hidden');
        }

        if ($questionId === null) {
            $qs = $this->questions->findBySession((int) $session['id']);
        } else {
            $q = $this->questions->findById($questionId);
            if ($q === null || (int) $q['session_id'] !== (int) $session['id']) {
                throw new NotFoundException('question_not_found');
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
            throw new NotFoundException('question_not_found');
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
     * @throws DomainException question_not_found | question_not_open_text | forbidden
     *                         | invalid_llm_response (422) | llm_unavailable (503)
     */
    public function extractThemes(int $questionId, int $userId): array
    {
        $question = $this->requireQuestion($questionId, $userId);
        if (($question['question_type'] ?? '') !== 'open_text') {
            throw new ValidationException('question_not_open_text');
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
     * @throws DomainException  session_not_found | forbidden
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
        // NFR-77: fold the stop-word list with the same rule as the answers —
        // Turkish stop words such as "için" and "mı" carry i-variants too.
        $stopWords = array_fill_keys(
            array_map(
                static fn (string $word): string => TextFold::forComparison($word),
                $this->wordCloudStopWords($language)
            ),
            true
        );

        foreach ($answers as $row) {
            // NFR-77: Turkish-correct fold, so "İlgi" and "ilgi" land in one bucket.
            $text = TextFold::forComparison(trim((string) ($row['text'] ?? $row['answer_text'] ?? '')));
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
     * @throws DomainException course_not_found | forbidden
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

    // ── LMS exports — Moodle GIFT + gradebook CSV (T-1113, FR-98) ─────────────

    /**
     * Builds a Moodle GIFT question export for one session.
     *
     * The file carries no participant data at all. Access is the same course
     * rule every other report export uses (FR-97). exam_mode (FR-96) gates the
     * student result path only and never restricts the instructor's own export.
     *
     * @requirement FR-98
     * @return array{session_id:int,gift:string,question_count:int,downgraded_count:int}
     * @throws DomainException session_not_found | course_not_found | forbidden
     */
    public function buildGiftExport(int $sessionId, int $userId): array
    {
        $this->requireSession($sessionId, $userId);

        $questions = $this->questions->findBySession($sessionId);
        $blocks = [];
        $downgraded = 0;

        foreach (array_values($questions) as $index => $question) {
            $block = $this->giftBlock(
                $question,
                $this->optionsForQuestion((int) $question['id']),
                $index + 1
            );
            $blocks[] = $block['text'];
            $downgraded += $block['downgraded'] ? 1 : 0;
        }

        $gift = '// ' . self::giftCommentLine(t('report.gift.file_header', ['session' => $sessionId])) . "\n";
        if ($blocks !== []) {
            $gift .= "\n" . implode("\n\n", $blocks) . "\n";
        }

        return [
            'session_id' => $sessionId,
            'gift' => $gift,
            'question_count' => count($blocks),
            'downgraded_count' => $downgraded,
        ];
    }

    /**
     * Builds gradebook rows for one session: one row per participant with the
     * quiz score (FR-92), the attainable maximum and the percentage.
     *
     * Anonymization behaves exactly as buildReport(): a session already
     * anonymized in storage (FR-70) is anonymous regardless of the flag.
     *
     * @requirement FR-98
     * @return array{session_id:int,max_score:int,rows:array<int,array{nickname:string,score:int,max_score:int,percentage:float}>}
     * @throws DomainException session_not_found | course_not_found | forbidden
     */
    public function buildGradebook(int $sessionId, int $userId, bool $anonymize = false): array
    {
        $this->requireSession($sessionId, $userId);
        $pdo = $this->pdo ?? Database::connection();

        $maxScore = $this->maxScore($sessionId, $pdo);
        $rows = [];
        $counter = 1;

        foreach ($this->computeScores($sessionId, $pdo) as $scoreRow) {
            $score = (int) $scoreRow['score'];
            $rows[] = [
                'nickname' => $anonymize
                    ? t('report.anonymized_participant', ['number' => $counter++])
                    : (string) $scoreRow['nickname'],
                'score' => $score,
                'max_score' => $maxScore,
                'percentage' => $maxScore > 0 ? round($score / $maxScore * 100, 1) : 0.0,
            ];
        }

        return [
            'session_id' => $sessionId,
            'max_score' => $maxScore,
            'rows' => $rows,
        ];
    }

    /**
     * One GIFT question block plus whether it had to be downgraded to an essay.
     *
     * @param array<string,mixed>            $question
     * @param array<int,array<string,mixed>> $options
     * @return array{text:string,downgraded:bool}
     */
    private function giftBlock(array $question, array $options, int $number): array
    {
        $type = (string) $question['question_type'];
        $title = self::giftEscape(t('report.gift.question_title', ['number' => $number]));
        $text = self::giftEscape((string) $question['question_text']);
        $correct = array_values(array_filter($options, static fn (array $o): bool => (bool) $o['is_correct']));

        // Essay by definition: no correct answer exists for these types.
        if ($type === 'open_text') {
            return ['text' => '::' . $title . ':: ' . $text . ' {}', 'downgraded' => false];
        }

        // A scale item has no correct answer, so a GIFT multiple choice would be
        // invalid. Keep it as an essay and preserve the scale as visible text.
        if ($type === 'likert_5') {
            return [
                'text' => '::' . $title . ':: ' . $text . self::giftOptionList($options) . ' {}',
                'downgraded' => false,
            ];
        }

        // The remaining forms all need a correct answer. Downgrading to a valid
        // essay keeps the question importable instead of emitting broken GIFT.
        if ($correct === []) {
            return [
                'text' => '// ' . self::giftCommentLine(t('report.gift.no_correct_answer')) . "\n"
                    . '::' . $title . ':: ' . $text . self::giftOptionList($options) . ' {}',
                'downgraded' => true,
            ];
        }

        if ($type === 'fill_in_the_blank') {
            return [
                'text' => '::' . $title . ':: ' . $text . ' {=' . self::giftEscape((string) $correct[0]['option_text']) . '}',
                'downgraded' => false,
            ];
        }

        if ($type === 'yes_no' && count($correct) === 1) {
            $flag = self::giftTrueFalseFlag($correct[0]);
            if ($flag !== null) {
                return ['text' => '::' . $title . ':: ' . $text . ' {' . $flag . '}', 'downgraded' => false];
            }
        }

        return [
            'text' => '::' . $title . ':: ' . $text . " {\n" . self::giftAnswerLines($options, count($correct)) . '}',
            'downgraded' => false,
        ];
    }

    /**
     * Answer lines for a GIFT multiple choice block. A single correct option
     * uses the plain "=" / "~" form; several correct options need the weighted
     * form, because plain GIFT accepts only one "=" per question.
     *
     * @param array<int,array<string,mixed>> $options
     */
    private static function giftAnswerLines(array $options, int $correctCount): string
    {
        $wrongCount = count($options) - $correctCount;
        $lines = '';

        foreach ($options as $option) {
            $text = self::giftEscape((string) $option['option_text']);

            if ((bool) $option['is_correct']) {
                $lines .= $correctCount === 1
                    ? '=' . $text . "\n"
                    : '~%' . self::giftCredit(100 / $correctCount) . '%' . $text . "\n";
            } else {
                $lines .= $correctCount === 1
                    ? '~' . $text . "\n"
                    : '~%-' . self::giftCredit($wrongCount > 0 ? 100 / $wrongCount : 100) . '%' . $text . "\n";
            }
        }

        return $lines;
    }

    private static function giftCredit(float $percent): string
    {
        return rtrim(rtrim(number_format($percent, 5, '.', ''), '0'), '.');
    }

    /**
     * Renders the option texts as visible text of an essay question, using the
     * GIFT "\n" escape so the block never gains a blank line.
     *
     * @param array<int,array<string,mixed>> $options
     */
    private static function giftOptionList(array $options): string
    {
        if ($options === []) {
            return '';
        }

        $lines = array_map(
            static fn (array $option): string => '- ' . self::giftEscape((string) $option['option_text']),
            $options
        );

        return '\\n' . implode('\\n', $lines);
    }

    /**
     * Maps a yes/no option onto the GIFT true/false form, or null when it does
     * not map cleanly and the multiple choice fallback should be used.
     *
     * @param array<string,mixed> $option
     */
    private static function giftTrueFalseFlag(array $option): ?string
    {
        // NFR-77: both the instructor's option text and the token list are folded
        // with the same rule — "hayır" and "HAYIR" must map onto the same token.
        $value = TextFold::forComparison(trim((string) ($option['option_value'] ?? '')));
        $text = TextFold::forComparison(trim((string) ($option['option_text'] ?? '')));

        foreach ([['T', ['yes', 'true', 'evet', 'doğru']], ['F', ['no', 'false', 'hayır', 'yanlış']]] as [$flag, $tokens]) {
            $tokens = array_map(static fn (string $token): string => TextFold::forComparison($token), $tokens);
            if (in_array($value, $tokens, true) || in_array($text, $tokens, true)) {
                return $flag;
            }
        }

        return null;
    }

    /**
     * Escapes the GIFT control characters. The backslash is remapped in the same
     * pass so escapes added here are never doubled, and every newline becomes the
     * GIFT "\n" escape — GIFT separates questions by a blank line, so a raw line
     * break inside a question body would split it into two broken questions.
     *
     * A leading "//" needs no escape: every question is written with a "::title::"
     * prefix (exactly as Moodle's own GIFT exporter does), so question text can
     * never start a line and can never be read as a comment.
     */
    private static function giftEscape(string $text): string
    {
        $escaped = strtr($text, [
            '\\' => '\\\\',
            '~' => '\\~',
            '=' => '\\=',
            '#' => '\\#',
            '{' => '\\{',
            '}' => '\\}',
            ':' => '\\:',
        ]);

        return str_replace(["\r\n", "\r", "\n"], '\\n', $escaped);
    }

    /** Collapses a translated string onto one line so it stays a valid GIFT comment. */
    private static function giftCommentLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function optionsForQuestion(int $questionId): array
    {
        $pdo = $this->pdo ?? Database::connection();

        $stmt = $pdo->prepare(
            'SELECT option_text, option_value, is_correct, order_no
             FROM options
             WHERE question_id = ?
             ORDER BY order_no ASC'
        );
        $stmt->execute([$questionId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Attainable score: the number of questions in the session carrying at least
     * one is_correct option, matching how computeScores() awards points (FR-92).
     */
    private function maxScore(int $sessionId, PDO $pdo): int
    {
        $questionIds = array_map(
            static fn (array $question): int => (int) $question['id'],
            $this->questions->findBySession($sessionId)
        );

        if ($questionIds === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($questionIds), '?'));
        $stmt = $pdo->prepare(
            'SELECT COUNT(DISTINCT question_id) FROM options
             WHERE is_correct = 1 AND question_id IN (' . $placeholders . ')'
        );
        $stmt->execute($questionIds);

        return (int) $stmt->fetchColumn();
    }

    private function computeScores(int $sessionId, PDO $pdo): array
    {
        $stmt = $pdo->prepare(
            'SELECT id AS participant_id, nickname
             FROM participants
             WHERE session_id = ?
             ORDER BY id ASC'
        );
        $stmt->execute([$sessionId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($rows === []) {
            return [];
        }

        $correctOptions = $this->correctOptions($sessionId, $pdo);

        $answersStmt = $pdo->prepare(
            'SELECT a.participant_id, a.question_id, a.selected_option_id, a.answer_text
             FROM answers a
             INNER JOIN participants p ON p.id = a.participant_id
             WHERE p.session_id = ?'
        );
        $answersStmt->execute([$sessionId]);

        $points = [];
        foreach ($answersStmt->fetchAll(PDO::FETCH_ASSOC) as $answer) {
            $participantId = (int) $answer['participant_id'];
            $points[$participantId] = ($points[$participantId] ?? 0)
                + self::countCorrectMatches($answer, $correctOptions);
        }

        $rows = array_map(
            static function (array $row) use ($points): array {
                $row['score'] = $points[(int) $row['participant_id']] ?? 0;

                return $row;
            },
            $rows
        );

        // Highest score first; ties keep join order so the ranking is stable.
        usort($rows, static fn (array $a, array $b): int => $b['score'] <=> $a['score']
            ?: (int) $a['participant_id'] <=> (int) $b['participant_id']);

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

    /**
     * Every is_correct option of every question in the session.
     *
     * @return array<int,array<int,array{id:int,text:string}>> keyed by question id
     */
    private function correctOptions(int $sessionId, PDO $pdo): array
    {
        $questionIds = array_map(
            static fn (array $question): int => (int) $question['id'],
            $this->questions->findBySession($sessionId)
        );

        if ($questionIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($questionIds), '?'));
        $stmt = $pdo->prepare(
            'SELECT id, question_id, option_text FROM options
             WHERE is_correct = 1 AND question_id IN (' . $placeholders . ')'
        );
        $stmt->execute($questionIds);

        $byQuestion = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $option) {
            $byQuestion[(int) $option['question_id']][] = [
                'id' => (int) $option['id'],
                'text' => (string) $option['option_text'],
            ];
        }

        return $byQuestion;
    }

    /**
     * Counts the correct options one answer satisfies — a selected option id, or
     * for fill_in_the_blank / typed answers a case-insensitive text match
     * (FR-31, FR-92).
     *
     * The text comparison is folded in PHP rather than with SQL LOWER(), which
     * this query used to do. SQL case folding is both engine-dependent and
     * Turkish-incorrect: MySQL's LOWER('İ') under utf8mb4_unicode_ci yields
     * "i" + U+0307 COMBINING DOT ABOVE, and SQLite's LOWER() is ASCII-only and
     * leaves 'İ' untouched entirely. Either way a Turkish student who typed
     * "İstanbul" against a correct answer of "istanbul" was marked wrong.
     *
     * Folding here rather than normalizing on write also means no stored column
     * can go stale when an instructor edits the correct answer, and it puts the
     * graded comparison on the path the tests actually exercise — SQLite-backed
     * integration tests could never have caught the SQL-side bug (NFR-77).
     *
     * @param array<string,mixed>                        $answer
     * @param array<int,array<int,array{id:int,text:string}>> $correctOptions
     */
    private static function countCorrectMatches(array $answer, array $correctOptions): int
    {
        $options = $correctOptions[(int) $answer['question_id']] ?? [];
        if ($options === []) {
            return 0;
        }

        $selectedId = $answer['selected_option_id'] === null ? null : (int) $answer['selected_option_id'];
        $typed = trim((string) ($answer['answer_text'] ?? ''));
        $typedKey = $typed === '' ? null : TextFold::forComparisonNormalized($typed);

        $matches = 0;
        foreach ($options as $option) {
            if ($selectedId !== null && $selectedId === $option['id']) {
                $matches++;

                continue;
            }

            if ($typedKey !== null && $typedKey === TextFold::forComparisonNormalized($option['text'])) {
                $matches++;
            }
        }

        return $matches;
    }

    private function requireSession(int $sessionId, int $userId): array
    {
        $session = $this->sessions->findById($sessionId);
        if ($session === null) {
            throw new NotFoundException('session_not_found');
        }
        $this->requireCourse((int) $session['course_id'], $userId);

        return $session;
    }

    private function requireCourse(int $courseId, int $userId): array
    {
        $course = $this->courses->findById($courseId);
        if ($course === null) {
            throw new NotFoundException('course_not_found');
        }
        // Owner or co-instructor (FR-97).
        if ($this->courses->roleFor($courseId, $userId) === null) {
            throw new ForbiddenException('forbidden');
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
            throw new NotFoundException('question_not_found');
        }

        $this->requireSession((int) $question['session_id'], $userId);

        return $question;
    }
}
