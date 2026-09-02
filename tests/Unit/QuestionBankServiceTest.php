<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit;

use EduQR\Contracts\CourseRepositoryInterface;
use EduQR\Contracts\OptionRepositoryInterface;
use EduQR\Contracts\QuestionBankRepositoryInterface;
use EduQR\Contracts\QuestionGenerationServiceInterface;
use EduQR\Contracts\QuestionRepositoryInterface;
use EduQR\Contracts\SessionRepositoryInterface;
use EduQR\Services\QuestionBankService;
use EduQR\Services\QuestionService;
use PHPUnit\Framework\TestCase;

final class QuestionBankServiceTest extends TestCase
{
    public function testSaveQuestionToBankCopiesExistingQuestion_FR93(): void
    {
        $created = [];
        $service = $this->buildService(
            bankRepo: $this->bankRepo($created),
            questionRepo: $this->questionRepo(),
            optionRepo: $this->optionRepo(),
            sessionRepo: $this->sessionRepo(),
            courseRepo: $this->courseRepo(),
            generator: $this->generator([]),
        );

        $id = $service->saveQuestion(50, 7);

        $this->assertSame(1, $id);
        $this->assertCount(1, $created);
        $this->assertSame('session_question', $created[0]['source_kind']);
        $this->assertSame('Session title', $created[0]['source_title']);
        $this->assertSame('What is recursion?', $created[0]['payload']['question_text']);
        $this->assertSame(1, $created[0]['payload']['options'][0]['order_no']);
    }

    public function testGenerateFromNotesStoresThreeBankItems_FR94(): void
    {
        $created = [];
        $generatorPayload = [
            [
                'question_text' => 'Opening question?',
                'question_type' => 'open_text',
                'stage' => 'opening',
                'show_results' => false,
                'allow_multiple_answers' => false,
                'options' => [],
            ],
            [
                'question_text' => 'Middle question?',
                'question_type' => 'multiple_choice',
                'stage' => 'middle',
                'show_results' => true,
                'allow_multiple_answers' => false,
                'options' => [
                    ['option_text' => 'A'],
                    ['option_text' => 'B'],
                ],
            ],
            [
                'question_text' => 'Closing question?',
                'question_type' => 'likert_5',
                'stage' => 'closing',
                'show_results' => false,
                'allow_multiple_answers' => false,
                'options' => [],
            ],
        ];

        $service = $this->buildService(
            bankRepo: $this->bankRepo($created),
            questionRepo: $this->questionRepo(),
            optionRepo: $this->optionRepo(),
            sessionRepo: $this->sessionRepo(),
            courseRepo: $this->courseRepo(),
            generator: $this->generator($generatorPayload),
        );

        $result = $service->generateFromNotes(99, 7, [
            'source_title' => 'Week 5 notes',
            'lecture_notes' => 'Recursion lecture notes',
        ]);

        $this->assertSame(3, $result['count']);
        $this->assertCount(3, $created);
        $this->assertSame('lecture_notes', $created[0]['source_kind']);
        $this->assertSame('Week 5 notes', $created[0]['source_title']);
        $this->assertSame('Middle question?', $created[1]['payload']['question_text']);
    }

    public function testCopyToSessionClonesBankItemsIntoDraftQuestions_FR95(): void
    {
        $createdQuestions = [];
        $bankItems = [
            [
                'id' => 9001,
                'course_id' => 99,
                'created_by_user_id' => 7,
                'source_kind' => 'lecture_notes',
                'source_title' => 'Week 5 notes',
                'question' => [
                    'question_text' => 'Opening question?',
                    'question_type' => 'open_text',
                    'stage' => 'opening',
                    'show_results' => false,
                    'allow_multiple_answers' => false,
                    'options' => [],
                ],
                'created_at' => '2026-06-04 18:00:00',
                'updated_at' => '2026-06-04 18:00:00',
            ],
            [
                'id' => 9002,
                'course_id' => 99,
                'created_by_user_id' => 7,
                'source_kind' => 'lecture_notes',
                'source_title' => 'Week 5 notes',
                'question' => [
                    'question_text' => 'Middle question?',
                    'question_type' => 'open_text',
                    'stage' => 'middle',
                    'show_results' => false,
                    'allow_multiple_answers' => false,
                    'options' => [],
                ],
                'created_at' => '2026-06-04 18:01:00',
                'updated_at' => '2026-06-04 18:01:00',
            ],
        ];

        $service = $this->buildService(
            bankRepo: $this->bankRepo($createdQuestions, $bankItems),
            questionRepo: $this->questionRepoForCreate($createdQuestions),
            optionRepo: $this->optionRepo(),
            sessionRepo: $this->sessionRepo(),
            courseRepo: $this->courseRepo(),
            generator: $this->generator([]),
        );

        $result = $service->copyToSession(10, 7, [9001, 9002]);

        $this->assertSame(2, $result['count']);
        $this->assertCount(2, $createdQuestions);
        $this->assertSame('Opening question?', $createdQuestions[0]['body']['question_text']);
        $this->assertSame('Middle question?', $createdQuestions[1]['body']['question_text']);
    }

    private function buildService(
        QuestionBankRepositoryInterface $bankRepo,
        QuestionRepositoryInterface $questionRepo,
        OptionRepositoryInterface $optionRepo,
        SessionRepositoryInterface $sessionRepo,
        CourseRepositoryInterface $courseRepo,
        QuestionGenerationServiceInterface $generator,
    ): QuestionBankService {
        $questionService = new QuestionService($questionRepo, $optionRepo, $sessionRepo, $courseRepo);

        return new QuestionBankService(
            $bankRepo,
            $questionRepo,
            $optionRepo,
            $sessionRepo,
            $courseRepo,
            $questionService,
            $generator,
        );
    }

