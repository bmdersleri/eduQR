<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit;

use EduQR\Contracts\AnswerRepositoryInterface;
use EduQR\Contracts\OptionRepositoryInterface;
use EduQR\Contracts\ParticipantRepositoryInterface;
use EduQR\Contracts\QuestionRepositoryInterface;
use EduQR\Contracts\SessionRepositoryInterface;
use EduQR\Exceptions\DuplicateAnswerException;
use EduQR\Services\AnswerService;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AnswerService — T-713
 *
 * All external dependencies (repositories) are mocked so no database is needed.
 */
class AnswerServiceTest extends TestCase
{
    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Build a fully-wired AnswerService from per-test stubs.
     *
     * All parameters are optional; pass only what your test needs.
     */
    private function makeService(
        ?array $participantRow = null,
        ?array $questionRow = null,
        ?array $sessionRow = null,
        ?array $optionRow = null,
        bool   $alreadyAnswered = false,
        bool   $throwDuplicate = false,
    ): AnswerService {
        // Defaults that represent a valid, happy-path state
        $participant = $participantRow ?? [
            'id' => 1,
            'session_id' => 10,
        ];

        $question = $questionRow ?? [
            'id' => 99,
            'session_id' => 10,
            'question_type' => 'multiple_choice',
            'status' => 'active',
        ];

        $session = $sessionRow ?? [
            'id' => 10,
            'status' => 'active',
        ];

        $option = $optionRow ?? [
            'id' => 5,
            'question_id' => 99,
        ];

        // ── Answers repo ──────────────────────────────────────────────────────
        $answers = $this->createMock(AnswerRepositoryInterface::class);
        $answers->method('existsByParticipantAndQuestion')
                ->willReturn($alreadyAnswered);

        if ($throwDuplicate) {
            $pdo = new PDOException('Duplicate entry', 0);
            $pdo->errorInfo = ['23000', 1062, 'Duplicate entry'];
            // PDOException::getCode() returns the first constructor arg
            $pdoEx = new PDOException('Duplicate entry');
            // We need SQLSTATE '23000' — use a helper closure
            $answers->method('insert')
                    ->willThrowException($this->makeDuplicatePdoException());
        } else {
            $answers->method('insert')->willReturn(42);
        }

        // ── Questions repo ────────────────────────────────────────────────────
        $questions = $this->createMock(QuestionRepositoryInterface::class);
        $questions->method('findById')->willReturn($question);

        // ── Sessions repo ─────────────────────────────────────────────────────
        $sessions = $this->createMock(SessionRepositoryInterface::class);
        $sessions->method('findById')->willReturn($session);

        // ── Participants repo ─────────────────────────────────────────────────
        $participants = $this->createMock(ParticipantRepositoryInterface::class);
        $participants->method('findById')->willReturn($participant);

        // ── Options repo ──────────────────────────────────────────────────────
        $options = $this->createMock(OptionRepositoryInterface::class);
        $options->method('findById')->willReturn($option);

        return new AnswerService($answers, $questions, $sessions, $participants, $options);
    }

    /** Build a PDOException that has SQLSTATE '23000'. */
    private function makeDuplicatePdoException(): PDOException
    {
        // PDOException stores SQLSTATE as the exception's "code" property
        $e = new class ('Duplicate entry', 0) extends PDOException {
            public function __construct(string $msg, int $code)
            {
                parent::__construct($msg, $code);
                $this->code = '23000'; // Override string SQLSTATE
            }
        };

        return $e;
    }

    // ── T-702: validateAnswerShape — option-based ──────────────────────────────

    public function testValidOptionAnswerReturnsIds(): void
    {
        $service = $this->makeService();

        $question = ['id' => 99, 'question_type' => 'multiple_choice', 'session_id' => 10, 'status' => 'active'];
        [$optId, $text] = $service->validateAnswerShape($question, ['selected_option_id' => 5]);

        $this->assertSame(5, $optId);
        $this->assertNull($text);
    }

    public function testMissingOptionIdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('selected_option_id:required');

