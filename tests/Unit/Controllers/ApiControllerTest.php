<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit\Controllers;

use EduQR\Controllers\ApiController;
use EduQR\Exceptions\AuthenticationException;
use EduQR\Exceptions\ConflictException;
use EduQR\Exceptions\ForbiddenException;
use EduQR\Exceptions\NotFoundException;
use EduQR\Exceptions\UpstreamServiceException;
use EduQR\Exceptions\ValidationException;
use EduQR\Services\DuplicateAnswerException;
use PHPUnit\Framework\TestCase;

/**
 * The shared error mapper (NFR-79, ADR-0007 decision 3).
 *
 * `t()` is uninitialised under the unit suite, so `I18nService::translate()`
 * falls through to the key itself. That makes the translation *key* directly
 * observable, which is what these tests want to pin: the mapper's job is to
 * choose the right key, not to render Turkish.
 *
 * @requirement NFR-79
 */
class ApiControllerTest extends TestCase
{
    // ── Default statuses, one per type (§9.1 table) ───────────────────────────

    public function test_each_exception_type_maps_to_its_default_status_NFR79(): void
    {
        $this->assertSame(404, self::statusFor(new NotFoundException('course_not_found')));
        $this->assertSame(401, self::statusFor(new AuthenticationException('invalid_credentials')));
        $this->assertSame(403, self::statusFor(new ForbiddenException('forbidden')));
        $this->assertSame(422, self::statusFor(new ValidationException('session_not_active')));
        $this->assertSame(409, self::statusFor(new ConflictException('invalid_course_state')));
        $this->assertSame(503, self::statusFor(new UpstreamServiceException('llm_unavailable')));
    }

    // ── Overridden status wins ────────────────────────────────────────────────

    public function test_an_overridden_status_beats_the_subtype_default_NFR79(): void
    {
        // The two deliberate divergences of §9.1, read off the same exception type.
        $this->assertSame(410, self::statusFor(new ValidationException('session_closed', 410)));
        $this->assertSame(422, self::statusFor(new ValidationException('session_closed')));

        $this->assertSame(429, self::statusFor(new ForbiddenException('too_many_attempts', 429)));
        $this->assertSame(422, self::statusFor(new UpstreamServiceException('invalid_llm_response', 422)));
        $this->assertSame(400, self::statusFor(new ValidationException('profane_nickname', 400, null, 'nickname')));
    }

    /**
     * The property most likely to be broken by a well-meaning future change: a
     * single global code-to-status table would flatten these two rows into one.
     */
    public function test_session_closed_is_410_at_the_door_and_422_inside_NFR79(): void
    {
        $atJoin = ApiController::domainEnvelope(new ValidationException('session_closed', 410));
        $whileAnswering = ApiController::domainEnvelope(new ValidationException('session_closed'));

        $this->assertSame(410, $atJoin['status']);
        $this->assertSame(422, $whileAnswering['status']);

        // Same code, same message — only the status distinguishes them.
        $this->assertSame($atJoin['body'], $whileAnswering['body']);
    }

    public function test_session_paused_keeps_the_same_split_NFR79(): void
    {
        $this->assertSame(410, self::statusFor(new ValidationException('session_paused', 410)));
        $this->assertSame(422, self::statusFor(new ValidationException('session_paused')));
    }

    // ── Published code vs thrown code ─────────────────────────────────────────

    public function test_the_published_code_is_the_public_code_NFR79(): void
    {
        $body = self::bodyFor(new AuthenticationException('participant_not_found', 401, 'not_joined'));

        $this->assertSame('not_joined', $body['error']['code']);
    }

    public function test_the_published_code_falls_back_to_the_thrown_code_NFR79(): void
    {
        $this->assertSame('course_not_found', self::bodyFor(new NotFoundException('course_not_found'))['error']['code']);
    }

