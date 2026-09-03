<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit;

use EduQR\Contracts\CourseRepositoryInterface;
use EduQR\Contracts\OptionRepositoryInterface;
use EduQR\Contracts\QuestionRepositoryInterface;
use EduQR\Contracts\SessionRepositoryInterface;
use EduQR\Exceptions\ValidationException;
use EduQR\Services\QuestionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class QuestionServiceTest extends TestCase
{
    // ── validateType ───────────────────────────────────────────────────────────

    public function testInvalidTypeThrows(): void
    {
        $service = $this->makeService();

        try {
            $service->create(1, 1, [
                'question_text' => 'Test?',
                'question_type' => 'unknown_type',
            ]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('invalid_question_type', $e->getErrorCode());
            $this->assertSame(422, $e->getStatus());
            $this->assertSame('invalid_question_type', $e->getPublicCode());
            $this->assertSame('question_type', $e->getField());
        }
    }

    #[DataProvider('validTypeProvider')]
    public function testAllValidTypesAccepted(string $type): void
    {
        $service = $this->makeService();
        $opts = $type === 'multiple_choice'
            ? [['option_text' => 'A'], ['option_text' => 'B']]
            : [];

        $id = $service->create(1, 1, [
            'question_text' => 'Test?',
            'question_type' => $type,
            'options' => $opts,
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
        $service = $this->makeService();

        try {
            $service->create(1, 1, [
                'question_text' => '   ',
                'question_type' => 'open_text',
            ]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('required', $e->getErrorCode());
            $this->assertSame(400, $e->getStatus());
            $this->assertSame('missing_fields', $e->getPublicCode());
            $this->assertSame('question_text', $e->getField());
        }
    }

    public function testTextTooLongThrows(): void
    {
        $service = $this->makeService();

        try {
            $service->create(1, 1, [
                'question_text' => str_repeat('a', 501),
                'question_type' => 'open_text',
            ]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('text_too_long', $e->getErrorCode());
            $this->assertSame(400, $e->getStatus());
            $this->assertSame('validation_error', $e->getPublicCode());
            $this->assertSame('question_text', $e->getField());
        }
    }

    // ── multiple_choice option count ───────────────────────────────────────────

    public function testMultipleChoiceTooFewOptionsThrows(): void
    {
        $service = $this->makeService();

        try {
            $service->create(1, 1, [
                'question_text' => 'Pick one?',
                'question_type' => 'multiple_choice',
                'options' => [['option_text' => 'Only one']],
            ]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('invalid_option_count', $e->getErrorCode());
            $this->assertSame(422, $e->getStatus());
            $this->assertSame('invalid_option_count', $e->getPublicCode());
            $this->assertSame('options', $e->getField());
        }
    }

    public function testMultipleChoiceTooManyOptionsThrows(): void
    {
        $service = $this->makeService();
        $options = array_fill(0, 9, ['option_text' => 'Option']);

        try {
            $service->create(1, 1, [
                'question_text' => 'Pick one?',
                'question_type' => 'multiple_choice',
                'options' => $options,
            ]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('invalid_option_count', $e->getErrorCode());
            $this->assertSame(422, $e->getStatus());
            $this->assertSame('invalid_option_count', $e->getPublicCode());
            $this->assertSame('options', $e->getField());
        }
    }

    public function testMultipleChoiceValidOptionsAccepted(): void
    {
        $capturedOptions = [];
        $service = $this->makeService(capturedOptions: $capturedOptions);

        $service->create(1, 1, [
            'question_text' => 'Pick one?',
            'question_type' => 'multiple_choice',
            'options' => [
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
        $this->assertSame('no', $capturedOptions[1]['option_value']);
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

    // ── fill_in_the_blank — single correct-answer option (FR-31) ──────────────────

    public function testFillInTheBlankValidAnswerCreatesOneCorrectOption(): void
    {
        $capturedOptions = [];
        $service = $this->makeService(capturedOptions: $capturedOptions);

        $service->create(1, 1, [
            'question_text' => 'The powerhouse of the cell is the ____.',
            'question_type' => 'fill_in_the_blank',
            'correct_answer' => 'Mitochondria',
        ]);

        $this->assertCount(1, $capturedOptions);
        $this->assertSame('Mitochondria', $capturedOptions[0]['option_text']);
        $this->assertSame(1, $capturedOptions[0]['is_correct']);
    }

    public function testFillInTheBlankEmptyAnswerThrows(): void
    {
        $service = $this->makeService();

        try {
            $service->create(1, 1, [
                'question_text' => 'Fill in: ____.',
                'question_type' => 'fill_in_the_blank',
                'correct_answer' => '   ',
            ]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('required', $e->getErrorCode());
            $this->assertSame(400, $e->getStatus());
            $this->assertSame('validation_error', $e->getPublicCode());
            $this->assertSame('correct_answer', $e->getField());
        }
    }

    public function testFillInTheBlankMissingAnswerThrows(): void
    {
        $service = $this->makeService();

        try {
            $service->create(1, 1, [
                'question_text' => 'Fill in: ____.',
                'question_type' => 'fill_in_the_blank',
            ]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('required', $e->getErrorCode());
            $this->assertSame(400, $e->getStatus());
            $this->assertSame('validation_error', $e->getPublicCode());
            $this->assertSame('correct_answer', $e->getField());
        }
    }

    public function testFillInTheBlankTooLongAnswerThrows(): void
    {
        $service = $this->makeService();

        try {
            $service->create(1, 1, [
                'question_text' => 'Fill in: ____.',
                'question_type' => 'fill_in_the_blank',
                'correct_answer' => str_repeat('a', 201),
            ]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('text_too_long', $e->getErrorCode());
            $this->assertSame(400, $e->getStatus());
            $this->assertSame('validation_error', $e->getPublicCode());
            $this->assertSame('correct_answer', $e->getField());
        }
    }

    // ── one-active-question rule (FR-33) ───────────────────────────────────────

    public function testActivateClosesOtherActiveQuestion(): void
    {
        $activateCalls = [];

        $questionRepo = new class ($activateCalls) implements QuestionRepositoryInterface {
            private int $nextId = 1;
            public function __construct(private array &$calls)
            {
            }

            public function create(int $s, string $t, string $tp, bool $sr, bool $am, string $stage = 'middle'): int
            {
                return $this->nextId++;
            }
            public function findById(int $id): ?array
            {
                return ['id' => $id, 'session_id' => 1, 'status' => 'draft', 'question_type' => 'open_text'];
            }
            public function findBySession(int $s): array
            {
                return [];
            }
            public function findActiveBySessionCode(string $c): ?array
            {
                return null;
            }
            public function update(int $id, array $fields): void
            {
            }
            public function delete(int $id): void
            {
            }
            public function activate(int $id, int $sessionId): void
            {
                $this->calls[] = ['id' => $id, 'session_id' => $sessionId];
            }
            public function close(int $id): void
            {
            }
            public function reorder(int $s, array $ids): void
            {
            }
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

        $questionRepo = new class () implements QuestionRepositoryInterface {
            public function create(int $s, string $t, string $tp, bool $sr, bool $am, string $stage = 'middle'): int
            {
                return 1;
            }
            public function findById(int $id): ?array
            {
                return ['id' => $id, 'session_id' => 1, 'status' => 'closed', 'question_type' => 'open_text'];
            }
            public function findBySession(int $s): array
            {
                return [];
            }
            public function findActiveBySessionCode(string $c): ?array
            {
                return null;
            }
            public function update(int $id, array $fields): void
            {
            }
            public function delete(int $id): void
            {
            }
            public function activate(int $id, int $sessionId): void
            {
            }
            public function close(int $id): void
            {
            }
            public function reorder(int $s, array $ids): void
            {
            }
        };

        $service = $this->makeServiceWithRepo($questionRepo);
        $service->activate(1, 1);
    }

    public function testActivateFailsWhenSessionNotActive(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('session_not_active');

        $questionRepo = new class () implements QuestionRepositoryInterface {
            public function create(int $s, string $t, string $tp, bool $sr, bool $am, string $stage = 'middle'): int
            {
                return 1;
            }
            public function findById(int $id): ?array
            {
                return ['id' => $id, 'session_id' => 1, 'status' => 'draft', 'question_type' => 'open_text'];
            }
            public function findBySession(int $s): array
            {
                return [];
            }
            public function findActiveBySessionCode(string $c): ?array
            {
                return null;
            }
            public function update(int $id, array $fields): void
            {
            }
            public function delete(int $id): void
            {
            }
            public function activate(int $id, int $sessionId): void
            {
            }
            public function close(int $id): void
            {
            }
            public function reorder(int $s, array $ids): void
            {
            }
        };

        $service = $this->makeServiceWithRepo($questionRepo, sessionStatus: 'paused');
        $service->activate(1, 1);
    }

    // ── delete draft-only check ────────────────────────────────────────────────

    public function testDeleteNonDraftThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('question_not_draft');

        $questionRepo = new class () implements QuestionRepositoryInterface {
            public function create(int $s, string $t, string $tp, bool $sr, bool $am, string $stage = 'middle'): int
            {
                return 1;
            }
            public function findById(int $id): ?array
            {
                return ['id' => $id, 'session_id' => 1, 'status' => 'active', 'question_type' => 'open_text'];
            }
            public function findBySession(int $s): array
            {
                return [];
            }
            public function findActiveBySessionCode(string $c): ?array
            {
                return null;
            }
            public function update(int $id, array $fields): void
            {
            }
            public function delete(int $id): void
            {
            }
            public function activate(int $id, int $sessionId): void
            {
            }
            public function close(int $id): void
            {
            }
            public function reorder(int $s, array $ids): void
            {
            }
        };

        $service = $this->makeServiceWithRepo($questionRepo);
        $service->delete(1, 1);
    }

    // ── image attachment (FR-39) ──────────────────────────────────────────────

    public function testSetImagePathUpdatesDraftQuestion_FR39(): void
    {
        $updatedFields = [];
        $questionRepo = $this->questionRepoForDraft($updatedFields);
        $service = $this->makeServiceWithRepo($questionRepo);

        $service->setImagePath(1, 1, 'uploads/questions/1_a1b2c3d4e5f60789.png');

        $this->assertSame(
            ['image_path' => 'uploads/questions/1_a1b2c3d4e5f60789.png'],
            $updatedFields
        );
    }

    public function testSetImagePathRejectsUnsafeRelativePath_FR39(): void
    {
        $updatedFields = [];
        $questionRepo = $this->questionRepoForDraft($updatedFields);
        $service = $this->makeServiceWithRepo($questionRepo);

        try {
            $service->setImagePath(1, 1, '../app.log');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('invalid_image_path', $e->getErrorCode());
            $this->assertSame(400, $e->getStatus());
            $this->assertSame('validation_error', $e->getPublicCode());
            $this->assertNull($e->getField());
        }
    }

    public function testGenericUpdateIgnoresImagePath_FR39(): void
    {
        $updatedFields = [];
        $questionRepo = $this->questionRepoForDraft($updatedFields);
        $service = $this->makeServiceWithRepo($questionRepo);

        $service->update(1, 1, ['image_path' => 'uploads/questions/1_a1b2c3d4e5f60789.png']);

        $this->assertSame([], $updatedFields);
    }

    public function testCreateManySetsStageAndMaintainsOrder_FR31(): void
    {
        $captured = [];
        $questionRepo = new class ($captured) implements QuestionRepositoryInterface {
            public function __construct(private array &$captured)
            {
            }
            public function create(int $s, string $t, string $tp, bool $sr, bool $am, string $stage = 'middle'): int
            {
                $this->captured[] = [
                    'session_id' => $s,
                    'text' => $t,
                    'type' => $tp,
                    'show_results' => $sr,
                    'allow_multiple' => $am,
                    'stage' => $stage,
                ];

                return count($this->captured);
            }
            public function findById(int $id): ?array
            {
                return null;
            }
            public function findBySession(int $s): array
            {
                return [];
            }
            public function findActiveBySessionCode(string $c): ?array
            {
                return null;
            }
            public function update(int $id, array $fields): void
            {
            }
            public function delete(int $id): void
            {
            }
            public function activate(int $id, int $sessionId): void
            {
            }
            public function close(int $id): void
            {
            }
            public function reorder(int $s, array $ids): void
            {
            }
        };

        $service = $this->makeServiceWithRepo($questionRepo);
        $items = [
            ['question_text' => 'Opening Q', 'question_type' => 'open_text', 'stage' => 'opening'],
            ['question_text' => 'Middle Q', 'question_type' => 'open_text', 'stage' => 'middle'],
            ['question_text' => 'Closing Q', 'question_type' => 'open_text', 'stage' => 'closing'],
        ];

        $ids = $service->createMany(1, 1, $items);

        $this->assertCount(3, $ids);
        $this->assertCount(3, $captured);

        $this->assertSame('opening', $captured[0]['stage']);
        $this->assertSame('Opening Q', $captured[0]['text']);

        $this->assertSame('middle', $captured[1]['stage']);
        $this->assertSame('Middle Q', $captured[1]['text']);

        $this->assertSame('closing', $captured[2]['stage']);
        $this->assertSame('Closing Q', $captured[2]['text']);
    }

    // ── Co-instructor access (FR-97) ───────────────────────────────────────────

    public function testCreateQuestionAllowedForCoInstructor_FR97(): void
    {
        $captured = [];
        $service = $this->makeService('en', $captured, [20]);

        $id = $service->create(1, 20, [
            'question_text' => 'Co-instructor question?',
            'question_type' => 'open_text',
        ]);

        $this->assertGreaterThan(0, $id);
    }

    public function testCreateQuestionStillForbiddenForStranger_FR97(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('forbidden');

        $captured = [];
        $service = $this->makeService('en', $captured, [20]);
        $service->create(1, 99, [
            'question_text' => 'Stranger question?',
            'question_type' => 'open_text',
        ]);
    }

    // ── Stub factories ─────────────────────────────────────────────────────────

    /** @param list<int> $coInstructors co-instructor user ids on course 10 (FR-97) */
    private function makeService(
        string $language = 'en',
        array  &$capturedOptions = [],
        array  $coInstructors = [],
    ): QuestionService {
        $session = [
            'id' => 1,
            'course_id' => 10,
            'status' => 'active',
            'language' => $language,
        ];

        $questionRepo = new class () implements QuestionRepositoryInterface {
            private int $nextId = 1;
            public function create(int $s, string $t, string $tp, bool $sr, bool $am, string $stage = 'middle'): int
            {
                return $this->nextId++;
            }
            public function findById(int $id): ?array
            {
                return null;
            }
            public function findBySession(int $s): array
            {
                return [];
            }
            public function findActiveBySessionCode(string $c): ?array
            {
                return null;
            }
            public function update(int $id, array $fields): void
            {
            }
            public function delete(int $id): void
            {
            }
            public function activate(int $id, int $sessionId): void
            {
            }
            public function close(int $id): void
            {
            }
            public function reorder(int $s, array $ids): void
            {
            }
        };

        $opts = &$capturedOptions;
        $optionRepo = new class ($opts) implements OptionRepositoryInterface {
            public function __construct(private array &$captured)
            {
            }
            public function createBulk(int $questionId, array $options): void
            {
                foreach ($options as $o) {
                    $this->captured[] = $o;
                }
            }
            public function findByQuestion(int $questionId): array
            {
                return [];
            }
            public function deleteByQuestion(int $questionId): void
            {
            }
            public function findById(int $id): ?array
            {
                return null;
            }
        };

        return $this->buildService($session, $questionRepo, $optionRepo, $coInstructors);
    }

    private function makeServiceWithRepo(
        QuestionRepositoryInterface $questionRepo,
        string $sessionStatus = 'active',
    ): QuestionService {
        $session = [
            'id' => 1,
            'course_id' => 10,
            'status' => $sessionStatus,
            'language' => 'en',
        ];

        $optionRepo = new class () implements OptionRepositoryInterface {
            public function createBulk(int $questionId, array $options): void
            {
            }
            public function findByQuestion(int $questionId): array
            {
                return [];
            }
            public function deleteByQuestion(int $questionId): void
            {
            }
            public function findById(int $id): ?array
            {
                return null;
            }
        };

        return $this->buildService($session, $questionRepo, $optionRepo);
    }

    private function questionRepoForDraft(array &$updatedFields): QuestionRepositoryInterface
    {
        return new class ($updatedFields) implements QuestionRepositoryInterface {
            public function __construct(private array &$updatedFields)
            {
            }
            public function create(int $s, string $t, string $tp, bool $sr, bool $am, string $stage = 'middle'): int
            {
                return 1;
            }
            public function findById(int $id): ?array
            {
                return [
                    'id' => $id,
                    'session_id' => 1,
                    'status' => 'draft',
                    'question_type' => 'open_text',
                    'image_path' => null,
                ];
            }
            public function findBySession(int $s): array
            {
                return [];
            }
            public function findActiveBySessionCode(string $c): ?array
            {
                return null;
            }
            public function update(int $id, array $fields): void
            {
                $this->updatedFields = $fields;
            }
            public function delete(int $id): void
            {
            }
            public function activate(int $id, int $sessionId): void
            {
            }
            public function close(int $id): void
            {
            }
            public function reorder(int $s, array $ids): void
            {
            }
        };
    }

    /** @param list<int> $coInstructors co-instructor user ids on course 10 (FR-97) */
    private function buildService(
        array $session,
        QuestionRepositoryInterface $questionRepo,
        OptionRepositoryInterface $optionRepo,
        array $coInstructors = [],
    ): QuestionService {
        $sessionRepo = new class ($session) implements SessionRepositoryInterface {
            public function __construct(private array $session)
            {
            }
            public function findById(int $id): ?array
            {
                return $this->session;
            }
            public function findByShortCode(string $code): ?array
            {
                return $this->session;
            }
            public function shortCodeExists(string $code): bool
            {
                return false;
            }
            public function create(int $courseId, string $title, string $shortCode, string $language, int $isQuiz = 0): int
            {
                return 1;
            }
            public function update(int $id, array $fields): void
            {
            }
            public function listByCourse(int $courseId): array
            {
                return [];
            }
            public function countParticipants(int $sessionId): int
            {
                return 0;
            }
            public function anonymize(int $sessionId): void
            {
            }
        };

        $courseRepo = new class ($coInstructors) implements CourseRepositoryInterface {
            public function __construct(private array $coInstructors = [])
            {
            }
            public function findById(int $id): ?array
            {
                return ['id' => 10, 'instructor_id' => 1, 'title' => 'Test', 'status' => 'active', 'default_language' => 'en'];
            }
            public function listByInstructor(int $instructorId, int $page, int $perPage): array
            {
                return [];
            }
            public function countByInstructor(int $instructorId): int
            {
                return 0;
            }
            public function create(int $instructorId, string $title, ?string $code, ?string $semester, ?string $description, string $defaultLanguage): int
            {
                return 10;
            }
            public function update(int $id, array $fields): void
            {
            }
            public function archive(int $id): void
            {
            }
            public function restore(int $id): void
            {
            }
            public function roleFor(int $courseId, int $userId): ?string
            {
                if ($userId === 1) {
                    return 'owner';
                }

                return in_array($userId, $this->coInstructors, true) ? 'co_instructor' : null;
            }
            public function listInstructors(int $courseId): array
            {
                return [];
            }
            public function addInstructor(int $courseId, int $userId, string $role): void
            {
            }
            public function removeInstructor(int $courseId, int $userId): bool
            {
                return false;
            }
        };

        return new QuestionService($questionRepo, $optionRepo, $sessionRepo, $courseRepo);
    }
}
