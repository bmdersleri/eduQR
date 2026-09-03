<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit;

use EduQR\Contracts\CourseRepositoryInterface;
use EduQR\Contracts\QuestionRepositoryInterface;
use EduQR\Contracts\SessionRepositoryInterface;
use EduQR\Exceptions\ForbiddenException;
use EduQR\Exceptions\NotFoundException;
use EduQR\Exceptions\ValidationException;
use EduQR\Services\PollVersionService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The version queries behind a conditional poll — NFR-76, API_SPEC.md §1.9.
 *
 * A controller cannot be dispatched from this suite: every terminal method of
 * ApiController ends in `exit`, and there is no test database. So the two
 * halves of a `304` are pinned separately — that the same state yields the same
 * version and moved state a different one is asserted here, and that a matching
 * version produces an empty-bodied `304` carrying its `ETag` is asserted in
 * ApiControllerEtagTest.
 *
 * The questions and answers live in an in-memory SQLite database because the
 * results version reads them with its own SQL. Everything the version reaches
 * through a repository is mocked.
 *
 * @requirement NFR-76
 */
final class PollVersionServiceTest extends TestCase
{
    private PDO $pdo;

    /** @var array<string, mixed>|null */
    private ?array $sessionByCode = null;

    /** @var array<string, mixed>|null */
    private ?array $activeQuestion = null;

    /** @var array<string, mixed>|null */
    private ?array $sessionById = ['id' => 10, 'course_id' => 5, 'status' => 'active'];

    /** @var array<string, mixed>|null */
    private ?array $course = ['id' => 5, 'title' => 'Course'];

