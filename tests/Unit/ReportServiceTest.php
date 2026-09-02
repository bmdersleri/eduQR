<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit;

use EduQR\Contracts\CourseRepositoryInterface;
use EduQR\Contracts\OpenTextThemeExtractionServiceInterface;
use EduQR\Contracts\OptionRepositoryInterface;
use EduQR\Contracts\QuestionRepositoryInterface;
use EduQR\Contracts\SessionRepositoryInterface;
use EduQR\Services\ReportService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ReportService — T-811
 *
 * The aggregation helpers use raw SQL, so we use an in-memory SQLite DB
 * for the PDO-bound tests, and mock repositories for the permission tests.
 */
class ReportServiceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->pdo->exec('
            CREATE TABLE options (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                question_id  INTEGER NOT NULL,
                option_text  TEXT    NOT NULL,
                option_value TEXT    NULL,
                is_correct   INTEGER NOT NULL DEFAULT 0,
                order_no     INTEGER NOT NULL DEFAULT 0
            )
        ');

        $this->pdo->exec('
            CREATE TABLE answers (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                question_id         INTEGER NOT NULL,
                participant_id      INTEGER NOT NULL,
                selected_option_id  INTEGER NULL,
                answer_text         TEXT    NULL,
                is_hidden           INTEGER NOT NULL DEFAULT 0,
                created_at          TEXT    NOT NULL DEFAULT (datetime(\'now\')),
                UNIQUE (question_id, participant_id)
            )
        ');

        $this->pdo->exec('
            CREATE TABLE participants (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id INTEGER NOT NULL,
                nickname   TEXT    NOT NULL
            )
        ');
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function makeService(
        ?array $questionRow = null,
        ?array $sessionRow = null,
        ?array $courseRow = null,
        ?array $sessionList = null,
        ?OpenTextThemeExtractionServiceInterface $themeExtractor = null,
    ): ReportService {
        $q = $questionRow ?? [
            'id' => 1,
            'session_id' => 10,
            'question_type' => 'multiple_choice',
            'question_text' => 'Test?',
            'status' => 'closed',
            'show_results' => 1,
        ];

        $questions = $this->createMock(QuestionRepositoryInterface::class);
        $questions->method('findById')->willReturn($q);
        $questions->method('findBySession')->willReturn([$q]);

        $sessions = $this->createMock(SessionRepositoryInterface::class);
        $sessions->method('findById')->willReturn($sessionRow ?? ['id' => 10, 'status' => 'active', 'course_id' => 5, 'show_results_to_students' => 1]);
        $sessions->method('findByShortCode')->willReturn($sessionRow ?? ['id' => 10, 'status' => 'active', 'course_id' => 5, 'show_results_to_students' => 1]);
        $sessions->method('listByCourse')->willReturn($sessionList ?? [$sessionRow ?? ['id' => 10, 'status' => 'active', 'course_id' => 5, 'show_results_to_students' => 1]]);

        $courses = $this->createMock(CourseRepositoryInterface::class);
        $courses->method('findById')->willReturn($courseRow ?? ['id' => 5, 'instructor_id' => 99]);

        $options = $this->createMock(OptionRepositoryInterface::class);

        return new ReportService($sessions, $questions, $options, $courses, $this->pdo, $themeExtractor);
    }

    /** Insert option rows directly into the SQLite DB. */
    private function seedOptions(int $questionId, array $texts): array
    {
        $ids = [];
        foreach ($texts as $i => $text) {
            $this->pdo->prepare(
                'INSERT INTO options (question_id, option_text, option_value, order_no) VALUES (?,?,?,?)'
            )->execute([$questionId, $text, (string) ($i + 1), $i + 1]);
            $ids[] = (int) $this->pdo->lastInsertId();
        }

        return $ids;
    }

    /** Insert a single is_correct=1 option (fill_in_the_blank / quiz mode). */
    private function seedCorrectOption(int $questionId, string $text): int
    {
        $this->pdo->prepare(
            'INSERT INTO options (question_id, option_text, option_value, is_correct, order_no) VALUES (?,?,NULL,1,1)'
        )->execute([$questionId, $text]);

        return (int) $this->pdo->lastInsertId();
    }

    /** Insert participant + answer rows. */
    private function seedAnswer(int $participantId, int $questionId, ?int $optionId, ?string $text = null): void
    {
        $this->pdo->prepare(
            'INSERT OR IGNORE INTO participants (id, session_id, nickname) VALUES (?,?,?)'
        )->execute([$participantId, 10, 'P' . $participantId]);

        $this->pdo->prepare(
            'INSERT INTO answers (question_id, participant_id, selected_option_id, answer_text) VALUES (?,?,?,?)'
        )->execute([$questionId, $participantId, $optionId, $text]);
    }

    private function seedHiddenAnswer(int $participantId, int $questionId, ?int $optionId): void
    {
        $this->pdo->prepare(
            'INSERT OR IGNORE INTO participants (id, session_id, nickname) VALUES (?,?,?)'
        )->execute([$participantId, 10, 'P' . $participantId]);

        $this->pdo->prepare(
            'INSERT INTO answers (question_id, participant_id, selected_option_id, is_hidden) VALUES (?,?,?,1)'
        )->execute([$questionId, $participantId, $optionId]);
    }

    // ── T-801: aggregate counts ────────────────────────────────────────────────

    public function testAggregateReturnsZeroCountsWithNoAnswers(): void
    {
        $optIds = $this->seedOptions(1, ['Yes', 'No']);
        $service = $this->makeService();

        $result = $service->aggregate(1);

        $this->assertSame(0, $result['answer_count']);
        $this->assertCount(2, $result['options']);
        foreach ($result['options'] as $opt) {
            $this->assertSame(0, $opt['count']);
            $this->assertSame(0.0, $opt['percent']);
        }
    }

    public function testAggregateCountsAnswersPerOption(): void
    {
        [$optA, $optB, $optC] = $this->seedOptions(1, ['A', 'B', 'C']);

        $this->seedAnswer(1, 1, $optA);
        $this->seedAnswer(2, 1, $optA);
        $this->seedAnswer(3, 1, $optB);

        $service = $this->makeService();
        $result = $service->aggregate(1);

        $this->assertSame(3, $result['answer_count']);

        $counts = array_column($result['options'], 'count');
        $this->assertSame(2, $counts[0]); // A
        $this->assertSame(1, $counts[1]); // B
        $this->assertSame(0, $counts[2]); // C (no answers)
    }

    // ── T-801: percentage rounding ─────────────────────────────────────────────

    public function testPercentagesSumToApproximately100(): void
    {
        [$optA, $optB, $optC] = $this->seedOptions(1, ['A', 'B', 'C']);
        $this->seedAnswer(1, 1, $optA);
        $this->seedAnswer(2, 1, $optB);
        $this->seedAnswer(3, 1, $optC);

        $service = $this->makeService();
        $result = $service->aggregate(1);

        foreach ($result['options'] as $opt) {
            $this->assertEqualsWithDelta(33.3, $opt['percent'], 0.5);
        }
        $total = array_sum(array_column($result['options'], 'percent'));
        $this->assertEqualsWithDelta(100.0, $total, 0.4);
    }

    public function testPercentageIsZeroWhenNoAnswers(): void
    {
        $this->seedOptions(1, ['A', 'B']);
        $service = $this->makeService();
        $result = $service->aggregate(1);

        $this->assertSame(0.0, $result['options'][0]['percent']);
        $this->assertSame(0.0, $result['options'][1]['percent']);
    }

    // ── T-811: hidden answers excluded from counts ─────────────────────────────

    public function testHiddenAnswersExcludedFromAggregate(): void
    {
        [$optA, $optB] = $this->seedOptions(1, ['Yes', 'No']);

        $this->seedAnswer(1, 1, $optA);       // visible
        $this->seedHiddenAnswer(2, 1, $optB); // hidden → should not count

        $service = $this->makeService();
        $result = $service->aggregate(1);

        $this->assertSame(1, $result['answer_count']); // only the visible one
        $this->assertSame(1, $result['options'][0]['count']); // optA
        $this->assertSame(0, $result['options'][1]['count']); // optB hidden
    }

    // ── T-802: open-text answer list ──────────────────────────────────────────

    public function testOpenTextAnswersReturnsList(): void
    {
        $this->pdo->exec("INSERT INTO participants (id, session_id, nickname) VALUES (1, 10, 'Alice')");
        $this->pdo->exec("INSERT INTO answers (question_id, participant_id, answer_text) VALUES (2, 1, 'Great lecture')");

        $service = $this->makeService(questionRow: [
            'id' => 2,
            'session_id' => 10,
            'question_type' => 'open_text',
            'question_text' => 'Tell us.',
            'status' => 'closed',
            'show_results' => 1,
        ]);

        $answers = $service->openTextAnswers(2, true);

        $this->assertCount(1, $answers);
        $this->assertSame('Great lecture', $answers[0]['text']);
        $this->assertSame('Alice', $answers[0]['nickname']);
    }

    public function testHiddenOpenTextExcludedForStudents(): void
    {
        $this->pdo->exec("INSERT INTO participants (id, session_id, nickname) VALUES (1, 10, 'Alice')");
        $this->pdo->exec("INSERT INTO participants (id, session_id, nickname) VALUES (2, 10, 'Bob')");
        $this->pdo->exec("INSERT INTO answers (question_id, participant_id, answer_text) VALUES (2, 1, 'Visible')");
        $this->pdo->exec("INSERT INTO answers (question_id, participant_id, answer_text, is_hidden) VALUES (2, 2, 'Hidden', 1)");

        $service = $this->makeService(questionRow: [
            'id' => 2,
            'session_id' => 10,
            'question_type' => 'open_text',
            'question_text' => 'Tell us.',
            'status' => 'closed',
            'show_results' => 1,
        ]);

        // Students: includeHidden = false
        $visible = $service->openTextAnswers(2, false);
        $this->assertCount(1, $visible);
        $this->assertSame('Visible', $visible[0]['text']);

        // Instructor: includeHidden = true
        $all = $service->openTextAnswers(2, true);
        $this->assertCount(2, $all);
    }

    public function testBuildReportAddsWordCloudForOpenTextQuestions_FR66(): void
    {
        $this->pdo->exec("INSERT INTO participants (id, session_id, nickname) VALUES (1, 10, 'Alice')");
        $this->pdo->exec("INSERT INTO participants (id, session_id, nickname) VALUES (2, 10, 'Bob')");
        $this->pdo->exec("INSERT INTO participants (id, session_id, nickname) VALUES (3, 10, 'Cem')");
        $this->pdo->exec("INSERT INTO answers (question_id, participant_id, answer_text) VALUES (2, 1, 'Pointer logic')");
        $this->pdo->exec("INSERT INTO answers (question_id, participant_id, answer_text) VALUES (2, 2, 'Pointer usage')");
        $this->pdo->exec("INSERT INTO answers (question_id, participant_id, answer_text) VALUES (2, 3, 'The pointer is clear')");

        $service = $this->makeService(questionRow: [
            'id' => 2,
            'session_id' => 10,
            'question_type' => 'open_text',
            'question_text' => 'Tell us.',
            'status' => 'closed',
            'show_results' => 1,
        ], sessionRow: [
            'id' => 10,
            'status' => 'closed',
            'course_id' => 5,
            'show_results_to_students' => 1,
            'title' => 'S',
            'language' => 'en',
            'started_at' => null,
            'closed_at' => null,
            'anonymized' => 0,
        ]);

        $report = $service->buildReport(10, 99);
        $question = $report['questions'][0];

        $this->assertArrayHasKey('word_cloud', $question);
        $this->assertSame(['pointer', 'clear', 'logic', 'usage'], array_column($question['word_cloud'], 'term'));
        $this->assertSame(3, $question['word_cloud'][0]['count']);
    }

    public function testStudentResultsWordCloudExcludesHiddenAnswers_FR66(): void
    {
        $this->pdo->exec("INSERT INTO participants (id, session_id, nickname) VALUES (1, 10, 'Alice')");
        $this->pdo->exec("INSERT INTO participants (id, session_id, nickname) VALUES (2, 10, 'Bob')");
        $this->pdo->exec("INSERT INTO answers (question_id, participant_id, answer_text) VALUES (2, 1, 'Alpha beta')");
        $this->pdo->exec("INSERT INTO answers (question_id, participant_id, answer_text, is_hidden) VALUES (2, 2, 'Gamma delta', 1)");

        $service = $this->makeService(questionRow: [
            'id' => 2,
            'session_id' => 10,
            'question_type' => 'open_text',
            'question_text' => 'Tell us.',
            'status' => 'closed',
            'show_results' => 1,
        ], sessionRow: [
            'id' => 10,
            'status' => 'active',
            'course_id' => 5,
            'show_results_to_students' => 1,
            'short_code' => 'ABCD12',
            'title' => 'S',
            'language' => 'en',
            'started_at' => null,
            'closed_at' => null,
            'anonymized' => 0,
        ]);

        $results = $service->getStudentResults('ABCD12', 2);
        $question = $results[0];
        $terms = array_column($question['word_cloud'], 'term');

        $this->assertSame(['alpha', 'beta'], $terms);
        $this->assertNotContains('gamma', $terms);
        $this->assertNotContains('delta', $terms);
    }

    public function testExtractThemesUsesVisibleOpenTextAnswers_FR65(): void
    {
        $this->pdo->exec("INSERT INTO participants (id, session_id, nickname) VALUES (1, 10, 'Alice')");
        $this->pdo->exec("INSERT INTO participants (id, session_id, nickname) VALUES (2, 10, 'Bob')");
        $this->pdo->exec("INSERT INTO answers (question_id, participant_id, answer_text) VALUES (2, 1, 'Visible')");
        $this->pdo->exec("INSERT INTO answers (question_id, participant_id, answer_text, is_hidden) VALUES (2, 2, 'Hidden', 1)");

        $themeExtractor = new class () implements OpenTextThemeExtractionServiceInterface {
            public array $capturedAnswers = [];

            public function extractThemes(string $questionText, array $answers, string $language): array
            {
                $this->capturedAnswers = $answers;

                return [[
                    'title' => 'Pointer basics',
                    'summary' => 'Students mention pointer logic.',
                    'keywords' => ['pointers'],
                    'example_answers' => ['Visible'],
                ]];
            }
        };

        $service = $this->makeService(questionRow: [
            'id' => 2,
            'session_id' => 10,
            'question_type' => 'open_text',
            'question_text' => 'Tell us.',
            'status' => 'closed',
            'show_results' => 1,
        ], themeExtractor: $themeExtractor);

        $result = $service->extractThemes(2, 99);

        $this->assertSame(1, $result['answer_count']);
        $this->assertCount(1, $result['themes']);
        $this->assertSame('Pointer basics', $result['themes'][0]['title']);
        $this->assertCount(1, $themeExtractor->capturedAnswers);
        $this->assertSame('Visible', $themeExtractor->capturedAnswers[0]['answer_text']);
    }

    // ── T-804: results_hidden for student ─────────────────────────────────────

    public function testStudentResultsThrowsWhenShowResultsDisabled(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('results_hidden');

        $service = $this->makeService(sessionRow: [
            'id' => 10,
            'status' => 'active',
            'course_id' => 5,
            'show_results_to_students' => 0,
        ]);

        $service->getStudentResults('ABCD12', null);
    }

    // ── FR-96: exam_mode overrides show_results_to_students ───────────────────

    public function testStudentResultsThrowsWhenExamModeEnabledEvenIfShowResultsEnabled(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('results_hidden');

        $service = $this->makeService(sessionRow: [
            'id' => 10,
            'status' => 'active',
            'course_id' => 5,
            'show_results_to_students' => 1,
            'exam_mode' => 1,
        ]);

        $service->getStudentResults('ABCD12', null);
    }

    public function testStudentResultsReturnsResultsWhenExamModeDisabledAndShowResultsEnabled(): void
    {
        [$optA, $optB] = $this->seedOptions(1, ['Yes', 'No']);
        $this->seedAnswer(1, 1, $optA);

        $service = $this->makeService(sessionRow: [
            'id' => 10,
            'status' => 'active',
            'course_id' => 5,
            'show_results_to_students' => 1,
            'exam_mode' => 0,
        ]);

        $results = $service->getStudentResults('ABCD12', null);

        $this->assertCount(1, $results);
        $this->assertSame(1, $results[0]['answer_count']);
    }

    // ── fill_in_the_blank quiz scoring (FR-31, FR-92) ───────────────────────────

    public function testFillInTheBlankQuizScoringMatchesCaseInsensitiveTrimmed(): void
    {
        $this->seedCorrectOption(1, 'Mitochondria');

        // Participant 1 answers correctly, with different case and stray whitespace.
        $this->seedAnswer(1, 1, null, '  mitochondria  ');
        // Participant 2 answers incorrectly.
        $this->seedAnswer(2, 1, null, 'Nucleus');

        $service = $this->makeService(
            questionRow: [
                'id' => 1,
                'session_id' => 10,
                'question_type' => 'fill_in_the_blank',
                'question_text' => 'The powerhouse of the cell is the ____.',
                'status' => 'closed',
                'show_results' => 1,
            ],
            sessionRow: [
                'id' => 10, 'status' => 'closed', 'course_id' => 5,
                'show_results_to_students' => 1, 'title' => 'Quiz',
                'language' => 'en', 'started_at' => null, 'closed_at' => null,
                'anonymized' => 0, 'is_quiz' => 1,
            ],
        );

        $report = $service->buildReport(10, 99);

        $this->assertArrayHasKey('scores', $report);

        $byParticipant = [];
        foreach ($report['scores'] as $row) {
            $byParticipant[$row['participant_id']] = $row['score'];
        }

        $this->assertSame(1, $byParticipant[1]); // correct, case-insensitive trimmed match
        $this->assertSame(0, $byParticipant[2]); // incorrect
    }

    // ── T-912: buildReport() — shape and counts ────────────────────────────────

    public function testBuildReportReturnsCorrectTopLevelShape(): void
    {
        $service = $this->makeService(sessionRow: [
            'id' => 10, 'status' => 'closed', 'course_id' => 5,
            'show_results_to_students' => 1, 'title' => 'Test Session',
            'language' => 'en', 'started_at' => '2026-05-15 10:00:00',
            'closed_at' => '2026-05-15 11:00:00', 'anonymized' => 0,
        ]);

        $this->seedOptions(1, ['A', 'B']);
        $report = $service->buildReport(10, 99);

        $this->assertArrayHasKey('session', $report);
        $this->assertArrayHasKey('summary', $report);
        $this->assertArrayHasKey('questions', $report);

        $this->assertSame('Test Session', $report['session']['title']);
        $this->assertSame(false, $report['session']['anonymized']);
        $this->assertArrayHasKey('participant_count', $report['summary']);
        $this->assertArrayHasKey('question_count', $report['summary']);
        $this->assertArrayHasKey('answer_count', $report['summary']);
        $this->assertArrayHasKey('participation_rate', $report['summary']);
    }

    public function testBuildReportSummaryCounts(): void
    {
        $this->seedOptions(1, ['A', 'B', 'C']);
        $this->seedAnswer(1, 1, 1); // participant 1 → optA
        $this->seedAnswer(2, 1, 2); // participant 2 → optB

        $service = $this->makeService(sessionRow: [
            'id' => 10, 'status' => 'closed', 'course_id' => 5,
            'show_results_to_students' => 1, 'title' => 'S',
            'language' => 'en', 'started_at' => null, 'closed_at' => null, 'anonymized' => 0,
        ]);

        $report = $service->buildReport(10, 99);

        $this->assertSame(2, $report['summary']['participant_count']); // 2 participants seeded
        $this->assertSame(2, $report['summary']['answer_count']);
        $this->assertSame(1, $report['summary']['question_count']);
    }

    public function testBuildReportOptionDistribution(): void
    {
        [$optA, $optB] = $this->seedOptions(1, ['Yes', 'No']);
        $this->seedAnswer(1, 1, $optA);
        $this->seedAnswer(2, 1, $optA);
        $this->seedAnswer(3, 1, $optB);

        $service = $this->makeService(sessionRow: [
            'id' => 10, 'status' => 'closed', 'course_id' => 5,
            'show_results_to_students' => 1, 'title' => 'S',
            'language' => 'en', 'started_at' => null, 'closed_at' => null, 'anonymized' => 0,
        ]);

        $report = $service->buildReport(10, 99);
        $q = $report['questions'][0];

        $this->assertArrayHasKey('distribution', $q);
        $this->assertSame(2, $q['distribution'][0]['count']); // Yes
        $this->assertSame(1, $q['distribution'][1]['count']); // No
    }

    public function testBuildReportAnonymizesNicknames(): void
    {
        // Use an open_text question so we can check nicknames
        $openQ = [
            'id' => 3,
            'session_id' => 10,
            'question_type' => 'open_text',
            'question_text' => 'Tell us.',
            'status' => 'closed',
            'show_results' => 1,
        ];

        $this->pdo->exec("INSERT INTO participants (id, session_id, nickname) VALUES (1, 10, 'Alice')");
        $this->pdo->exec("INSERT INTO participants (id, session_id, nickname) VALUES (2, 10, 'Bob')");
        $this->pdo->exec("INSERT INTO answers (question_id, participant_id, answer_text) VALUES (3, 1, 'Hello')");
        $this->pdo->exec("INSERT INTO answers (question_id, participant_id, answer_text) VALUES (3, 2, 'World')");

        $service = $this->makeService(
            questionRow: $openQ,
            sessionRow:  [
                'id' => 10, 'status' => 'closed', 'course_id' => 5,
                'show_results_to_students' => 1, 'title' => 'S',
                'language' => 'en', 'started_at' => null, 'closed_at' => null, 'anonymized' => 0,
            ],
        );

        $report = $service->buildReport(10, 99, anonymize: true);
        $nicknames = array_column($report['questions'][0]['answers'], 'nickname');

        foreach ($nicknames as $nick) {
            $this->assertStringStartsWith('Participant ', $nick);
            $this->assertStringNotContainsStringIgnoringCase('Alice', $nick);
            $this->assertStringNotContainsStringIgnoringCase('Bob', $nick);
        }
    }

    public function testBuildReportAnonymizationIsConsistentAcrossQuestions(): void
    {
        $q1 = ['id' => 10, 'session_id' => 10, 'question_type' => 'open_text',
                'question_text' => 'Q1', 'status' => 'closed', 'show_results' => 1];
        $q2 = ['id' => 11, 'session_id' => 10, 'question_type' => 'open_text',
                'question_text' => 'Q2', 'status' => 'closed', 'show_results' => 1];

        $this->pdo->exec("INSERT INTO participants (id, session_id, nickname) VALUES (1, 10, 'Elif')");
        $this->pdo->exec("INSERT INTO answers (question_id, participant_id, answer_text) VALUES (10, 1, 'Ans1')");
        $this->pdo->exec("INSERT INTO answers (question_id, participant_id, answer_text) VALUES (11, 1, 'Ans2')");

        // Mock returns BOTH questions
        $sessions = $this->createMock(\EduQR\Contracts\SessionRepositoryInterface::class);
        $sessionRow = [
            'id' => 10, 'status' => 'closed', 'course_id' => 5,
            'show_results_to_students' => 1, 'title' => 'S',
            'language' => 'en', 'started_at' => null, 'closed_at' => null, 'anonymized' => 0,
        ];
        $sessions->method('findById')->willReturn($sessionRow);
        $sessions->method('findByShortCode')->willReturn($sessionRow);

        $questions = $this->createMock(\EduQR\Contracts\QuestionRepositoryInterface::class);
        $questions->method('findById')->willReturn($q1);
        $questions->method('findBySession')->willReturn([$q1, $q2]);

        $courses = $this->createMock(\EduQR\Contracts\CourseRepositoryInterface::class);
        $courses->method('findById')->willReturn(['id' => 5, 'instructor_id' => 99, 'title' => 'CS']);

        $options = $this->createMock(\EduQR\Contracts\OptionRepositoryInterface::class);

        $service = new \EduQR\Services\ReportService($sessions, $questions, $options, $courses, $this->pdo);
        $report = $service->buildReport(10, 99, anonymize: true);

        $nick1 = $report['questions'][0]['answers'][0]['nickname'];
        $nick2 = $report['questions'][1]['answers'][0]['nickname'];

        $this->assertSame($nick1, $nick2, 'Same participant must get same label in all questions');
        $this->assertStringStartsWith('Participant ', $nick1);
    }

    public function testBuildReportForbiddenForWrongInstructor(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('forbidden');

        $service = $this->makeService(courseRow: ['id' => 5, 'instructor_id' => 999]);
        $service->buildReport(10, 1); // userId=1, but instructor_id=999
    }

    public function testBuildReportSessionNotFound(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('session_not_found');

        $sessions = $this->createMock(\EduQR\Contracts\SessionRepositoryInterface::class);
        $sessions->method('findById')->willReturn(null);
        $sessions->method('findByShortCode')->willReturn(null);

        $questions = $this->createMock(\EduQR\Contracts\QuestionRepositoryInterface::class);
        $courses = $this->createMock(\EduQR\Contracts\CourseRepositoryInterface::class);
        $options = $this->createMock(\EduQR\Contracts\OptionRepositoryInterface::class);

        $service = new \EduQR\Services\ReportService($sessions, $questions, $options, $courses, $this->pdo);
        $service->buildReport(99, 1);
    }

    public function testBuildCourseAnalyticsReturnsSummaryAndSessionRows_FR64(): void
    {
        $this->seedOptions(1, ['A', 'B']);
        $this->seedAnswer(1, 1, 1);
        $this->seedAnswer(2, 1, 2);

        $service = $this->makeService(
            sessionRow: [
                'id' => 10,
                'status' => 'closed',
                'course_id' => 5,
                'show_results_to_students' => 1,
                'title' => 'Week 1',
                'short_code' => 'ABCD23',
                'language' => 'en',
                'started_at' => '2026-05-15 10:00:00',
                'closed_at' => '2026-05-15 11:00:00',
                'created_at' => '2026-05-15 09:55:00',
                'anonymized' => 0,
                'is_quiz' => 0,
            ],
            sessionList: [[
                'id' => 10,
                'status' => 'closed',
                'course_id' => 5,
                'show_results_to_students' => 1,
                'title' => 'Week 1',
                'short_code' => 'ABCD23',
                'language' => 'en',
                'started_at' => '2026-05-15 10:00:00',
                'closed_at' => '2026-05-15 11:00:00',
                'created_at' => '2026-05-15 09:55:00',
                'anonymized' => 0,
                'is_quiz' => 0,
            ]],
            courseRow: ['id' => 5, 'instructor_id' => 99, 'title' => 'CS', 'status' => 'active', 'code' => 'CSE203', 'semester' => '2026-Spring'],
        );

        $analytics = $service->buildCourseAnalytics(5, 99);

        $this->assertSame('CS', $analytics['course']['title']);
        $this->assertSame(1, $analytics['summary']['session_count']);
        $this->assertSame(1, $analytics['summary']['closed_session_count']);
        $this->assertSame(2, $analytics['summary']['participant_count']);
        $this->assertSame(1, $analytics['summary']['question_count']);
        $this->assertSame(2, $analytics['summary']['answer_count']);
        $this->assertSame('2026-05-15 10:00:00', $analytics['summary']['last_session_at']);
        $this->assertSame('multiple_choice', $analytics['question_type_breakdown'][0]['type']);
        $this->assertSame(1, $analytics['question_type_breakdown'][0]['count']);
        $this->assertSame('Week 1', $analytics['sessions'][0]['title']);
        $this->assertSame('ABCD23', $analytics['sessions'][0]['short_code']);
    }

    public function testBuildCourseAnalyticsRejectsWrongInstructor_FR64(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('forbidden');

        $service = $this->makeService(courseRow: ['id' => 5, 'instructor_id' => 999]);
        $service->buildCourseAnalytics(5, 1);
    }
}
