<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit;

use EduQR\Contracts\CourseRepositoryInterface;
use EduQR\Contracts\OptionRepositoryInterface;
use EduQR\Contracts\QuestionRepositoryInterface;
use EduQR\Contracts\SessionRepositoryInterface;
use EduQR\Controllers\Api\PublicQuestionController;
use EduQR\Services\QuestionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the student poll is allowed to cost — NFR-76, second clause.
 *
 * The requirement is that the active-question poll must not build a full
 * result aggregation. It already did not when this was written: the poll runs
 * PublicQuestionController -> QuestionService::getActiveQuestionByCode, which
 * reads the session, the active question and its options, and nothing else.
 * Nothing on the path holds a ResultsService, and no student screen asks for
 * results at all.
 *
 * So this file pins the property rather than introducing it. The phone polls
 * every three seconds for as long as a lecture lasts, and the cheapest way for
 * that to regress is for somebody to add one convenient field to the payload.
 *
 * @requirement NFR-76
 */
final class StudentPollCostTest extends TestCase
{
    /** @var list<string> */
    private array $reads = [];

    /** The whole payload of the poll, as of NFR-76. */
    private const PAYLOAD_KEYS = [
        'id',
        'type',
        'text',
        'image_url',
        'options',
        'activated_at',
        'already_answered',
    ];

    // ── What the poll returns ─────────────────────────────────────────────────

    /**
     * @requirement NFR-76
     */
    public function testThePollReturnsTheQuestionAndNoAggregate(): void
    {
        $payload = $this->pollOnce();

        self::assertNotNull($payload);
        self::assertSame(self::PAYLOAD_KEYS, array_keys($payload));
    }

    /**
     * The shapes an aggregate would arrive in. None of them is a field the
     * waiting screen or the answer form draws.
     *
     * @requirement NFR-76
     */
    #[DataProvider('aggregateKeys')]
    public function testThePollCarriesNoCountOrPercentage(string $key): void
    {
        self::assertNotContains($key, self::PAYLOAD_KEYS);
        self::assertArrayNotHasKey($key, (array) $this->pollOnce());
    }

    /** @return array<string, array{string}> */
    public static function aggregateKeys(): array
    {
        return [
            'counts' => ['counts'],
            'results' => ['results'],
            'answers' => ['answers'],
            'answer_count' => ['answer_count'],
            'total' => ['total'],
            'percentages' => ['percentages'],
            'distribution' => ['distribution'],
        ];
    }

    /**
     * The poll reads three things. A fourth repository call would be a fourth
     * query on every three-second tick.
     *
     * @requirement NFR-76
     */
    public function testThePollReadsTheSessionTheQuestionAndItsOptionsOnly(): void
    {
        $this->pollOnce();

        self::assertSame(
            ['sessions.findByShortCode', 'questions.findActiveBySessionCode', 'options.findByQuestion'],
            $this->reads,
        );
    }

    // ── What the poll path is made of ─────────────────────────────────────────

    /**
     * Neither the service that answers the poll nor the controller in front of
     * it is given anything that could aggregate: no answer repository, no
     * results service. Asserted from the constructors, so adding one to either
     * fails here before it can be called from the poll.
     *
     * @requirement NFR-76
     */
    #[DataProvider('pollPathClasses')]
    public function testNothingOnThePollPathIsGivenAWayToAggregate(string $class): void
    {
        $collaborators = [];
        foreach ((new \ReflectionClass($class))->getProperties() as $property) {
            $type = $property->getType();
            if ($type instanceof \ReflectionNamedType) {
                $collaborators[] = $type->getName();
            }
        }

        self::assertNotEmpty($collaborators, $class . ' must declare its collaborators.');

        foreach ($collaborators as $collaborator) {
            self::assertStringNotContainsString('Results', $collaborator, $class . ' must not aggregate.');
            self::assertStringNotContainsString('Answer', $collaborator, $class . ' must not read answers.');
            self::assertStringNotContainsString('Scoring', $collaborator, $class . ' must not score.');
        }
    }