    private ?string $role = 'owner';

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->pdo->exec('
            CREATE TABLE questions (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id INTEGER NOT NULL,
                status     TEXT    NOT NULL DEFAULT "draft",
                updated_at TEXT    NULL
            )
        ');

        $this->pdo->exec('
            CREATE TABLE answers (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                question_id    INTEGER NOT NULL,
                participant_id INTEGER NOT NULL,
                is_hidden      INTEGER NOT NULL DEFAULT 0
            )
        ');

        $this->pdo->exec('
            CREATE TABLE options (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                question_id INTEGER NOT NULL,
                option_text TEXT    NOT NULL
            )
        ');

        $this->pdo->exec('
            CREATE TABLE question_reactions (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id     INTEGER NOT NULL,
                question_id    INTEGER NOT NULL,
                participant_id INTEGER NOT NULL,
                reaction       TEXT    NOT NULL,
                updated_at     TEXT    NULL
            )
        ');
    }

    // ── /active-question ──────────────────────────────────────────────────────

    /**
     * @requirement NFR-76
     */
    public function testTheActiveQuestionVersionIsStableWhileNothingMoves(): void
    {
        $this->sessionByCode = ['id' => 10, 'status' => 'active', 'updated_at' => '2026-09-03 10:00:00'];
        $this->activeQuestion = [
            'id' => 7,
            'status' => 'active',
            'activated_at' => '2026-09-03 10:01:00',
            'updated_at' => '2026-09-03 10:01:00',
        ];

        $service = $this->makeService();

        self::assertSame(
            $service->activeQuestionVersion('ABC123'),
            $service->activeQuestionVersion('ABC123'),
        );
    }

    /**
     * The poll that matters: a phone waiting on the next question must be told
     * when a different one is activated.
     *
     * @requirement NFR-76
     */
    public function testActivatingAnotherQuestionMovesTheActiveQuestionVersion(): void
    {
        $this->sessionByCode = ['id' => 10, 'status' => 'active', 'updated_at' => '2026-09-03 10:00:00'];
        $this->activeQuestion = null;

        $service = $this->makeService();
        $before = $service->activeQuestionVersion('ABC123');

        $this->activeQuestion = [
            'id' => 7,
            'status' => 'active',
            'activated_at' => '2026-09-03 10:01:00',
            'updated_at' => '2026-09-03 10:01:00',
        ];

        self::assertNotSame($before, $service->activeQuestionVersion('ABC123'));
    }

    /**
     * @requirement NFR-76
     */
    public function testClosingTheQuestionMovesTheActiveQuestionVersion(): void
    {
        $this->sessionByCode = ['id' => 10, 'status' => 'active', 'updated_at' => '2026-09-03 10:00:00'];
        $this->activeQuestion = [
            'id' => 7,
            'status' => 'active',
            'activated_at' => '2026-09-03 10:01:00',
            'updated_at' => '2026-09-03 10:01:00',
        ];

        $service = $this->makeService();
        $before = $service->activeQuestionVersion('ABC123');

        $this->activeQuestion['status'] = 'closed';
        $this->activeQuestion['updated_at'] = '2026-09-03 10:05:00';

        self::assertNotSame($before, $service->activeQuestionVersion('ABC123'));
    }

    /**
     * Authorization beats caching: an unknown short code is 404 before any
     * version exists to compare against.
     *
     * @requirement NFR-76
     */
    public function testAnUnknownShortCodeHasNoVersion(): void
    {
        $this->sessionByCode = null;

        $this->expectException(NotFoundException::class);
        $this->makeService()->activeQuestionVersion('NOPE12');
    }

    /**
     * A closed session is 410 whatever the caller already holds. The guard is
     * in the version query and not only in the service behind it, because the
     * version query is what runs first.
     *
     * @requirement NFR-76
     */
    public function testAClosedSessionHasNoVersion(): void
    {
        $this->sessionByCode = ['id' => 10, 'status' => 'closed', 'updated_at' => '2026-09-03 10:00:00'];

        try {
            $this->makeService()->activeQuestionVersion('ABC123');
            self::fail('A closed session must not produce a version.');
        } catch (ValidationException $e) {
            self::assertSame('session_closed', $e->getErrorCode());
            self::assertSame(410, $e->getStatus());
        }
    }

    // ── /results ──────────────────────────────────────────────────────────────

    /**
     * @requirement NFR-76
     */
    public function testTheResultsVersionIsStableWhileNothingMoves(): void
    {
        $this->givenQuestion(1, 10, 'closed', '2026-09-03 10:00:00');
        $this->givenAnswer(1, 100);

        $service = $this->makeService();

        self::assertSame(
            $service->resultsVersion(10, 42, null),
            $service->resultsVersion(10, 42, null),
        );
    }

    /**
     * @requirement NFR-76
     */
    public function testANewAnswerMovesTheResultsVersion(): void
    {
        $this->givenQuestion(1, 10, 'active', '2026-09-03 10:00:00');
        $this->givenAnswer(1, 100);

        $service = $this->makeService();
        $before = $service->resultsVersion(10, 42, null);

        $this->givenAnswer(1, 101);

        self::assertNotSame($before, $service->resultsVersion(10, 42, null));
    }

    /**
     * The SUM(is_hidden) case, and the reason API_SPEC §1.9 requires it.
     *
     * The answers table has created_at and no updated_at, so hiding an answer
     * changes neither COUNT(*) nor MAX(id). Without the sum, an instructor who
     * moderated an answer would go on being handed the response that still
     * contains it for as long as nobody else answered.
     *
     * @requirement NFR-76
     */
    public function testHidingAnAnswerMovesTheResultsVersion(): void
    {
        $this->givenQuestion(1, 10, 'closed', '2026-09-03 10:00:00');
        $this->givenAnswer(1, 100);
        $this->givenAnswer(1, 101);

        $service = $this->makeService();
        $before = $service->resultsVersion(10, 42, null);

        $this->pdo->exec('UPDATE answers SET is_hidden = 1 WHERE participant_id = 101');
        $after = $service->resultsVersion(10, 42, null);

        self::assertNotSame($before, $after, 'Hiding an answer must change the results version.');

        // And the count and the maximum id really did not move, which is what
        // makes the sum load-bearing rather than belt-and-braces.
        $row = $this->pdo->query('SELECT COUNT(*) AS c, MAX(id) AS m FROM answers WHERE question_id = 1')
            ->fetch(PDO::FETCH_ASSOC);
        self::assertSame(2, (int) $row['c']);
        self::assertSame(2, (int) $row['m']);
    }

    /**
     * @requirement NFR-76
     */
    public function testReopeningAQuestionMovesTheResultsVersion(): void
    {
        $this->givenQuestion(1, 10, 'closed', '2026-09-03 10:00:00');

        $service = $this->makeService();
        $before = $service->resultsVersion(10, 42, null);

        $this->pdo->exec("UPDATE questions SET status = 'active', updated_at = '2026-09-03 11:00:00' WHERE id = 1");

        self::assertNotSame($before, $service->resultsVersion(10, 42, null));
    }

    /**
     * A question added to the session changes the version of the whole-session
     * poll, which is the request the instructor results screen actually makes.
     *
     * @requirement NFR-76
     */
    public function testAddingAQuestionMovesTheWholeSessionResultsVersion(): void
    {
        $this->givenQuestion(1, 10, 'closed', '2026-09-03 10:00:00');

        $service = $this->makeService();
        $before = $service->resultsVersion(10, 42, null);

        $this->givenQuestion(2, 10, 'draft', '2026-09-03 10:30:00');

        self::assertNotSame($before, $service->resultsVersion(10, 42, null));
    }

    /**
     * Narrowing to one question is a different answer, so it is a different
     * version — an ETag that covered more than the body carries would let a
     * browser reuse the wrong response.
     *
     * @requirement NFR-76
     */
    public function testOneQuestionAndTheWholeSessionHaveDifferentVersions(): void
    {
        $this->givenQuestion(1, 10, 'closed', '2026-09-03 10:00:00');
        $this->givenQuestion(2, 10, 'closed', '2026-09-03 10:00:00');

        $service = $this->makeService();

        self::assertNotSame(
            $service->resultsVersion(10, 42, null),
            $service->resultsVersion(10, 42, 1),
        );
    }

    /**
     * Ruling 2, the security case: a caller with no role on the course is
     * refused, and the refusal happens before a version exists to match.
     *
     * @requirement NFR-76
     */
    public function testACallerWithNoRoleOnTheCourseGetsNoResultsVersion(): void
    {
        $this->givenQuestion(1, 10, 'closed', '2026-09-03 10:00:00');
        $this->role = null;

        $this->expectException(ForbiddenException::class);
        $this->makeService()->resultsVersion(10, 42, null);
    }

    /**
     * @requirement NFR-76
     */
    public function testAnUnknownSessionGetsNoResultsVersion(): void
    {
        $this->sessionById = null;

        $this->expectException(NotFoundException::class);
        $this->makeService()->resultsVersion(10, 42, null);
    }

    /**
     * A question id belonging to another session is answered the way
     * getResults() answers it — not found, rather than a version of somebody
     * else's question.
     *
     * @requirement NFR-76
     */
    public function testAQuestionFromAnotherSessionGetsNoResultsVersion(): void
    {
        $this->givenQuestion(1, 10, 'closed', '2026-09-03 10:00:00');
        $this->givenQuestion(9, 99, 'closed', '2026-09-03 10:00:00');

        try {
            $this->makeService()->resultsVersion(10, 42, 9);
            self::fail('A question from another session must not produce a version.');
        } catch (NotFoundException $e) {
            self::assertSame('question_not_found', $e->getErrorCode());
        }
    }

    /**
     * A session with no questions still has a version, and it is not an error.
     *
     * @requirement NFR-76
     */
    public function testASessionWithNoQuestionsHasAnEmptyButStableVersion(): void
    {
        $service = $this->makeService();

        self::assertSame('', $service->resultsVersion(10, 42, null));
    }

    // ── /questions ────────────────────────────────────────────────────────────

    /**
     * @requirement NFR-76
     */
    public function testTheQuestionsVersionIsStableWhileNothingMoves(): void
    {
        $this->givenQuestion(1, 10, 'draft', '2026-09-03 10:00:00');
        $this->givenOption(1, 'A');

        $service = $this->makeService();

        self::assertSame(
            $service->questionsVersion(10, 42),
            $service->questionsVersion(10, 42),
        );
    }

    /**
     * @requirement NFR-76
     */
    public function testAddingAQuestionMovesTheQuestionsVersion(): void
    {
        $this->givenQuestion(1, 10, 'draft', '2026-09-03 10:00:00');

        $service = $this->makeService();
        $before = $service->questionsVersion(10, 42);

        $this->givenQuestion(2, 10, 'draft', '2026-09-03 10:00:00');

        self::assertNotSame($before, $service->questionsVersion(10, 42));
    }

    /**
     * @requirement NFR-76
     */
    public function testEditingAQuestionMovesTheQuestionsVersion(): void
    {
        $this->givenQuestion(1, 10, 'draft', '2026-09-03 10:00:00');

        $service = $this->makeService();
        $before = $service->questionsVersion(10, 42);

        $this->pdo->exec("UPDATE questions SET updated_at = '2026-09-03 10:04:00' WHERE id = 1");

        self::assertNotSame($before, $service->questionsVersion(10, 42));
    }

    /**
     * Why this version reads more than API_SPEC §1.9 names for it.
     *
     * QuestionService::update() replaces the options of a draft question
     * through OptionRepository, and does not write the question row when only
     * the options were submitted. Without the options in the version, an
     * instructor who rewrote the choices would be handed a 304 and go on seeing
     * the old ones.
     *
     * @requirement NFR-76
     */
    public function testReplacingOnlyTheOptionsMovesTheQuestionsVersion(): void
    {
        $this->givenQuestion(1, 10, 'draft', '2026-09-03 10:00:00');
        $this->givenOption(1, 'A');
        $this->givenOption(1, 'B');

        $service = $this->makeService();
        $before = $service->questionsVersion(10, 42);

        // Exactly what an options-only edit does: delete, then insert again.
        $this->pdo->exec('DELETE FROM options WHERE question_id = 1');
        $this->givenOption(1, 'C');
        $this->givenOption(1, 'D');

        self::assertNotSame($before, $service->questionsVersion(10, 42));

        // And the question row really did not move, which is what makes the
        // second read load-bearing rather than belt-and-braces.
        $row = $this->pdo->query('SELECT COUNT(*) AS c, MAX(updated_at) AS m FROM questions WHERE session_id = 10')
            ->fetch(PDO::FETCH_ASSOC);
        self::assertSame(1, (int) $row['c']);
        self::assertSame('2026-09-03 10:00:00', $row['m']);
    }

    /**
     * Another session's questions are not this session's version.
     *
     * @requirement NFR-76
     */
    public function testTheQuestionsVersionIsScopedToItsSession(): void
    {
        $this->givenQuestion(1, 10, 'draft', '2026-09-03 10:00:00');

        $service = $this->makeService();
        $before = $service->questionsVersion(10, 42);

        $this->givenQuestion(9, 99, 'draft', '2026-09-03 11:00:00');

        self::assertSame($before, $service->questionsVersion(10, 42));
    }

    /**
     * Ruling 2 again, on the second polled endpoint of the detail screen.
     *
     * @requirement NFR-76
     */
    public function testACallerWithNoRoleOnTheCourseGetsNoQuestionsVersion(): void
    {
        $this->givenQuestion(1, 10, 'draft', '2026-09-03 10:00:00');
        $this->role = null;

        $this->expectException(ForbiddenException::class);
        $this->makeService()->questionsVersion(10, 42);
    }

    // ── /reactions ────────────────────────────────────────────────────────────

    /**
     * @requirement NFR-76
     */
    public function testTheReactionsVersionIsStableWhileNothingMoves(): void
    {
        $this->givenQuestion(1, 10, 'active', '2026-09-03 10:00:00');
        $this->givenReaction(10, 1, 500, '2026-09-03 10:02:00');

        $service = $this->makeService();

        self::assertSame(
            $service->reactionsVersion(10, 42),
            $service->reactionsVersion(10, 42),
        );
    }

    /**
     * @requirement NFR-76
     */
    public function testANewReactionMovesTheReactionsVersion(): void
    {
        $this->givenQuestion(1, 10, 'active', '2026-09-03 10:00:00');
        $this->givenReaction(10, 1, 500, '2026-09-03 10:02:00');

        $service = $this->makeService();
        $before = $service->reactionsVersion(10, 42);

        $this->givenReaction(10, 1, 501, '2026-09-03 10:03:00');

        self::assertNotSame($before, $service->reactionsVersion(10, 42));
    }

    /**
     * Reacting again is an upsert, so the count does not move and only
     * updated_at does — which is why §1.9 asks for both.
     *
     * @requirement NFR-76
     */
    public function testChangingAnExistingReactionMovesTheReactionsVersion(): void
    {
        $this->givenQuestion(1, 10, 'active', '2026-09-03 10:00:00');
        $this->givenReaction(10, 1, 500, '2026-09-03 10:02:00');

        $service = $this->makeService();
        $before = $service->reactionsVersion(10, 42);

        $this->pdo->exec(
            "UPDATE question_reactions SET reaction = 'lost', updated_at = '2026-09-03 10:06:00'"
            . ' WHERE participant_id = 500'
        );

        self::assertNotSame($before, $service->reactionsVersion(10, 42));
    }

    /**
     * The other reason this version reads more than §1.9 names for it: the body
     * carries a zeroed row for every question in the session, so adding a
     * question changes the response while leaving the reactions untouched.
     *
     * @requirement NFR-76
     */
    public function testAddingAQuestionMovesTheReactionsVersion(): void
    {
        $this->givenQuestion(1, 10, 'active', '2026-09-03 10:00:00');
        $this->givenReaction(10, 1, 500, '2026-09-03 10:02:00');

        $service = $this->makeService();
        $before = $service->reactionsVersion(10, 42);

        $this->givenQuestion(2, 10, 'draft', '2026-09-03 10:07:00');

        self::assertNotSame($before, $service->reactionsVersion(10, 42));
    }

    /**
     * @requirement NFR-76
     */
    public function testACallerWithNoRoleOnTheCourseGetsNoReactionsVersion(): void
    {
        $this->givenQuestion(1, 10, 'active', '2026-09-03 10:00:00');
        $this->role = null;

        $this->expectException(ForbiddenException::class);
        $this->makeService()->reactionsVersion(10, 42);
    }

    /**
     * @requirement NFR-76
     */
    public function testAnUnknownSessionGetsNoReactionsVersion(): void
    {
        $this->sessionById = null;

        $this->expectException(NotFoundException::class);
        $this->makeService()->reactionsVersion(10, 42);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function givenQuestion(int $id, int $sessionId, string $status, string $updatedAt): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO questions (id, session_id, status, updated_at) VALUES (?, ?, ?, ?)'
        );
        $statement->execute([$id, $sessionId, $status, $updatedAt]);
    }

    private function givenAnswer(int $questionId, int $participantId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO answers (question_id, participant_id) VALUES (?, ?)'
        );
        $statement->execute([$questionId, $participantId]);
    }

    private function givenOption(int $questionId, string $text): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO options (question_id, option_text) VALUES (?, ?)'
        );
        $statement->execute([$questionId, $text]);
    }

    private function givenReaction(int $sessionId, int $questionId, int $participantId, string $updatedAt): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO question_reactions (session_id, question_id, participant_id, reaction, updated_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $statement->execute([$sessionId, $questionId, $participantId, 'got_it', $updatedAt]);
    }

    /**
     * The repositories answer from the properties above, so a test can move the
     * state between two calls to the same service instance — which is exactly
     * what a second poll does.
     */
    private function makeService(): PollVersionService
    {
        $sessions = $this->createMock(SessionRepositoryInterface::class);
        $sessions->method('findByShortCode')->willReturnCallback(fn (): ?array => $this->sessionByCode);
        $sessions->method('findById')->willReturnCallback(fn (): ?array => $this->sessionById);

        $questions = $this->createMock(QuestionRepositoryInterface::class);
        $questions->method('findActiveBySessionCode')->willReturnCallback(fn (): ?array => $this->activeQuestion);

        $courses = $this->createMock(CourseRepositoryInterface::class);
        $courses->method('findById')->willReturnCallback(fn (): ?array => $this->course);
        $courses->method('roleFor')->willReturnCallback(fn (): ?string => $this->role);

        return new PollVersionService($sessions, $questions, $courses, $this->pdo);
    }
}
