<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit;

use EduQR\Contracts\CourseRepositoryInterface;
use EduQR\Contracts\OpenTextThemeExtractionServiceInterface;
use EduQR\Contracts\QuestionRepositoryInterface;
use EduQR\Contracts\SessionRepositoryInterface;
use EduQR\Services\ResultsService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ResultsService — T-811, T-1130
 *
 * The aggregation helpers use raw SQL, so we use an in-memory SQLite DB
 * for the PDO-bound tests, and mock repositories for the permission tests.
 *
 * Renamed from ReportServiceTest when the live-results surface became a class
 * of its own (NFR-82); every assertion below is the one it had there.
 */
class ResultsServiceTest extends TestCase
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
        ?OpenTextThemeExtractionServiceInterface $themeExtractor = null,
    ): ResultsService {
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

        $course = $courseRow ?? ['id' => 5, 'instructor_id' => 99];

        $courses = $this->createMock(CourseRepositoryInterface::class);
        $courses->method('findById')->willReturn($course);
        // FR-97: the owner is the only instructor on these fixtures.
        $courses->method('roleFor')->willReturnCallback(
            static fn (int $courseId, int $userId): ?string => (int) $course['instructor_id'] === $userId
                ? 'owner'
                : null
        );

        // The extractor is a required collaborator now (NFR-80), so the tests
        // that do not exercise FR-65 get one that is never called.
        $themeExtractor ??= $this->createMock(OpenTextThemeExtractionServiceInterface::class);

        return new ResultsService(
            $sessions,
            $questions,
            $courses,
            $this->pdo,
            $themeExtractor,
        );
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

    /**
     * FR-66 / NFR-77: word-cloud grouping folds Turkish i-variants, so answers
     * that differ only in i-casing land in one term rather than several.
     */
    public function testWordCloudGroupsTurkishICasingIntoOneTerm_NFR77(): void
    {
        $service = $this->makeService(
            questionRow: [
                'id' => 1,
                'session_id' => 10,
                'question_type' => 'open_text',
                'question_text' => 'Ne öğrendin?',
                'status' => 'closed',
                'show_results' => 1,
            ],
            sessionRow: [
                'id' => 10, 'status' => 'closed', 'course_id' => 5,
                'show_results_to_students' => 1, 'title' => 'Ders',
                'language' => 'tr', 'started_at' => null, 'closed_at' => null,
                'anonymized' => 0,
            ],
        );

        $this->seedAnswer(1, 1, null, 'İletişim');
        $this->seedAnswer(2, 1, null, 'iletişim');
        $this->seedAnswer(3, 1, null, 'İLETİŞİM');

        $results = $service->getResults(10, 99, 1);
        $cloud = $results[0]['word_cloud'] ?? [];

        $terms = array_column($cloud, 'term');
        $this->assertCount(1, $terms, 'i-casing variants must collapse into one term');
        $this->assertSame(3, $cloud[0]['count']);
    }
}
