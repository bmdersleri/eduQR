<?php

declare(strict_types=1);

namespace EduQR\Tests\Integration;

use EduQR\Contracts\OptionRepositoryInterface;
use EduQR\Contracts\ParticipantRepositoryInterface;
use EduQR\Contracts\QuestionRepositoryInterface;
use EduQR\Contracts\SessionRepositoryInterface;
use EduQR\Repositories\AnswerRepository;
use EduQR\Services\AnswerService;
use EduQR\Services\DuplicateAnswerException;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Integration test — T-714
 *
 * Tests the full answer submission flow including:
 * - Closed-question rejection
 * - Successful option submission
 * - Successful open-text submission
 * - Duplicate answer rejection (409)
 * - Open-text sanitization
 *
 * Uses a real AnswerRepository backed by an in-memory SQLite database.
 * Domain stubs (QuestionRepository, SessionRepository, etc.) are mocked
 * since their tables would require full MySQL-specific schema.
 */
class AnswerFlowTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        // ── In-memory SQLite for AnswerRepository ──────────────────────────────
        $this->pdo = new PDO('sqlite::memory:', options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // Minimal answers table matching the migration (SQLite-compatible subset)
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
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /** Build AnswerService wired to the real in-memory AnswerRepository. */
    private function makeService(
        string $questionStatus = 'active',
        string $sessionStatus = 'active',
        int    $participantSession = 10,
        string $questionType = 'multiple_choice',
        ?array $optionRow = null,
    ): AnswerService {
        $question = [
            'id' => 99,
            'session_id' => 10,
            'question_type' => $questionType,
            'status' => $questionStatus,
        ];

        $option = $optionRow ?? ['id' => 5, 'question_id' => 99];

        $questions = $this->createMock(QuestionRepositoryInterface::class);
        $questions->method('findById')->willReturn($question);

        $sessions = $this->createMock(SessionRepositoryInterface::class);
        $sessions->method('findById')->willReturn(['id' => 10, 'status' => $sessionStatus]);

        $participants = $this->createMock(ParticipantRepositoryInterface::class);
        $participants->method('findById')
                     ->willReturn(['id' => 1, 'session_id' => $participantSession]);

        $options = $this->createMock(OptionRepositoryInterface::class);
        $options->method('findById')->willReturn($option);

        // Real AnswerRepository backed by SQLite
        $answerRepo = new AnswerRepository($this->pdo);

        return new AnswerService($answerRepo, $questions, $sessions, $participants, $options);
    }

    // ── T-714: closed-question rejection ──────────────────────────────────────

    public function testClosedQuestionIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('question_not_active');

        $service = $this->makeService(questionStatus: 'closed');
        $service->submit(1, ['question_id' => 99, 'selected_option_id' => 5]);
    }

    // ── T-714: paused / closed session rejection ──────────────────────────────

    public function testPausedSessionIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('session_paused');

        $service = $this->makeService(sessionStatus: 'paused');
        $service->submit(1, ['question_id' => 99, 'selected_option_id' => 5]);
    }

    public function testClosedSessionIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('session_closed');

        $service = $this->makeService(sessionStatus: 'closed');
        $service->submit(1, ['question_id' => 99, 'selected_option_id' => 5]);
    }

    // ── T-714: full successful option answer ──────────────────────────────────

    public function testSuccessfulOptionAnswerPersists(): void
    {
        $service = $this->makeService();
        $answerId = $service->submit(1, ['question_id' => 99, 'selected_option_id' => 5]);

        $this->assertGreaterThan(0, $answerId);

        // Verify row actually written to DB
        $row = $this->pdo->query("SELECT * FROM answers WHERE id = {$answerId}")->fetch();
        $this->assertIsArray($row);
        $this->assertSame(99, (int) $row['question_id']);
        $this->assertSame(1, (int) $row['participant_id']);
        $this->assertSame(5, (int) $row['selected_option_id']);
        $this->assertNull($row['answer_text']);
    }

    // ── T-714: full successful open-text answer ───────────────────────────────

    public function testSuccessfulOpenTextAnswerPersists(): void
    {
        $service = $this->makeService(questionType: 'open_text');
        $answerId = $service->submit(1, [
            'question_id' => 99,
            'answer_text' => '<b>Great</b> lecture!',
        ]);

        $this->assertGreaterThan(0, $answerId);

        $row = $this->pdo->query("SELECT * FROM answers WHERE id = {$answerId}")->fetch();
        $this->assertIsArray($row);
        $this->assertNull($row['selected_option_id']);
        // HTML tags stripped
        $this->assertSame('Great lecture!', $row['answer_text']);
    }

    // ── T-714: duplicate prevention ───────────────────────────────────────────

    public function testDuplicateAnswerThrowsDuplicateAnswerException(): void
    {
        $this->expectException(DuplicateAnswerException::class);

        $service = $this->makeService();

        // First answer — should succeed
        $service->submit(1, ['question_id' => 99, 'selected_option_id' => 5]);

        // Second answer by the same participant → must throw
        $service->submit(1, ['question_id' => 99, 'selected_option_id' => 5]);
    }

    // ── T-714: repository countByQuestion ─────────────────────────────────────

    public function testCountByQuestionReflectsAnswers(): void
    {
        $service = $this->makeService();
        $repo = new AnswerRepository($this->pdo);

        $this->assertSame(0, $repo->countByQuestion(99));

        $service->submit(1, ['question_id' => 99, 'selected_option_id' => 5]);
        $this->assertSame(1, $repo->countByQuestion(99));
    }

    // ── T-714: fetchByQuestion ────────────────────────────────────────────────

    public function testFetchByQuestionReturnsRows(): void
    {
        $service = $this->makeService();
        $repo = new AnswerRepository($this->pdo);

        $this->assertCount(0, $repo->fetchByQuestion(99));

        $service->submit(1, ['question_id' => 99, 'selected_option_id' => 5]);
        $rows = $repo->fetchByQuestion(99);

        $this->assertCount(1, $rows);
        $this->assertSame(5, (int) $rows[0]['selected_option_id']);
    }
}
