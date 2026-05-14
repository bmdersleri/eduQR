<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit;

use EduQR\Contracts\CourseRepositoryInterface;
use EduQR\Contracts\OptionRepositoryInterface;
use EduQR\Contracts\QuestionRepositoryInterface;
use EduQR\Contracts\SessionRepositoryInterface;
use EduQR\Services\QuestionService;
use PHPUnit\Framework\TestCase;

class QuestionServiceTest extends TestCase
{
    // ── validateType ───────────────────────────────────────────────────────────

    public function testInvalidTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('question_type:invalid');

        $service = $this->makeService();
        $service->create(1, 1, [
            'question_text' => 'Test?',
            'question_type' => 'unknown_type',
        ]);
    }

    /** @dataProvider validTypeProvider */
    public function testAllValidTypesAccepted(string $type): void
    {
        $service = $this->makeService();
        $opts    = $type === 'multiple_choice'
            ? [['option_text' => 'A'], ['option_text' => 'B']]
            : [];

        $id = $service->create(1, 1, [
            'question_text' => 'Test?',
            'question_type' => $type,
            'options'       => $opts,
        ]);

        $this->assertGreaterThan(0, $id);
    }

    public static function validTypeProvider(): array
    {
        return [
            ['multiple_choice'],
            ['open_text'],
            ['yes_no'],
            ['likert_5'],
        ];
    }

    // ── question_text validation ───────────────────────────────────────────────

    public function testEmptyTextThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('question_text:required');

        $service = $this->makeService();
        $service->create(1, 1, [
            'question_text' => '   ',
            'question_type' => 'open_text',
        ]);
    }

    public function testTextTooLongThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('question_text:too_long');

        $service = $this->makeService();
        $service->create(1, 1, [
            'question_text' => str_repeat('a', 501),
            'question_type' => 'open_text',
        ]);
    }

    // ── multiple_choice option count ───────────────────────────────────────────

    public function testMultipleChoiceTooFewOptionsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('options:invalid_count');

        $service = $this->makeService();
        $service->create(1, 1, [
            'question_text' => 'Pick one?',
            'question_type' => 'multiple_choice',
            'options'       => [['option_text' => 'Only one']],
        ]);
    }

    public function testMultipleChoiceTooManyOptionsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('options:invalid_count');

        $service = $this->makeService();
        $options = array_fill(0, 9, ['option_text' => 'Option']);
        $service->create(1, 1, [
            'question_text' => 'Pick one?',
            'question_type' => 'multiple_choice',
            'options'       => $options,
        ]);
    }

    public function testMultipleChoiceValidOptionsAccepted(): void
    {
        $capturedOptions = [];
        $service = $this->makeService(capturedOptions: $capturedOptions);

        $service->create(1, 1, [
            'question_text' => 'Pick one?',
            'question_type' => 'multiple_choice',
            'options'       => [
                ['option_text' => 'Alpha'],
                ['option_text' => 'Beta'],
                ['option_text' => 'Gamma'],
            ],
        ]);

        $this->assertCount(3, $capturedOptions);
        $this->assertSame('Alpha', $capturedOptions[0]['option_text']);
    }

    // ── yes_no auto-options ────────────────────────────────────────────────────

    public function testYesNoCreatesExactlyTwoOptions(): void
    {
        $capturedOptions = [];
        $service = $this->makeService(capturedOptions: $capturedOptions);

        $service->create(1, 1, [
            'question_text' => 'Are you sure?',
            'question_type' => 'yes_no',
        ]);

        $this->assertCount(2, $capturedOptions);
        $this->assertSame('yes', $capturedOptions[0]['option_value']);
        $this->assertSame('no',  $capturedOptions[1]['option_value']);
    }

    public function testYesNoTurkishLabels(): void
    {
        $capturedOptions = [];
        $service = $this->makeService(language: 'tr', capturedOptions: $capturedOptions);

        $service->create(1, 1, [
            'question_text' => 'Emin misiniz?',
            'question_type' => 'yes_no',
        ]);

        $this->assertSame('Evet', $capturedOptions[0]['option_text']);
        $this->assertSame('Hayır', $capturedOptions[1]['option_text']);
    }

    // ── likert_5 auto-options ──────────────────────────────────────────────────

    public function testLikertCreatesFiveOptions(): void
    {
        $capturedOptions = [];
        $service = $this->makeService(capturedOptions: $capturedOptions);

        $service->create(1, 1, [
            'question_text' => 'Rate this.',
            'question_type' => 'likert_5',
        ]);

        $this->assertCount(5, $capturedOptions);
        $this->assertSame('1', $capturedOptions[0]['option_value']);
        $this->assertSame('5', $capturedOptions[4]['option_value']);
    }

    // ── open_text — no options ─────────────────────────────────────────────────

    public function testOpenTextCreatesNoOptions(): void
    {
        $capturedOptions = [];
        $service = $this->makeService(capturedOptions: $capturedOptions);

        $service->create(1, 1, [
            'question_text' => 'Describe it.',
            'question_type' => 'open_text',
        ]);

        $this->assertCount(0, $capturedOptions);
    }

    // ── one-active-question rule (FR-33) ───────────────────────────────────────

    public function testActivateClosesOtherActiveQuestion(): void
    {
        $activateCalls = [];

        $questionRepo = new class($activateCalls) implements QuestionRepositoryInterface {
            private int $nextId = 1;
            public function __construct(private array &$calls) {}

            public function create(int $s, string $t, string $tp, bool $sr, bool $am): int { return $this->nextId++; }
            public function findById(int $id): ?array {
                return ['id' => $id, 'session_id' => 1, 'status' => 'draft', 'question_type' => 'open_text'];
            }
            public function findBySession(int $s): array { return []; }
            public function findActiveBySessionCode(string $c): ?array { return null; }
            public function update(int $id, array $fields): void {}
            public function delete(int $id): void {}
            public function activate(int $id, int $sessionId): void {
                $this->calls[] = ['id' => $id, 'session_id' => $sessionId];
            }
            public function close(int $id): void {}
            public function reorder(int $s, array $ids): void {}
        };

        $service = $this->makeServiceWithRepo($questionRepo);
        $service->activate(1, 1);

        $this->assertCount(1, $activateCalls);
        $this->assertSame(1, $activateCalls[0]['id']);
        $this->assertSame(1, $activateCalls[0]['session_id']);
    }

    public function testActivateClosedQuestionThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid_state_transition');

        $questionRepo = new class implements QuestionRepositoryInterface {
            public function create(int $s, string $t, string $tp, bool $sr, bool $am): int { return 1; }
            public function findById(int $id): ?array {
                return ['id' => $id, 'session_id' => 1, 'status' => 'closed', 'question_type' => 'open_text'];
            }
            public function findBySession(int $s): array { return []; }
            public function findActiveBySessionCode(string $c): ?array { return null; }
            public function update(int $id, array $fields): void {}
            public function delete(int $id): void {}
            public function activate(int $id, int $sessionId): void {}
            public function close(int $id): void {}
            public function reorder(int $s, array $ids): void {}
        };

        $service = $this->makeServiceWithRepo($questionRepo);
        $service->activate(1, 1);
    }

    public function testActivateFailsWhenSessionNotActive(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('session_not_active');

        $questionRepo = new class implements QuestionRepositoryInterface {
            public function create(int $s, string $t, string $tp, bool $sr, bool $am): int { return 1; }
            public function findById(int $id): ?array {
                return ['id' => $id, 'session_id' => 1, 'status' => 'draft', 'question_type' => 'open_text'];
            }
            public function findBySession(int $s): array { return []; }
            public function findActiveBySessionCode(string $c): ?array { return null; }
            public function update(int $id, array $fields): void {}
            public function delete(int $id): void {}
            public function activate(int $id, int $sessionId): void {}
            public function close(int $id): void {}
            public function reorder(int $s, array $ids): void {}
        };

        $service = $this->makeServiceWithRepo($questionRepo, sessionStatus: 'paused');
        $service->activate(1, 1);
    }

    // ── delete draft-only check ────────────────────────────────────────────────

    public function testDeleteNonDraftThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('question_not_draft');

        $questionRepo = new class implements QuestionRepositoryInterface {
            public function create(int $s, string $t, string $tp, bool $sr, bool $am): int { return 1; }
            public function findById(int $id): ?array {
                return ['id' => $id, 'session_id' => 1, 'status' => 'active', 'question_type' => 'open_text'];
            }
            public function findBySession(int $s): array { return []; }
            public function findActiveBySessionCode(string $c): ?array { return null; }
            public function update(int $id, array $fields): void {}
            public function delete(int $id): void {}
            public function activate(int $id, int $sessionId): void {}
            public function close(int $id): void {}
            public function reorder(int $s, array $ids): void {}
        };

        $service = $this->makeServiceWithRepo($questionRepo);
        $service->delete(1, 1);
    }

    // ── Stub factories ─────────────────────────────────────────────────────────

    private function makeService(
        string $language       = 'en',
        array  &$capturedOptions = [],
    ): QuestionService {
        $session = [
            'id'          => 1,
            'course_id'   => 10,
            'status'      => 'active',
            'language'    => $language,
        ];

        $questionRepo = new class implements QuestionRepositoryInterface {
            private int $nextId = 1;
            public function create(int $s, string $t, string $tp, bool $sr, bool $am): int { return $this->nextId++; }
            public function findById(int $id): ?array { return null; }
            public function findBySession(int $s): array { return []; }
            public function findActiveBySessionCode(string $c): ?array { return null; }
            public function update(int $id, array $fields): void {}
            public function delete(int $id): void {}
            public function activate(int $id, int $sessionId): void {}
            public function close(int $id): void {}
            public function reorder(int $s, array $ids): void {}
        };

        $opts = &$capturedOptions;
        $optionRepo = new class($opts) implements OptionRepositoryInterface {
            public function __construct(private array &$captured) {}
            public function createBulk(int $questionId, array $options): void {
                foreach ($options as $o) $this->captured[] = $o;
            }
            public function findByQuestion(int $questionId): array { return []; }
            public function deleteByQuestion(int $questionId): void {}
            public function findById(int $id): ?array { return null; }
        };

        return $this->buildService($session, $questionRepo, $optionRepo);
    }

    private function makeServiceWithRepo(
        QuestionRepositoryInterface $questionRepo,
        string $sessionStatus = 'active',
    ): QuestionService {
        $session = [
            'id'        => 1,
            'course_id' => 10,
            'status'    => $sessionStatus,
            'language'  => 'en',
        ];

        $optionRepo = new class implements OptionRepositoryInterface {
            public function createBulk(int $questionId, array $options): void {}
            public function findByQuestion(int $questionId): array { return []; }
            public function deleteByQuestion(int $questionId): void {}
            public function findById(int $id): ?array { return null; }
        };

        return $this->buildService($session, $questionRepo, $optionRepo);
    }

    private function buildService(
        array $session,
        QuestionRepositoryInterface $questionRepo,
        OptionRepositoryInterface $optionRepo,
    ): QuestionService {
        $sessionRepo = new class($session) implements SessionRepositoryInterface {
            public function __construct(private array $session) {}
            public function findById(int $id): ?array { return $this->session; }
            public function findByShortCode(string $code): ?array { return $this->session; }
            public function shortCodeExists(string $code): bool { return false; }
            public function create(int $courseId, string $title, string $shortCode, string $language): int { return 1; }
            public function update(int $id, array $fields): void {}
            public function listByCourse(int $courseId): array { return []; }
            public function countParticipants(int $sessionId): int { return 0; }
        };

        $courseRepo = new class implements CourseRepositoryInterface {
            public function findById(int $id): ?array {
                return ['id' => 10, 'instructor_id' => 1, 'title' => 'Test', 'status' => 'active', 'default_language' => 'en'];
            }
            public function listByInstructor(int $instructorId, int $page, int $perPage): array { return []; }
            public function countByInstructor(int $instructorId): int { return 0; }
            public function create(int $instructorId, string $title, ?string $code, ?string $semester, ?string $description, string $defaultLanguage): int { return 10; }
            public function update(int $id, array $fields): void {}
            public function archive(int $id): void {}
        };

        return new QuestionService($questionRepo, $optionRepo, $sessionRepo, $courseRepo);
    }
}
