<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit;

use EduQR\Exceptions\AuthenticationException;
use EduQR\Exceptions\ConflictException;
use EduQR\Exceptions\DomainException;
use EduQR\Exceptions\ForbiddenException;
use EduQR\Exceptions\NotFoundException;
use EduQR\Exceptions\UpstreamServiceException;
use EduQR\Exceptions\ValidationException;
use EduQR\Services\DuplicateAnswerException;
use PHPUnit\Framework\TestCase;

/**
 * The typed domain exceptions of SYSTEM_ARCHITECTURE.md §9.1.
 *
 * @requirement NFR-78
 */
class DomainExceptionTest extends TestCase
{
    // ── Default statuses (§9.1 table) ─────────────────────────────────────────

    public function test_not_found_defaults_to_404_NFR78(): void
    {
        $this->assertSame(404, (new NotFoundException('course_not_found'))->getStatus());
    }

    public function test_forbidden_defaults_to_403_NFR78(): void
    {
        $this->assertSame(403, (new ForbiddenException('forbidden'))->getStatus());
    }

    public function test_validation_defaults_to_422_NFR78(): void
    {
        $this->assertSame(422, (new ValidationException('invalid_option'))->getStatus());
    }

    public function test_conflict_defaults_to_409_NFR78(): void
    {
        $this->assertSame(409, (new ConflictException('already_anonymized'))->getStatus());
    }

    public function test_authentication_defaults_to_401_NFR78(): void
    {
        $this->assertSame(401, (new AuthenticationException('invalid_credentials'))->getStatus());
    }

    public function test_upstream_defaults_to_503_NFR78(): void
    {
        $this->assertSame(503, (new UpstreamServiceException('llm_unavailable'))->getStatus());
    }

    /**
     * The two codes UpstreamServiceException carries are published with different
     * statuses: the provider being down is a 503, the provider answering with
     * something unusable is a 422. Both must survive the round trip by type, which
     * is the whole reason this type exists rather than a bare \RuntimeException.
     */
    public function test_upstream_carries_both_published_statuses_NFR78(): void
    {
        $down = new UpstreamServiceException('llm_unavailable');
        $garbage = new UpstreamServiceException('invalid_llm_response', 422);

        $this->assertSame(503, $down->getStatus());
        $this->assertSame('llm_unavailable', $down->getPublicCode());
        $this->assertSame(422, $garbage->getStatus());
        $this->assertSame('invalid_llm_response', $garbage->getPublicCode());
    }

    /**
     * A 401 must not be reachable by catching ForbiddenException, and a 403 must
     * not be reachable by catching AuthenticationException. Collapsing the two
     * would tell a client to retry a sign-in that cannot help, or not to retry one
     * that would.
     */
    public function test_authentication_and_forbidden_are_not_interchangeable_NFR78(): void
    {
        $this->assertNotInstanceOf(ForbiddenException::class, new AuthenticationException('invalid_credentials'));
        $this->assertNotInstanceOf(AuthenticationException::class, new ForbiddenException('forbidden'));
    }

    /**
     * Both new types are still DomainExceptions, so the shared mapper introduced
     * by T-1127 reaches them without a special case.
     */
    public function test_new_types_are_domain_exceptions_NFR78(): void
    {
        $this->assertInstanceOf(DomainException::class, new AuthenticationException('invalid_credentials'));
        $this->assertInstanceOf(DomainException::class, new UpstreamServiceException('llm_unavailable'));
    }

    // ── Status override (§9.1: "a default, not a law") ────────────────────────

    public function test_status_override_replaces_the_default_NFR78(): void
    {
        // session_closed is 410 at join and 422 while answering.
        $this->assertSame(410, (new ValidationException('session_closed', 410))->getStatus());
        $this->assertSame(422, (new ValidationException('session_closed'))->getStatus());
    }

    public function test_status_override_applies_to_every_subtype_NFR78(): void
    {
        $this->assertSame(401, (new NotFoundException('invalid_credentials', 401))->getStatus());
        $this->assertSame(429, (new ForbiddenException('too_many_attempts', 429))->getStatus());
        $this->assertSame(400, (new ValidationException('profane_nickname', 400))->getStatus());
        $this->assertSame(422, (new ConflictException('some_conflict', 422))->getStatus());
    }