        $service = $this->makeService();
        $question = ['id' => 99, 'question_type' => 'multiple_choice', 'session_id' => 10, 'status' => 'active'];
        $service->validateAnswerShape($question, []);
    }

    public function testOptionNotBelongingToQuestionThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid_option');

        // Return an option that belongs to a DIFFERENT question
        $wrongOption = ['id' => 5, 'question_id' => 999];
        $service = $this->makeService(optionRow: $wrongOption);

        $question = ['id' => 99, 'question_type' => 'multiple_choice', 'session_id' => 10, 'status' => 'active'];
        $service->validateAnswerShape($question, ['selected_option_id' => 5]);
    }

    public function testBothFieldsPopulatedForOptionTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('answer:invalid_shape');

        $service = $this->makeService();
        $question = ['id' => 99, 'question_type' => 'multiple_choice', 'session_id' => 10, 'status' => 'active'];
        $service->validateAnswerShape($question, [
            'selected_option_id' => 5,
            'answer_text' => 'should not be here',
        ]);
    }

    // ── T-702: validateAnswerShape — open_text ────────────────────────────────

    public function testOpenTextAnswerReturnsText(): void
    {
        $service = $this->makeService(questionRow: [
            'id' => 99, 'question_type' => 'open_text', 'session_id' => 10, 'status' => 'active',
        ]);

        $question = ['id' => 99, 'question_type' => 'open_text', 'session_id' => 10, 'status' => 'active'];
        [$optId, $text] = $service->validateAnswerShape($question, ['answer_text' => '  hello  ']);

        $this->assertNull($optId);
        $this->assertSame('hello', $text); // trimmed
    }

    public function testOpenTextEmptyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('answer_text:required');

        $service = $this->makeService();
        $question = ['id' => 99, 'question_type' => 'open_text', 'session_id' => 10, 'status' => 'active'];
        $service->validateAnswerShape($question, ['answer_text' => '   ']);
    }

    public function testOpenTextTooLongThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('answer_text:too_long');

        $service = $this->makeService();
        $question = ['id' => 99, 'question_type' => 'open_text', 'session_id' => 10, 'status' => 'active'];
        $service->validateAnswerShape($question, ['answer_text' => str_repeat('x', 2001)]);
    }

    public function testOpenTextStripsTags(): void
    {
        // strip_tags() removes HTML tags but keeps their text content.
        // XSS protection is the output layer's job (htmlspecialchars).
        // Here we verify that inline tags like <b> are stripped from the stored text.
        $service = $this->makeService();
        $question = ['id' => 99, 'question_type' => 'open_text', 'session_id' => 10, 'status' => 'active'];
        [, $text] = $service->validateAnswerShape($question, [
            'answer_text' => '<b>Great</b> lecture!',
        ]);

        $this->assertSame('Great lecture!', $text);
    }

    public function testOpenTextWithOptionIdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('answer:invalid_shape');

        $service = $this->makeService();
        $question = ['id' => 99, 'question_type' => 'open_text', 'session_id' => 10, 'status' => 'active'];
        $service->validateAnswerShape($question, [
            'answer_text' => 'some text',
            'selected_option_id' => 5,
        ]);
    }

    // ── fill_in_the_blank — validateAnswerShape mirrors open_text (FR-31) ────────

    public function testFillInTheBlankAnswerReturnsText(): void
    {
        $service = $this->makeService();

        $question = ['id' => 99, 'question_type' => 'fill_in_the_blank', 'session_id' => 10, 'status' => 'active'];
        [$optId, $text] = $service->validateAnswerShape($question, ['answer_text' => '  Mitochondria  ']);

        $this->assertNull($optId);
        $this->assertSame('Mitochondria', $text); // trimmed
    }

    public function testFillInTheBlankEmptyAnswerThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('answer_text:required');

        $service = $this->makeService();
        $question = ['id' => 99, 'question_type' => 'fill_in_the_blank', 'session_id' => 10, 'status' => 'active'];
        $service->validateAnswerShape($question, ['answer_text' => '   ']);
    }

    public function testSuccessfulFillInTheBlankSubmitReturnsId(): void
    {
        $service = $this->makeService(questionRow: [
            'id' => 99,
            'session_id' => 10,
            'question_type' => 'fill_in_the_blank',
            'status' => 'active',
        ]);

        $answerId = $service->submit(1, ['question_id' => 99, 'answer_text' => 'Mitochondria']);
        $this->assertSame(42, $answerId);
    }

    // ── T-704: participant belongs to session ─────────────────────────────────

    public function testParticipantFromWrongSessionThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('forbidden');

        // Participant is in session 99, question is in session 10 → mismatch
        $service = $this->makeService(
            participantRow: ['id' => 1, 'session_id' => 99],
        );

        $service->submit(1, ['question_id' => 99, 'selected_option_id' => 5]);
    }

    // ── T-705: question must be active ────────────────────────────────────────

    public function testClosedQuestionThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('question_not_active');

        $service = $this->makeService(questionRow: [
            'id' => 99,
            'session_id' => 10,
            'question_type' => 'multiple_choice',
            'status' => 'closed',
        ]);

        $service->submit(1, ['question_id' => 99, 'selected_option_id' => 5]);
    }

    // ── T-705: session must be active ─────────────────────────────────────────

    public function testPausedSessionThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('session_paused');

        $service = $this->makeService(sessionRow: ['id' => 10, 'status' => 'paused']);
        $service->submit(1, ['question_id' => 99, 'selected_option_id' => 5]);
    }

    public function testClosedSessionThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('session_closed');

        $service = $this->makeService(sessionRow: ['id' => 10, 'status' => 'closed']);
        $service->submit(1, ['question_id' => 99, 'selected_option_id' => 5]);
    }

    // ── T-708: duplicate answer → DuplicateAnswerException ───────────────────

    public function testDuplicateInsertThrowsDuplicateAnswerException(): void
    {
        $this->expectException(DuplicateAnswerException::class);
        $this->expectExceptionMessage('duplicate_answer');

        $service = $this->makeService(throwDuplicate: true);
        $service->submit(1, ['question_id' => 99, 'selected_option_id' => 5]);
    }

    // ── Happy path ─────────────────────────────────────────────────────────────

    public function testSuccessfulOptionSubmitReturnsId(): void
    {
        $service = $this->makeService();
        $answerId = $service->submit(1, ['question_id' => 99, 'selected_option_id' => 5]);

        $this->assertSame(42, $answerId);
    }

    public function testSuccessfulOpenTextSubmitReturnsId(): void
    {
        $service = $this->makeService(questionRow: [
            'id' => 99,
            'session_id' => 10,
            'question_type' => 'open_text',
            'status' => 'active',
        ]);

        $answerId = $service->submit(1, ['question_id' => 99, 'answer_text' => 'My thoughts']);
        $this->assertSame(42, $answerId);
    }

    // ── missing question_id ───────────────────────────────────────────────────

    public function testMissingQuestionIdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('question_id:required');

        $service = $this->makeService();
        $service->submit(1, []);
    }
}
