<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit;

use EduQR\Contracts\CourseRepositoryInterface;
use EduQR\Contracts\QuestionRepositoryInterface;
use EduQR\Contracts\SessionRepositoryInterface;
use EduQR\Services\ReportBuilder;
use EduQR\Services\ScoringService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ReportBuilder — T-811, T-912, T-1130.
 *
 * Split out of ReportServiceTest unchanged when report assembly gained its own
 * class (NFR-82); every assertion below is the one it had there. The report
 * counts rows with raw SQL, so the tests use an in-memory SQLite DB and mock
 * the repositories.
 *
 * @requirement NFR-82
 */
class ReportBuilderTest extends TestCase
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
    ): ReportBuilder {
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

        return new ReportBuilder(
            $sessions,
            $questions,
            $courses,
            $this->pdo,
            new ScoringService($questions, $this->pdo),
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

    // ── FR-66: word cloud on the report ───────────────────────────────────────

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

    /**
     * NFR-77 / FR-92: a Turkish answer differing only in dotted/dotless I casing
     * must score as correct. The old SQL LOWER(TRIM(...)) match could not do
     * this: SQLite's LOWER() is ASCII-only and leaves 'İ' alone, and MySQL's
     * yields "i" + U+0307, so neither matched a plain "i".
     */
    public function testTurkishDottedIAnswerScoresCorrect_NFR77(): void
    {
        $this->seedCorrectOption(1, 'istanbul');

        $this->seedAnswer(1, 1, null, 'İstanbul');   // dotted capital I
        $this->seedAnswer(2, 1, null, 'ISTANBUL');   // dotless capital I
        $this->seedAnswer(3, 1, null, 'Ankara');     // genuinely different

        $byParticipant = $this->scoresByParticipant($this->makeTurkishQuizService());

        $this->assertSame(1, $byParticipant[1]);
        $this->assertSame(1, $byParticipant[2]);
        $this->assertSame(0, $byParticipant[3]);
    }

    /**
     * NFR-77: the same fold applied to a Turkish correct answer — the instructor
     * types the dotted form, the student types the plain one.
     */
    public function testTurkishDottedICorrectAnswerMatchesPlainTypedAnswer_NFR77(): void
    {
        $this->seedCorrectOption(1, 'İzmir');

        $this->seedAnswer(1, 1, null, 'izmir');
        $this->seedAnswer(2, 1, null, 'IZMIR');
        $this->seedAnswer(3, 1, null, 'Bursa');

        $byParticipant = $this->scoresByParticipant($this->makeTurkishQuizService());

        $this->assertSame(1, $byParticipant[1]);
        $this->assertSame(1, $byParticipant[2]);
        $this->assertSame(0, $byParticipant[3]);
    }

    /**
     * NFR-77: the Turkish-aware fold must not regress English. A naive Turkish
     * fold maps I → ı, which would stop "MITOCHONDRIA" matching "mitochondria".
     * The comparison must also not vary with the active locale.
     */
    public function testEnglishICasingStillScoresCorrect_NFR77(): void
    {
        $this->seedCorrectOption(1, 'mitochondria');

        $this->seedAnswer(1, 1, null, 'MITOCHONDRIA');
        $this->seedAnswer(2, 1, null, 'Mitochondria');
        $this->seedAnswer(3, 1, null, 'ribosome');

        $byParticipant = $this->scoresByParticipant($this->makeTurkishQuizService('en'));

        $this->assertSame(1, $byParticipant[1]);
        $this->assertSame(1, $byParticipant[2]);
        $this->assertSame(0, $byParticipant[3]);
    }

    /** NFR-77: an answer that merely shares a prefix must still score wrong. */
    public function testDifferentAnswerStillScoresWrong_NFR77(): void
    {
        $this->seedCorrectOption(1, 'İstanbul');

        $this->seedAnswer(1, 1, null, 'İstanbulspor');
        $this->seedAnswer(2, 1, null, '');
        $this->seedAnswer(3, 1, null, 'İstanbul');

        $byParticipant = $this->scoresByParticipant($this->makeTurkishQuizService());

        $this->assertSame(0, $byParticipant[1]);
        $this->assertSame(0, $byParticipant[2]);
        $this->assertSame(1, $byParticipant[3]);
    }

    private function makeTurkishQuizService(string $language = 'tr'): ReportBuilder
    {
        return $this->makeService(
            questionRow: [
                'id' => 1,
                'session_id' => 10,
                'question_type' => 'fill_in_the_blank',
                'question_text' => 'Türkiye\'nin en kalabalık şehri ____.',
                'status' => 'closed',
                'show_results' => 1,
            ],
            sessionRow: [
                'id' => 10, 'status' => 'closed', 'course_id' => 5,
                'show_results_to_students' => 1, 'title' => 'Sınav',
                'language' => $language, 'started_at' => null, 'closed_at' => null,
                'anonymized' => 0, 'is_quiz' => 1,
            ],
        );
    }

    /** @return array<int,int> participant id → score */
    private function scoresByParticipant(ReportBuilder $service): array
    {
        $report = $service->buildReport(10, 99);
        $this->assertArrayHasKey('scores', $report);

        $byParticipant = [];
        foreach ($report['scores'] as $row) {
            $byParticipant[(int) $row['participant_id']] = (int) $row['score'];
        }

        return $byParticipant;
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
        $courses->method('roleFor')->willReturnCallback(
            static fn (int $courseId, int $userId): ?string => $userId === 99 ? 'owner' : null
        );

        $service = new ReportBuilder(
            $sessions,
            $questions,
            $courses,
            $this->pdo,
            new ScoringService($questions, $this->pdo),
        );
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

        $service = new ReportBuilder(
            $sessions,
            $questions,
            $courses,
            $this->pdo,
            new ScoringService($questions, $this->pdo),
        );
        $service->buildReport(99, 1);
    }

    // ── Co-instructor access (FR-97) ───────────────────────────────────────────

    /** Course 5 is owned by 99; user 20 co-instructs it, user 77 is unrelated. */
    private function makeServiceWithCoInstructor(): ReportBuilder
    {
        $sessionRow = [
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
        ];

        $questions = $this->createMock(QuestionRepositoryInterface::class);
        $questions->method('findBySession')->willReturn([]);

        $sessions = $this->createMock(SessionRepositoryInterface::class);
        $sessions->method('findById')->willReturn($sessionRow);
        $sessions->method('listByCourse')->willReturn([$sessionRow]);

        $courses = $this->createMock(CourseRepositoryInterface::class);
        $courses->method('findById')->willReturn([
            'id' => 5,
            'instructor_id' => 99,
            'title' => 'CS',
            'status' => 'active',
            'code' => 'CSE203',
            'semester' => '2026-Spring',
        ]);
        $courses->method('roleFor')->willReturnCallback(
            static fn (int $courseId, int $userId): ?string => match ($userId) {
                99 => 'owner',
                20 => 'co_instructor',
                default => null,
            }
        );

        return new ReportBuilder(
            $sessions,
            $questions,
            $courses,
            $this->pdo,
            new ScoringService($questions, $this->pdo),
        );
    }

    public function testBuildReportAllowedForCoInstructor_FR97(): void
    {
        $report = $this->makeServiceWithCoInstructor()->buildReport(10, 20);
        $this->assertSame(10, (int) $report['session']['id']);
    }

    public function testBuildReportStillForbiddenForStranger_FR97(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('forbidden');
        $this->makeServiceWithCoInstructor()->buildReport(10, 77);
    }
}
