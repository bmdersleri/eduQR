<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit;

use EduQR\Contracts\CourseRepositoryInterface;
use EduQR\Contracts\ParticipantRepositoryInterface;
use EduQR\Contracts\QuestionRepositoryInterface;
use EduQR\Contracts\ReactionRepositoryInterface;
use EduQR\Contracts\SessionRepositoryInterface;
use EduQR\Services\ReactionService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ReactionService — T-1105
 *
 * All repositories are anonymous-class stubs, so no database is needed.
 * The reaction stub emulates the UNIQUE (question_id, participant_id) +
 * ON DUPLICATE KEY UPDATE behaviour of the real table.
 *
 * @requirement FR-48
 */
class ReactionServiceTest extends TestCase
{
    // ── Stubs ──────────────────────────────────────────────────────────────────

    /** In-memory stand-in for question_reactions, keyed like the unique index. */
    private function makeReactionRepo(): ReactionRepositoryInterface
    {
        return new class () implements ReactionRepositoryInterface {
            /** @var array<string,array<string,mixed>> keyed by "questionId:participantId" */
            public array $rows = [];

            public function upsert(
                int    $sessionId,
                int    $questionId,
                int    $participantId,
                string $reaction
            ): void {
                // Mirrors ON DUPLICATE KEY UPDATE: the key replaces, never appends.
                $this->rows["{$questionId}:{$participantId}"] = [
                    'session_id' => $sessionId,
                    'question_id' => $questionId,
                    'participant_id' => $participantId,
                    'reaction' => $reaction,
                ];
            }

            public function aggregateBySession(int $sessionId): array
            {
                $counts = [];
                foreach ($this->rows as $row) {
                    if ($row['session_id'] !== $sessionId) {
                        continue;
                    }
                    $qid = $row['question_id'];
                    $counts[$qid] ??= ['question_id' => $qid, 'got_it' => 0, 'lost' => 0];
                    $counts[$qid][$row['reaction']]++;
                }

                return array_values($counts);
            }
        };
    }

