<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit;

use EduQR\Contracts\CourseRepositoryInterface;
use EduQR\Contracts\QuestionRepositoryInterface;
use EduQR\Contracts\SessionRepositoryInterface;
use EduQR\Services\CourseAnalyticsService;
use EduQR\Services\ReportBuilder;
use EduQR\Services\ScoringService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CourseAnalyticsService — T-1130.
 *
 * Split out of ReportServiceTest unchanged when course analytics gained its
 * own class (NFR-82); every assertion below is the one it had there. The unit
 * takes no connection of its own, but the report unit it rolls up does, so the
 * fixtures still seed an in-memory SQLite DB.
 *
 * @requirement NFR-82
 */
class CourseAnalyticsServiceTest extends TestCase
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
    ): CourseAnalyticsService {
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

        $course = $courseRow ?? ['id' => 5, 'instructor_id' => 99];

        $courses = $this->createMock(CourseRepositoryInterface::class);
        $courses->method('findById')->willReturn($course);
        // FR-97: the owner is the only instructor on these fixtures.
        $courses->method('roleFor')->willReturnCallback(
            static fn (int $courseId, int $userId): ?string => (int) $course['instructor_id'] === $userId
                ? 'owner'
                : null
        );

        return new CourseAnalyticsService(
            $sessions,
            $courses,
            new ReportBuilder($sessions, $questions, $courses, $this->pdo, new ScoringService($questions, $this->pdo)),
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

    // ── FR-64: course analytics ───────────────────────────────────────────────

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

    // ── Co-instructor access (FR-97) ───────────────────────────────────────────

    /** Course 5 is owned by 99; user 20 co-instructs it, user 77 is unrelated. */
    private function makeServiceWithCoInstructor(): CourseAnalyticsService
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

        return new CourseAnalyticsService(
            $sessions,
            $courses,
            new ReportBuilder($sessions, $questions, $courses, $this->pdo, new ScoringService($questions, $this->pdo)),
        );
    }

    public function testBuildCourseAnalyticsAllowedForCoInstructor_FR97(): void
    {
        $analytics = $this->makeServiceWithCoInstructor()->buildCourseAnalytics(5, 20);
        $this->assertSame('CS', $analytics['course']['title']);
    }

    public function testBuildCourseAnalyticsStillForbiddenForStranger_FR97(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('forbidden');
        $this->makeServiceWithCoInstructor()->buildCourseAnalytics(5, 77);
    }
}
