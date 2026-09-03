<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit;

use EduQR\Contracts\CourseRepositoryInterface;
use EduQR\Contracts\ParticipantRepositoryInterface;
use EduQR\Contracts\SessionRepositoryInterface;
use EduQR\Contracts\UserRepositoryInterface;
use EduQR\Exceptions\ConflictException;
use EduQR\Exceptions\ForbiddenException;
use EduQR\Exceptions\NotFoundException;
use EduQR\Exceptions\ValidationException;
use EduQR\Services\CourseService;
use EduQR\Services\ParticipantService;
use EduQR\Services\SessionService;
use PHPUnit\Framework\TestCase;

/**
 * A representative sample of the T-1126 conversion: three services, one per
 * shape of failure, proving that the thrown type changed and that the message
 * the HTTP layer still reads did not.
 *
 * @requirement NFR-78
 */
class ServiceDomainExceptionTest extends TestCase
{
    // ── CourseService ─────────────────────────────────────────────────────────

    public function test_course_service_throws_not_found_for_missing_course_NFR78(): void
    {
        $service = new CourseService(
            $this->courses(null, null),
            $this->createMock(UserRepositoryInterface::class)
        );

        try {
            $service->getCourse(7, 1);
            $this->fail('Expected NotFoundException');
        } catch (NotFoundException $e) {
            $this->assertSame('course_not_found', $e->getErrorCode());
            $this->assertSame(404, $e->getStatus());
            // The controllers still map by message text until T-1127.
            $this->assertSame('course_not_found', $e->getMessage());
        }
    }

    public function test_course_service_throws_forbidden_for_a_stranger_NFR78(): void
    {
        $service = new CourseService(
            $this->courses(['id' => 7, 'status' => 'active'], null),
            $this->createMock(UserRepositoryInterface::class)
        );

        try {
            $service->getCourse(7, 1);
            $this->fail('Expected ForbiddenException');
        } catch (ForbiddenException $e) {
            $this->assertSame('forbidden', $e->getErrorCode());
            $this->assertSame(403, $e->getStatus());
        }
    }

    public function test_course_owner_only_is_forbidden_published_as_forbidden_NFR78(): void
    {
        $service = new CourseService(
            $this->courses(['id' => 7, 'status' => 'active'], 'co_instructor'),
            $this->createMock(UserRepositoryInterface::class)
        );

        try {
            $service->requireOwner(7, 2);
            $this->fail('Expected ForbiddenException');
        } catch (ForbiddenException $e) {
            // The thrown code stays precise; the published code is the coarse one.
            $this->assertSame('course_owner_only', $e->getErrorCode());
            $this->assertSame('forbidden', $e->getPublicCode());
            $this->assertSame(403, $e->getStatus());
        }
    }

    // ── SessionService ────────────────────────────────────────────────────────

    public function test_session_service_throws_conflict_when_already_anonymized_NFR78(): void
    {
        $session = ['id' => 3, 'course_id' => 7, 'anonymized' => 1];
        $service = new SessionService(
            $this->sessions($session),
            $this->courses(['id' => 7, 'status' => 'active'], 'owner')
        );

        try {
            $service->anonymizeSession(3, 1);
            $this->fail('Expected ConflictException');
        } catch (ConflictException $e) {
            $this->assertSame('already_anonymized', $e->getErrorCode());
            $this->assertSame(409, $e->getStatus());
            $this->assertSame('already_anonymized', $e->getMessage());
        }
    }

    public function test_session_service_throws_validation_for_a_bad_state_transition_NFR78(): void
    {
        $session = ['id' => 3, 'course_id' => 7, 'status' => 'closed', 'anonymized' => 0];
        $service = new SessionService(
            $this->sessions($session),
            $this->courses(['id' => 7, 'status' => 'active'], 'owner')
        );

        try {
            $service->pauseSession(3, 1);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('invalid_state_transition', $e->getErrorCode());
            $this->assertSame(422, $e->getStatus());
        }
    }

    // ── ParticipantService ────────────────────────────────────────────────────

    public function test_join_a_closed_session_keeps_its_410_NFR78(): void
    {
        // §9.1: session_closed is 410 at join and 422 while answering, so the
        // throw site — not the subtype default — decides.
        $service = new ParticipantService(
            $this->createMock(ParticipantRepositoryInterface::class),
            $this->sessionsByCode(['id' => 3, 'status' => 'closed', 'language' => 'en'])
        );

        try {
            $service->join('ABC234', 'Ada', null, '');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('session_closed', $e->getErrorCode());
            $this->assertSame(410, $e->getStatus());
        }
    }

    public function test_duplicate_nickname_is_a_conflict_carrying_its_field_NFR78(): void
    {
        $participants = $this->createMock(ParticipantRepositoryInterface::class);
        $participants->method('existsByNicknameNormalized')->willReturn(true);

        $service = new ParticipantService(
            $participants,
            $this->sessionsByCode([
                'id' => 3,
                'status' => 'active',
                'language' => 'en',
                'short_code' => 'ABC234',
            ])
        );

        try {
            $service->join('ABC234', 'Ada', null, '');
            $this->fail('Expected ConflictException');
        } catch (ConflictException $e) {
            $this->assertSame('duplicate_nickname', $e->getErrorCode());
            $this->assertSame(409, $e->getStatus());
            $this->assertSame('nickname', $e->getField());
        }
    }

    // ── Stubs ─────────────────────────────────────────────────────────────────

    private function courses(?array $course, ?string $role): CourseRepositoryInterface
    {
        $repo = $this->createMock(CourseRepositoryInterface::class);
        $repo->method('findById')->willReturn($course);
        $repo->method('roleFor')->willReturn($role);

        return $repo;
    }

    private function sessions(?array $session): SessionRepositoryInterface
    {
        $repo = $this->createMock(SessionRepositoryInterface::class);
        $repo->method('findById')->willReturn($session);

        return $repo;
    }

    private function sessionsByCode(?array $session): SessionRepositoryInterface
    {
        $repo = $this->createMock(SessionRepositoryInterface::class);
        $repo->method('findByShortCode')->willReturn($session);

        return $repo;
    }
}