    private function makeQuestionRepo(array $question, array $sessionQuestions = []): QuestionRepositoryInterface
    {
        return new class ($question, $sessionQuestions) implements QuestionRepositoryInterface {
            public function __construct(
                private array $question,
                private array $sessionQuestions,
            ) {
            }

            public function create(
                int    $sessionId,
                string $questionText,
                string $questionType,
                bool   $showResults,
                bool   $allowMultipleAnswers,
                string $stage = 'middle'
            ): int {
                return 0;
            }

            public function findById(int $id): ?array
            {
                return $this->question === [] ? null : $this->question;
            }

            public function findBySession(int $sessionId): array
            {
                return $this->sessionQuestions;
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

    private function makeSessionRepo(array $session): SessionRepositoryInterface
    {
        return new class ($session) implements SessionRepositoryInterface {
            public function __construct(private array $session)
            {
            }

            public function findById(int $id): ?array
            {
                return $this->session === [] ? null : $this->session;
            }

            public function findByShortCode(string $code): ?array
            {
                return $this->session === [] ? null : $this->session;
            }

            public function shortCodeExists(string $code): bool
            {
                return false;
            }

            public function create(int $courseId, string $title, string $shortCode, string $language, int $isQuiz = 0): int
            {
                return 0;
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

    private function makeParticipantRepo(?array $participant): ParticipantRepositoryInterface
    {
        return new class ($participant) implements ParticipantRepositoryInterface {
            public function __construct(private ?array $participant)
            {
            }

            public function register(
                int    $sessionId,
                string $nickname,
                string $nicknameNormalized,
                ?string $deviceHash,
            ): int {
                return 0;
            }

            public function existsByNicknameNormalized(int $sessionId, string $nicknameNormalized): bool
            {
                return false;
            }

            public function countBySession(int $sessionId): int
            {
                return 0;
            }

            public function findBySession(int $sessionId): array
            {
                return [];
            }

            public function findById(int $id): ?array
            {
                return $this->participant;
            }

            public function findBySessionAndDeviceHash(int $sessionId, string $deviceHash): ?array
            {
                return null;
            }
        };
    }

    /** @param list<int> $coInstructors co-instructor user ids for this course (FR-97) */
    private function makeCourseRepo(?array $course, array $coInstructors = []): CourseRepositoryInterface
    {
        return new class ($course, $coInstructors) implements CourseRepositoryInterface {
            public function __construct(private ?array $course, private array $coInstructors = [])
            {
            }

            public function findById(int $id): ?array
            {
                return $this->course;
            }

            public function listByInstructor(int $instructorId, int $page, int $perPage): array
            {
                return [];
            }

            public function countByInstructor(int $instructorId): int
            {
                return 0;
            }

            public function create(
                int     $instructorId,
                string  $title,
                ?string $code,
                ?string $semester,
                ?string $description,
                string  $defaultLanguage
            ): int {
                return 0;
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
                if ($this->course !== null && (int) $this->course['instructor_id'] === $userId) {
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
    }

    // ── Helper ─────────────────────────────────────────────────────────────────

    /**
     * Build a fully-wired ReactionService from per-test stubs.
     * All parameters are optional; pass only what your test needs.
     */
    private function makeService(
        ?array $participantRow = null,
        ?array $questionRow = null,
        ?array $sessionRow = null,
        ?array $courseRow = null,
        array  $sessionQuestions = [],
        ?ReactionRepositoryInterface $reactions = null,
        array  $coInstructors = [],
    ): ReactionService {
        $participant = $participantRow ?? ['id' => 1, 'session_id' => 10];
        $question = $questionRow ?? [
            'id' => 99,
            'session_id' => 10,
            'question_type' => 'multiple_choice',
            'status' => 'active',
        ];
        $session = $sessionRow ?? ['id' => 10, 'course_id' => 7, 'status' => 'active'];
        $course = $courseRow ?? ['id' => 7, 'instructor_id' => 3];

        return new ReactionService(
            $reactions ?? $this->makeReactionRepo(),
            $this->makeQuestionRepo($question, $sessionQuestions),
            $this->makeSessionRepo($session),
            $this->makeParticipantRepo($participant),
            $this->makeCourseRepo($course, $coInstructors),
        );
    }

    // ── A valid reaction is stored ─────────────────────────────────────────────

    public function testValidReactionIsStored(): void
    {
        $reactions = $this->makeReactionRepo();
        $service = $this->makeService(reactions: $reactions);

        $stored = $service->react(1, ['question_id' => 99, 'reaction' => 'got_it']);

        $this->assertSame('got_it', $stored);
        $this->assertCount(1, $reactions->rows);
        $this->assertSame('got_it', $reactions->rows['99:1']['reaction']);
        $this->assertSame(10, $reactions->rows['99:1']['session_id']);
    }

    public function testLostReactionIsStored(): void
    {
        $reactions = $this->makeReactionRepo();
        $service = $this->makeService(reactions: $reactions);

        $this->assertSame('lost', $service->react(1, ['question_id' => 99, 'reaction' => 'lost']));
    }

    // ── Re-reacting replaces rather than duplicates ────────────────────────────

    public function testReReactingReplacesPreviousReaction(): void
    {
        $reactions = $this->makeReactionRepo();
        $service = $this->makeService(reactions: $reactions);

        $service->react(1, ['question_id' => 99, 'reaction' => 'got_it']);
        $service->react(1, ['question_id' => 99, 'reaction' => 'lost']);

        $this->assertCount(1, $reactions->rows, 'Re-reacting must not create a second row');
        $this->assertSame('lost', $reactions->rows['99:1']['reaction']);
    }

    // ── An invalid reaction value is rejected ──────────────────────────────────

    public function testUnknownReactionValueIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reaction:invalid');

        $service = $this->makeService();
        $service->react(1, ['question_id' => 99, 'reaction' => 'confused']);
    }

    public function testMissingReactionValueIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reaction:required');

        $service = $this->makeService();
        $service->react(1, ['question_id' => 99]);
    }

    public function testMissingQuestionIdIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('question_id:required');

        $service = $this->makeService();
        $service->react(1, ['reaction' => 'got_it']);
    }

    // ── State gates mirror the answer flow ─────────────────────────────────────

    public function testClosedQuestionIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('question_not_active');

        $service = $this->makeService(questionRow: [
            'id' => 99,
            'session_id' => 10,
            'question_type' => 'multiple_choice',
            'status' => 'closed',
        ]);

        $service->react(1, ['question_id' => 99, 'reaction' => 'got_it']);
    }

    public function testPausedSessionIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('session_paused');

        $service = $this->makeService(sessionRow: ['id' => 10, 'course_id' => 7, 'status' => 'paused']);
        $service->react(1, ['question_id' => 99, 'reaction' => 'got_it']);
    }

    public function testParticipantFromAnotherSessionIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('forbidden');

        $service = $this->makeService(participantRow: ['id' => 1, 'session_id' => 99]);
        $service->react(1, ['question_id' => 99, 'reaction' => 'got_it']);
    }

    // ── exam_mode / show_results do not gate reactions ─────────────────────────

    public function testExamModeDoesNotBlockReacting(): void
    {
        $reactions = $this->makeReactionRepo();
        $service = $this->makeService(
            sessionRow: [
                'id' => 10,
                'course_id' => 7,
                'status' => 'active',
                'exam_mode' => 1,
                'show_results_to_students' => 0,
            ],
            reactions: $reactions,
        );

        $this->assertSame('lost', $service->react(1, ['question_id' => 99, 'reaction' => 'lost']));
        $this->assertCount(1, $reactions->rows);
    }

    // ── Aggregates are computed correctly ──────────────────────────────────────

    public function testAggregatesCountEachReactionPerQuestion(): void
    {
        $reactions = $this->makeReactionRepo();
        $reactions->upsert(10, 99, 1, 'got_it');
        $reactions->upsert(10, 99, 2, 'got_it');
        $reactions->upsert(10, 99, 3, 'lost');
        $reactions->upsert(10, 100, 1, 'lost');

        $service = $this->makeService(
            sessionQuestions: [['id' => 99], ['id' => 100], ['id' => 101]],
            reactions: $reactions,
        );

        $out = $service->aggregatesForSession(10, 3);

        $this->assertSame(
            [
                ['question_id' => 99, 'got_it' => 2, 'lost' => 1],
                ['question_id' => 100, 'got_it' => 0, 'lost' => 1],
                // Unreacted question is zero-filled, not omitted
                ['question_id' => 101, 'got_it' => 0, 'lost' => 0],
            ],
            $out
        );
    }

    // ── Ownership is enforced on the aggregate endpoint ────────────────────────

    public function testAggregatesRejectNonOwningInstructor(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('forbidden');

        // Course belongs to instructor 3; caller is instructor 4
        $service = $this->makeService(sessionQuestions: [['id' => 99]]);
        $service->aggregatesForSession(10, 4);
    }

    public function testAggregatesRejectUnknownSession(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('session_not_found');

        $service = $this->makeService(sessionRow: []);
        $service->aggregatesForSession(10, 3);
    }

    public function testAggregatesAllowOwningInstructor(): void
    {
        $service = $this->makeService(sessionQuestions: [['id' => 99]]);

        $this->assertSame(
            [['question_id' => 99, 'got_it' => 0, 'lost' => 0]],
            $service->aggregatesForSession(10, 3)
        );
    }

    // ── Co-instructor access (FR-97) ───────────────────────────────────────────

    public function testAggregatesAllowCoInstructor_FR97(): void
    {
        // Course belongs to instructor 3; instructor 4 co-instructs it.
        $service = $this->makeService(sessionQuestions: [['id' => 99]], coInstructors: [4]);

        $this->assertSame(
            [['question_id' => 99, 'got_it' => 0, 'lost' => 0]],
            $service->aggregatesForSession(10, 4)
        );
    }

    public function testAggregatesStillRejectStranger_FR97(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('forbidden');

        $service = $this->makeService(sessionQuestions: [['id' => 99]], coInstructors: [4]);
        $service->aggregatesForSession(10, 5);
    }
}
