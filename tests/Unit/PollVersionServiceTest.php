<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit;

use EduQR\Contracts\QuestionRepositoryInterface;
use EduQR\Contracts\SessionRepositoryInterface;
use EduQR\Exceptions\NotFoundException;
use EduQR\Exceptions\ValidationException;
use EduQR\Services\PollVersionService;
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
 * The repositories are mocked and answer from properties the test moves between
 * two calls to the same service, which is exactly what a second poll does.
 *
 * @requirement NFR-76
 */
final class PollVersionServiceTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $sessionByCode = null;

    /** @var array<string, mixed>|null */
    private ?array $activeQuestion = null;

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

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * The repositories answer from the properties above, so a test can move the
     * state between two calls to the same service instance — which is exactly
     * what a second poll does.
     */
    private function makeService(): PollVersionService
    {
        $sessions = $this->createMock(SessionRepositoryInterface::class);
        $sessions->method('findByShortCode')->willReturnCallback(fn (): ?array => $this->sessionByCode);

        $questions = $this->createMock(QuestionRepositoryInterface::class);
        $questions->method('findActiveBySessionCode')->willReturnCallback(fn (): ?array => $this->activeQuestion);

        return new PollVersionService($sessions, $questions);
    }
}
