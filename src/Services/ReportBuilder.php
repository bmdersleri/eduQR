<?php

declare(strict_types=1);

namespace EduQR\Services;

use EduQR\Contracts\CourseRepositoryInterface;
use EduQR\Contracts\QuestionRepositoryInterface;
use EduQR\Contracts\ReportBuilderInterface;
use EduQR\Contracts\ScoringServiceInterface;
use EduQR\Contracts\SessionRepositoryInterface;
use EduQR\Exceptions\ForbiddenException;
use EduQR\Exceptions\NotFoundException;
use EduQR\Support\TextFold;
use PDO;

/**
 * Post-session report assembly — T-900, NFR-82.
 *
 * Split out of ReportService: assembling the closed-session document is a
 * different job from answering the live-results endpoints, even though both
 * read the same rows. This unit knows the shape of a report; it does not know
 * how a question is polled while a session is open.
 *
 * The PDO handle is required. buildReport() counts participants and answers
 * itself, so an instance without a connection could not build anything.
 *
 * requireSession(), requireCourse(), openTextAnswers(), aggregateOptions(),
 * buildWordCloud() and wordCloudStopWords() are duplicated here rather than
 * shared (NFR-82). A base class or a helper service holding them would put the
 * split units back into one object; the point of the split is that each unit
 * can be read on its own.
 */
final class ReportBuilder implements ReportBuilderInterface
{
    public function __construct(
        private readonly SessionRepositoryInterface  $sessions,
        private readonly QuestionRepositoryInterface $questions,
        private readonly CourseRepositoryInterface   $courses,
        private readonly PDO                         $pdo,
        private readonly ScoringServiceInterface     $scoring,
    ) {
    }

    /**
     * Builds a complete post-session report.
     *
     * @param  bool $anonymize  Replace nicknames with "Participant N" (FR-70)
     * @throws \EduQR\Exceptions\DomainException  session_not_found | forbidden
     */
    public function buildReport(int $sessionId, int $userId, bool $anonymize = false): array
    {
        $session = $this->requireSession($sessionId, $userId);
        $course = $this->courses->findById((int) $session['course_id']);
        $pdo = $this->pdo;

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
            $scores = $this->scoring->computeScores($sessionId);
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

    // ── Private helpers ────────────────────────────────────────────────────────

    /**
     * Returns open-text answers for a question.
     * If $hiddenOnly is false (default), returns all non-hidden answers.
     * If $includeHidden is true (instructor), returns all answers with is_hidden flag.
     */
    private function openTextAnswers(int $questionId, bool $includeHidden = false): array
    {
        $pdo = $this->pdo;

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

    /**
     * Counts and percentage for each option of a question.
     *
     * Returns:
     *   answer_count  — total answers (excluding hidden)
     *   options       — [{id, text, value, order_no, count, percent}]
     */
    private function aggregateOptions(int $questionId): array
    {
        $pdo = $this->pdo;

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
}