    /**
     * `course_owner_only` is the case that decides which getter the message
     * lookup uses. It publishes the coarse code `forbidden` so a client cannot
     * distinguish it from any other refusal, and still shows the instructor the
     * sentence that explains why.
     */
    public function test_the_message_follows_the_thrown_code_not_the_published_one_NFR79(): void
    {
        $body = self::bodyFor(new ForbiddenException('course_owner_only', 403, 'forbidden'));

        $this->assertSame('forbidden', $body['error']['code']);
        $this->assertSame('error.course_owner_only', $body['error']['message']);
    }

    public function test_a_renamed_code_takes_the_message_of_its_publication_NFR79(): void
    {
        // There is no `error.participant_not_found` sentence to show a student.
        $body = self::bodyFor(new AuthenticationException('participant_not_found', 401, 'not_joined'));
        $this->assertSame('error.not_joined', $body['error']['message']);

        $body = self::bodyFor(new ValidationException('question_not_active', 422, 'question_closed'));
        $this->assertSame('question_closed', $body['error']['code']);
        $this->assertSame('error.question_closed', $body['error']['message']);
    }

    public function test_a_code_without_an_override_reads_error_dot_code_NFR79(): void
    {
        $this->assertSame('error.session_not_found', ApiController::messageFor('session_not_found'));
        $this->assertSame('error.llm_unavailable', ApiController::messageFor('llm_unavailable'));
    }

    public function test_codes_whose_message_key_predates_them_keep_it_NFR79(): void
    {
        $this->assertSame('error.invalid_answer_shape', ApiController::messageFor('invalid_option'));
        $this->assertSame('common.error', ApiController::messageFor('already_anonymized'));
        $this->assertSame('auth.login.error.locked', ApiController::messageFor('too_many_attempts'));
        $this->assertSame('auth.login.error.invalid', ApiController::messageFor('invalid_credentials'));
        $this->assertSame('auth.reset.error.invalid_token', ApiController::messageFor('invalid_reset_token'));
    }

    // ── Field ─────────────────────────────────────────────────────────────────

    public function test_a_field_reaches_the_envelope_NFR79(): void
    {
        $body = self::bodyFor(new ConflictException('duplicate_nickname', 409, null, 'nickname'));

        $this->assertSame('nickname', $body['error']['field']);
        $this->assertSame(['code', 'message', 'field'], array_keys($body['error']));
    }

    public function test_no_field_member_when_there_is_no_field_NFR79(): void
    {
        $body = self::bodyFor(new NotFoundException('session_not_found'));

        $this->assertArrayNotHasKey('field', $body['error']);
        $this->assertSame(['code', 'message'], array_keys($body['error']));
    }

    // ── Envelope shape ────────────────────────────────────────────────────────

    public function test_the_error_envelope_shape_is_the_locked_one_NFR79(): void
    {
        $mapped = ApiController::domainEnvelope(new NotFoundException('course_not_found'));

        $this->assertSame(
            [
                'status' => 404,
                'body' => [
                    'success' => false,
                    'error' => ['code' => 'course_not_found', 'message' => 'error.course_not_found'],
                ],
            ],
            $mapped
        );
    }

    /**
     * `DuplicateAnswerException` keeps its own class name for the catch sites
     * that use it, and must still map like the ConflictException it is.
     */
    public function test_duplicate_answer_maps_like_any_other_conflict_NFR79(): void
    {
        $mapped = ApiController::domainEnvelope(new DuplicateAnswerException('duplicate_answer'));

        $this->assertSame(409, $mapped['status']);
        $this->assertSame('duplicate_answer', $mapped['body']['error']['code']);
        $this->assertSame('error.duplicate_answer', $mapped['body']['error']['message']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function statusFor(\EduQR\Exceptions\DomainException $e): int
    {
        return ApiController::domainEnvelope($e)['status'];
    }

    /** @return array<string, mixed> */
    private static function bodyFor(\EduQR\Exceptions\DomainException $e): array
    {
        return ApiController::domainEnvelope($e)['body'];
    }
}
