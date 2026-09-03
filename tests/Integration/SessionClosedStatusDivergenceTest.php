<?php

declare(strict_types=1);

namespace EduQR\Tests\Integration;

use EduQR\Contracts\AnswerRepositoryInterface;
use EduQR\Contracts\CourseRepositoryInterface;
use EduQR\Contracts\OptionRepositoryInterface;
use EduQR\Contracts\ParticipantRepositoryInterface;
use EduQR\Contracts\QuestionRepositoryInterface;
use EduQR\Contracts\SessionRepositoryInterface;
use EduQR\Controllers\ApiController;
use EduQR\Exceptions\DomainException;
use EduQR\Services\AnswerService;
use EduQR\Services\ParticipantService;
use EduQR\Services\ReactionService;
use EduQR\Services\SessionService;
use PHPUnit\Framework\TestCase;

/**
 * The 410-vs-422 divergence, end to end (NFR-79, SYSTEM_ARCHITECTURE.md §9.1).
 *
 * A closed session is `410 Gone` when you are trying to get in and `422` when
 * you are already inside and trying to act. Both answers carry the same
 * `session_closed` code and the same message; only the status differs, and it
 * differs because of where the failure was raised.
 *
 * `ApiControllerTest` pins the mapper against hand-built exceptions. This test
 * pins the other half: that the real services actually raise the exceptions the
 * mapper needs, so the divergence cannot be lost by a service quietly dropping
 * its status override. It drives each service through the same
 * `ApiController::domainEnvelope()` the controllers call.
 *
 * `t()` is uninitialised under the test suite, so `I18nService::translate()`
 * returns the key — which is what makes the message assertions readable.
 *
 * @requirement NFR-79
 */
class SessionClosedStatusDivergenceTest extends TestCase
{
    // ── The divergence itself ─────────────────────────────────────────────────

    /**
     * The property a single global code-to-status table would destroy.
     */
    public function test_a_closed_session_is_410_at_the_door_and_422_inside_NFR79(): void
    {
        $atJoin = $this->envelopeFrom(fn () => $this->joinService('closed')
            ->join('ABC123', 'ada', null, 'ua'));

        $whileAnswering = $this->envelopeFrom(fn () => $this->answerService('closed')
            ->submit(1, ['question_id' => 99, 'selected_option_id' => 5]));

        $this->assertSame(410, $atJoin['status']);
        $this->assertSame(422, $whileAnswering['status']);

        // Same failure, same code, same sentence — only the status diverges.
        $this->assertSame($atJoin['body'], $whileAnswering['body']);
        $this->assertSame('session_closed', $atJoin['body']['error']['code']);

        // Compared against t() rather than a literal: whether the suite has
        // already booted I18nService is test-order dependent, and the property
        // under test is that both doors read the same string, not which one.
        $this->assertSame(t('error.session_closed'), $atJoin['body']['error']['message']);
    }

    public function test_a_paused_session_diverges_the_same_way_NFR79(): void
    {
        $atJoin = $this->envelopeFrom(fn () => $this->joinService('paused')
            ->join('ABC123', 'ada', null, 'ua'));

        $whileAnswering = $this->envelopeFrom(fn () => $this->answerService('paused')
            ->submit(1, ['question_id' => 99, 'selected_option_id' => 5]));

        $this->assertSame(410, $atJoin['status']);
        $this->assertSame(422, $whileAnswering['status']);
        $this->assertSame('session_paused', $atJoin['body']['error']['code']);
        $this->assertSame('session_paused', $whileAnswering['body']['error']['code']);
    }

    /**
     * Resolving a short code is the other door: the public session lookup that
     * `PublicSessionController` serves also answers 410, not 422.
     */
    public function test_resolving_a_closed_short_code_is_410_NFR79(): void
    {
        $resolved = $this->envelopeFrom(fn () => $this->sessionService('closed')
            ->resolveByShortCode('ABC123'));

        $this->assertSame(410, $resolved['status']);
        $this->assertSame('session_closed', $resolved['body']['error']['code']);
    }