    // ── getMessage() still returns the code ───────────────────────────────────

    public function test_get_message_returns_the_error_code_NFR78(): void
    {
        $e = new NotFoundException('course_not_found');

        $this->assertSame('course_not_found', $e->getMessage());
        $this->assertSame('course_not_found', $e->getErrorCode());
    }

    public function test_get_message_returns_the_thrown_code_not_the_public_code_NFR78(): void
    {
        $e = new NotFoundException('participant_not_found', 401, 'not_joined');

        $this->assertSame('participant_not_found', $e->getMessage());
    }

    // ── Public code override ──────────────────────────────────────────────────

    public function test_public_code_defaults_to_the_thrown_code_NFR78(): void
    {
        $e = new NotFoundException('session_not_found');

        $this->assertSame('session_not_found', $e->getPublicCode());
        $this->assertSame('session_not_found', $e->getErrorCode());
    }

    public function test_public_code_override_is_exposed_separately_from_the_thrown_code_NFR78(): void
    {
        // §9.1: question_not_active is published as question_closed.
        $e = new ValidationException('question_not_active', 422, 'question_closed');

        $this->assertSame('question_not_active', $e->getErrorCode());
        $this->assertSame('question_closed', $e->getPublicCode());
    }

    // ── Field ─────────────────────────────────────────────────────────────────

    public function test_field_is_null_unless_given_NFR78(): void
    {
        $this->assertNull((new ValidationException('invalid_option'))->getField());
    }

    public function test_field_is_exposed_for_validation_failures_NFR78(): void
    {
        $e = new ValidationException('profane_nickname', 400, null, 'nickname');

        $this->assertSame('nickname', $e->getField());
    }

    // ── Catchability — the safety property of T-1126 ──────────────────────────

    public function test_domain_exception_is_catchable_as_runtime_exception_NFR78(): void
    {
        $caught = null;

        try {
            throw new NotFoundException('course_not_found');
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(NotFoundException::class, $caught);
        $this->assertSame('course_not_found', $caught->getMessage());
    }

    public function test_domain_exception_is_not_an_invalid_argument_exception_NFR78(): void
    {
        // Controllers catch \RuntimeException before \InvalidArgumentException;
        // a domain exception must never land in the validation branch.
        $this->assertNotInstanceOf(
            \InvalidArgumentException::class,
            new ValidationException('invalid_option')
        );
    }

    public function test_every_subtype_is_a_domain_exception_NFR78(): void
    {
        $this->assertInstanceOf(DomainException::class, new NotFoundException('a'));
        $this->assertInstanceOf(DomainException::class, new ForbiddenException('b'));
        $this->assertInstanceOf(DomainException::class, new ValidationException('c'));
        $this->assertInstanceOf(DomainException::class, new ConflictException('d'));
    }

    // ── DuplicateAnswerException folds into the hierarchy ─────────────────────

    public function test_duplicate_answer_exception_is_a_conflict_NFR78(): void
    {
        $e = new DuplicateAnswerException('duplicate_answer');

        $this->assertInstanceOf(ConflictException::class, $e);
        $this->assertInstanceOf(DomainException::class, $e);
        $this->assertSame(409, $e->getStatus());
        $this->assertSame('duplicate_answer', $e->getMessage());
        $this->assertSame('duplicate_answer', $e->getPublicCode());
    }

    public function test_duplicate_answer_exception_is_still_caught_by_its_own_name_NFR78(): void
    {
        $caught = false;

        try {
            throw new DuplicateAnswerException('duplicate_answer');
        } catch (DuplicateAnswerException) {
            $caught = true;
        }

        $this->assertTrue($caught);
    }

    // ── Previous exception chaining ───────────────────────────────────────────

    public function test_previous_exception_is_preserved_NFR78(): void
    {
        $cause = new \PDOException('23000');
        $e = new ConflictException('duplicate_answer', 409, null, null, $cause);

        $this->assertSame($cause, $e->getPrevious());
    }
}
