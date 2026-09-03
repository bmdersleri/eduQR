<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit;

use EduQR\Contracts\QuestionRepositoryInterface;
use EduQR\Services\ScoringService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ScoringService — T-1130.
 *
 * The scoring unit addressed directly rather than through a report or a
 * gradebook (NFR-82). Its three methods read rows, so the tests use an
 * in-memory SQLite DB and mock the one repository it collaborates with.
 *
 * @requirement NFR-82
 */
final class ScoringServiceTest extends TestCase
{
    private const SESSION_ID = 10;

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

    /** @param array<int,int> $questionIds */
    private function makeService(array $questionIds): ScoringService
    {
        $questions = $this->createMock(QuestionRepositoryInterface::class);
        $questions->method('findBySession')->willReturn(array_map(
            static fn (int $id): array => [
                'id' => $id,
                'session_id' => self::SESSION_ID,
                'question_type' => 'multiple_choice',
                'question_text' => 'Q' . $id,
                'status' => 'closed',
                'show_results' => 1,
            ],
            $questionIds
        ));

        return new ScoringService($questions, $this->pdo);
    }

    /** @param array<int,array{0:string,1:int}> $options text, is_correct */
    private function seedOptions(int $questionId, array $options): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO options (question_id, option_text, option_value, is_correct, order_no) VALUES (?,?,?,?,?)'
        );
        foreach ($options as $i => [$text, $isCorrect]) {
            $stmt->execute([$questionId, $text, (string) ($i + 1), $isCorrect, $i + 1]);
        }
    }

    private function seedParticipant(int $id, string $nickname): void
    {
        $this->pdo->prepare('INSERT INTO participants (id, session_id, nickname) VALUES (?,?,?)')
            ->execute([$id, self::SESSION_ID, $nickname]);
    }

    private function seedAnswer(int $participantId, int $questionId, ?int $optionId, ?string $text = null): void
    {
        $this->pdo->prepare(
            'INSERT INTO answers (question_id, participant_id, selected_option_id, answer_text) VALUES (?,?,?,?)'
        )->execute([$questionId, $participantId, $optionId, $text]);
    }

    // ── computeScores (FR-92) ─────────────────────────────────────────────────

    public function testComputeScoresAwardsOnePointPerCorrectSelectedOption_FR92(): void
    {
        $this->seedOptions(1, [['Right', 1], ['Wrong', 0]]);   // option ids 1, 2
        $this->seedOptions(2, [['Also right', 1], ['Nope', 0]]); // option ids 3, 4

        $this->seedParticipant(1, 'Elif');
        $this->seedParticipant(2, 'İsmail');
        $this->seedAnswer(1, 1, 1);
        $this->seedAnswer(1, 2, 3);
        $this->seedAnswer(2, 1, 2);

        $scores = $this->makeService([1, 2])->computeScores(self::SESSION_ID);

        $this->assertCount(2, $scores);
        $this->assertSame(
            ['participant_id' => 1, 'nickname' => 'Elif', 'score' => 2, 'rank' => 1],
            $scores[0]
        );
        $this->assertSame(
            ['participant_id' => 2, 'nickname' => 'İsmail', 'score' => 0, 'rank' => 2],
            $scores[1]
        );
    }

    /** A tie keeps participant-id order and shares one rank, so the list is stable. */
    public function testComputeScoresGivesTiedParticipantsTheSameRank_FR92(): void
    {
        $this->seedOptions(1, [['Right', 1], ['Wrong', 0]]);

        $this->seedParticipant(1, 'Ada');
        $this->seedParticipant(2, 'Bora');
        $this->seedParticipant(3, 'Cem');
        $this->seedAnswer(1, 1, 1);
        $this->seedAnswer(2, 1, 1);
        $this->seedAnswer(3, 1, 2);

        $scores = $this->makeService([1])->computeScores(self::SESSION_ID);

        $this->assertSame([1, 2, 3], array_column($scores, 'participant_id'));
        $this->assertSame([1, 1, 3], array_column($scores, 'rank'));
        $this->assertSame([1, 1, 0], array_column($scores, 'score'));
    }

    /** NFR-77 / FR-31: a typed answer is matched with the Turkish-correct fold. */
    public function testComputeScoresMatchesTypedAnswersWithTheTurkishFold_NFR77(): void
    {
        $this->seedOptions(1, [['İstanbul', 1]]);

        $this->seedParticipant(1, 'Ada');
        $this->seedParticipant(2, 'Bora');
        $this->seedAnswer(1, 1, null, '  istanbul ');
        $this->seedAnswer(2, 1, null, 'ISTANBULSPOR');

        $scores = $this->makeService([1])->computeScores(self::SESSION_ID);

        $this->assertSame(1, $scores[0]['score']);
        $this->assertSame(0, $scores[1]['score']);
    }

    public function testComputeScoresIsEmptyForASessionWithoutParticipants(): void
    {
        $this->assertSame([], $this->makeService([1])->computeScores(self::SESSION_ID));
    }

    // ── maxScore (FR-92) ──────────────────────────────────────────────────────

    public function testMaxScoreCountsOnlyQuestionsThatHaveACorrectOption_FR92(): void
    {
        $this->seedOptions(1, [['Right', 1], ['Wrong', 0]]);
        $this->seedOptions(2, [['Also right', 1], ['Nope', 0]]);
        $this->seedOptions(3, [['Coffee', 0], ['Tea', 0]]);

        $this->assertSame(2, $this->makeService([1, 2, 3])->maxScore(self::SESSION_ID));
    }

    public function testMaxScoreIsZeroForASessionWithoutQuestions(): void
    {
        $this->assertSame(0, $this->makeService([])->maxScore(self::SESSION_ID));
    }

    // ── correctOptions ────────────────────────────────────────────────────────

    public function testCorrectOptionsAreKeyedByQuestionAndExcludeWrongOnes(): void
    {
        $this->seedOptions(1, [['Push', 1], ['Append', 0], ['Pop', 1]]);
        $this->seedOptions(2, [['Coffee', 0], ['Tea', 0]]);

        $correct = $this->makeService([1, 2])->correctOptions(self::SESSION_ID);

        $this->assertSame([1], array_keys($correct));
        $this->assertSame(['Push', 'Pop'], array_column($correct[1], 'text'));
        $this->assertSame([1, 3], array_column($correct[1], 'id'));
    }

    public function testCorrectOptionsIsEmptyForASessionWithoutQuestions(): void
    {
        $this->assertSame([], $this->makeService([])->correctOptions(self::SESSION_ID));
    }
}