    /** @return array<string, array{class-string}> */
    public static function pollPathClasses(): array
    {
        return [
            'controller' => [PublicQuestionController::class],
            'service' => [QuestionService::class],
        ];
    }

    /**
     * The version query added by NFR-76 sits in front of the same poll, so it
     * is bound by the same clause: it reads the session and the active question
     * through repositories and touches no answers.
     *
     * @requirement NFR-76
     */
    public function testTheActiveQuestionVersionQueryAggregatesNothing(): void
    {
        $body = $this->methodBody('src/Services/PollVersionService.php', 'activeQuestionVersion');

        self::assertStringNotContainsString('answers', $body);
        self::assertStringNotContainsString('COUNT(', $body);
        self::assertStringNotContainsString('SELECT', $body);
    }

    /**
     * A version query that ran the results version instead would defeat the
     * clause even while returning the right ETag.
     *
     * @requirement NFR-76
     */
    public function testTheStudentPollDoesNotCallTheResultsVersion(): void
    {
        $source = $this->read('src/Controllers/Api/PublicQuestionController.php');

        self::assertStringContainsString('activeQuestionVersion(', $source);
        self::assertStringNotContainsString('resultsVersion(', $source);
    }

    // ── What the student screens ask for ──────────────────────────────────────

    /**
     * The inventory NFR-76 was written against: the two student timers poll
     * /active-question, and no student template asks for results on a timer or
     * otherwise. /student-results exists (API_SPEC §3.4) but is on no timer.
     *
     * @requirement NFR-76
     */
    public function testNoStudentScreenPollsForResults(): void
    {
        $scanned = 0;
        $offenders = [];

        foreach (glob(\dirname(__DIR__, 2) . '/templates/student/*.php') ?: [] as $path) {
            ++$scanned;
            $source = file_get_contents($path);
            if (! \is_string($source)) {
                continue;
            }
            if (str_contains($source, 'results')) {
                $offenders[] = basename($path);
            }
        }

        self::assertGreaterThan(0, $scanned, 'The student templates must have been scanned.');
        self::assertSame([], $offenders, 'A student screen must not fetch results.');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * One tick of the poll, through the service the controller calls.
     *
     * @return array<string, mixed>|null
     */
    private function pollOnce(): ?array
    {
        $this->reads = [];

        $sessions = $this->createMock(SessionRepositoryInterface::class);
        $sessions->method('findByShortCode')->willReturnCallback(function (): array {
            $this->reads[] = 'sessions.findByShortCode';

            return ['id' => 10, 'status' => 'active', 'language' => 'en'];
        });

        $questions = $this->createMock(QuestionRepositoryInterface::class);
        $questions->method('findActiveBySessionCode')->willReturnCallback(function (): array {
            $this->reads[] = 'questions.findActiveBySessionCode';

            return [
                'id' => 7,
                'question_type' => 'multiple_choice',
                'question_text' => 'Which one?',
                'image_path' => null,
                'activated_at' => '2026-09-03 10:01:00',
            ];
        });

        $options = $this->createMock(OptionRepositoryInterface::class);
        $options->method('findByQuestion')->willReturnCallback(function (): array {
            $this->reads[] = 'options.findByQuestion';

            return [['id' => 1, 'option_text' => 'A'], ['id' => 2, 'option_text' => 'B']];
        });

        $courses = $this->createMock(CourseRepositoryInterface::class);

        $service = new QuestionService($questions, $options, $sessions, $courses);

        return $service->getActiveQuestionByCode('ABC123');
    }

    private function methodBody(string $file, string $method): string
    {
        $source = $this->read($file);

        $start = strpos($source, 'function ' . $method . '(');
        self::assertIsInt($start, $method . '() must exist in ' . $file . '.');

        $end = strpos($source, "\n    }", $start);
        self::assertIsInt($end, $method . '() must be a method of ' . $file . '.');

        return substr($source, $start, $end - $start);
    }

    private function read(string $file): string
    {
        $source = file_get_contents(\dirname(__DIR__, 2) . '/' . $file);
        self::assertIsString($source, $file . ' must be readable.');

        return $source;
    }
}