    private function bankRepo(array &$created, array $findByIdsItems = []): QuestionBankRepositoryInterface
    {
        return new class ($created, $findByIdsItems) implements QuestionBankRepositoryInterface {
            public function __construct(private array &$created, private array $findByIdsItems)
            {
            }

            public function create(int $courseId, int $userId, string $sourceKind, array $payload, ?string $sourceTitle = null): int
            {
                $this->created[] = [
                    'course_id' => $courseId,
                    'user_id' => $userId,
                    'source_kind' => $sourceKind,
                    'payload' => $payload,
                    'source_title' => $sourceTitle,
                ];

                return count($this->created);
            }

            public function findById(int $id): ?array
            {
                foreach ($this->findByIdsItems as $item) {
                    if ((int) $item['id'] === $id) {
                        return $item;
                    }
                }

                return null;
            }

            public function findByCourse(int $courseId): array
            {
                return $this->findByIdsItems;
            }

            public function findByIds(int $courseId, array $ids): array
            {
                $wanted = array_map('intval', $ids);

                return array_values(array_filter(
                    $this->findByIdsItems,
                    static fn (array $item): bool => in_array((int) $item['id'], $wanted, true)
                ));
            }
        };
    }

    private function questionRepo(): QuestionRepositoryInterface
    {
        return new class () implements QuestionRepositoryInterface {
            public function create(int $s, string $t, string $tp, bool $sr, bool $am, string $stage = 'middle'): int
            {
                return 1;
            }

            public function findById(int $id): ?array
            {
                return [
                    'id' => $id,
                    'session_id' => 10,
                    'status' => 'draft',
                    'question_text' => 'What is recursion?',
                    'question_type' => 'multiple_choice',
                    'show_results' => 1,
                    'allow_multiple_answers' => 0,
                    'stage' => 'opening',
                ];
            }

            public function findBySession(int $sessionId): array
            {
                return [];
            }

            public function findActiveBySessionCode(string $shortCode): ?array
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

            public function reorder(int $sessionId, array $orderedIds): void
            {
            }
        };
    }

    private function questionRepoForCreate(array &$created): QuestionRepositoryInterface
    {
        return new class ($created) implements QuestionRepositoryInterface {
            public function __construct(private array &$created)
            {
            }

            public function create(int $sessionId, string $questionText, string $questionType, bool $showResults, bool $allowMultipleAnswers, string $stage = 'middle'): int
            {
                $this->created[] = [
                    'sessionId' => $sessionId,
                    'body' => [
                        'question_text' => $questionText,
                        'question_type' => $questionType,
                        'show_results' => $showResults,
                        'allow_multiple_answers' => $allowMultipleAnswers,
                        'stage' => $stage,
                    ],
                ];

                return count($this->created);
            }

            public function findById(int $id): ?array
            {
                return [
                    'id' => $id,
                    'session_id' => 10,
                    'status' => 'draft',
                    'question_text' => 'What is recursion?',
                    'question_type' => 'multiple_choice',
                    'show_results' => 1,
                    'allow_multiple_answers' => 0,
                    'stage' => 'opening',
                ];
            }

            public function findBySession(int $sessionId): array
            {
                return [];
            }

            public function findActiveBySessionCode(string $shortCode): ?array
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

            public function reorder(int $sessionId, array $orderedIds): void
            {
            }
        };
    }

    private function optionRepo(): OptionRepositoryInterface
    {
        return new class () implements OptionRepositoryInterface {
            public function createBulk(int $questionId, array $options): void
            {
            }

            public function findByQuestion(int $questionId): array
            {
                return [
                    ['id' => 1, 'option_text' => 'A', 'option_value' => '1', 'order_no' => 1, 'is_correct' => 0],
                    ['id' => 2, 'option_text' => 'B', 'option_value' => '2', 'order_no' => 2, 'is_correct' => 0],
                ];
            }

            public function deleteByQuestion(int $questionId): void
            {
            }

            public function findById(int $id): ?array
            {
                return null;
            }
        };
    }

    private function sessionRepo(): SessionRepositoryInterface
    {
        return new class () implements SessionRepositoryInterface {
            public function findById(int $id): ?array
            {
                return [
                    'id' => $id,
                    'course_id' => 99,
                    'title' => 'Session title',
                    'status' => 'active',
                    'language' => 'en',
                ];
            }

            public function findByShortCode(string $code): ?array
            {
                return null;
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
    }

    private function courseRepo(): CourseRepositoryInterface
    {
        return new class () implements CourseRepositoryInterface {
            public function findById(int $id): ?array
            {
                return [
                    'id' => $id,
                    'instructor_id' => 7,
                    'title' => 'Course title',
                    'default_language' => 'en',
                    'status' => 'active',
                ];
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
                return 1;
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
        };
    }

    private function generator(array $payload): QuestionGenerationServiceInterface
    {
        return new class ($payload) implements QuestionGenerationServiceInterface {
            public function __construct(private array $payload)
            {
            }

            public function generateFromNotes(string $courseTitle, ?string $topicName, string $lectureNotes, string $language): array
            {
                return $this->payload;
            }
        };
    }
}