    /**
     * Reacting is an inside-the-session action like answering, so it takes the
     * 422 side even though the student reaches it over a different endpoint.
     */
    public function test_reacting_in_a_closed_session_is_422_NFR79(): void
    {
        $reacted = $this->envelopeFrom(fn () => $this->reactionService('closed')
            ->react(1, ['question_id' => 99, 'reaction' => 'got_it']));

        $this->assertSame(422, $reacted['status']);
        $this->assertSame('session_closed', $reacted['body']['error']['code']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Run a service call that must fail, and map the failure exactly as a
     * controller would.
     *
     * @param  callable(): mixed $call
     * @return array{status: int, body: array<string, mixed>}
     */
    private function envelopeFrom(callable $call): array
    {
        try {
            $call();
        } catch (DomainException $e) {
            return ApiController::domainEnvelope($e);
        }

        $this->fail('expected a DomainException, the call succeeded');
    }

    /** @return array<string, mixed> */
    private function sessionRow(string $status): array
    {
        return [
            'id' => 10,
            'course_id' => 7,
            'short_code' => 'ABC123',
            'title' => 'Week 1',
            'status' => $status,
            'language' => 'en',
            'is_quiz' => 0,
        ];
    }

    /** The join path — ParticipantService, behind POST /sessions/{code}/join. */
    private function joinService(string $sessionStatus): ParticipantService
    {
        $sessions = $this->createMock(SessionRepositoryInterface::class);
        $sessions->method('findByShortCode')->willReturn($this->sessionRow($sessionStatus));

        return new ParticipantService(
            $this->createMock(ParticipantRepositoryInterface::class),
            $sessions,
        );
    }

    /** The resolve path — SessionService, behind GET /public/sessions/{code}. */
    private function sessionService(string $sessionStatus): SessionService
    {
        $sessions = $this->createMock(SessionRepositoryInterface::class);
        $sessions->method('findByShortCode')->willReturn($this->sessionRow($sessionStatus));

        return new SessionService($sessions, $this->createMock(CourseRepositoryInterface::class));
    }

    /** The answer path — AnswerService, behind POST /answers. */
    private function answerService(string $sessionStatus): AnswerService
    {
        $questions = $this->createMock(QuestionRepositoryInterface::class);
        $questions->method('findById')->willReturn([
            'id' => 99,
            'session_id' => 10,
            'question_type' => 'multiple_choice',
            'status' => 'active',
        ]);

        $sessions = $this->createMock(SessionRepositoryInterface::class);
        $sessions->method('findById')->willReturn($this->sessionRow($sessionStatus));

        $participants = $this->createMock(ParticipantRepositoryInterface::class);
        $participants->method('findById')->willReturn(['id' => 1, 'session_id' => 10]);

        return new AnswerService(
            $this->createMock(AnswerRepositoryInterface::class),
            $questions,
            $sessions,
            $participants,
            $this->createMock(OptionRepositoryInterface::class),
        );
    }

    /** The reaction path — ReactionService, behind POST /reactions. */
    private function reactionService(string $sessionStatus): ReactionService
    {
        $questions = $this->createMock(QuestionRepositoryInterface::class);
        $questions->method('findById')->willReturn([
            'id' => 99,
            'session_id' => 10,
            'status' => 'active',
        ]);

        $sessions = $this->createMock(SessionRepositoryInterface::class);
        $sessions->method('findById')->willReturn($this->sessionRow($sessionStatus));

        $participants = $this->createMock(ParticipantRepositoryInterface::class);
        $participants->method('findById')->willReturn(['id' => 1, 'session_id' => 10]);

        return new ReactionService(
            $this->createMock(\EduQR\Contracts\ReactionRepositoryInterface::class),
            $questions,
            $sessions,
            $participants,
            $this->createMock(CourseRepositoryInterface::class),
        );
    }
}
