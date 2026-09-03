<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use EduQR\Exceptions\NotFoundException;
use EduQR\Http\FailureResponse;
use EduQR\I18n\I18nService;
use PHPUnit\Framework\TestCase;

final class FailureResponseTest extends TestCase
{
    protected function setUp(): void
    {
        // Pin the locale so the envelope contains predictable messages
        I18nService::init(dirname(__DIR__, 3) . '/locales', 'en');
    }
    public function test_api_paths_want_json_NFR85(): void
    {
        $this->assertTrue(FailureResponse::wantsJson('/api/v1/courses'));
        $this->assertTrue(FailureResponse::wantsJson('/api/v1/sessions/12?since=4'));
    }

    public function test_html_paths_do_not_want_json_NFR85(): void
    {
        $this->assertFalse(FailureResponse::wantsJson('/admin/courses'));
        $this->assertFalse(FailureResponse::wantsJson('/'));
        $this->assertFalse(FailureResponse::wantsJson('/join/ABC123'));
        $this->assertFalse(FailureResponse::wantsJson(null));
        $this->assertFalse(FailureResponse::wantsJson('/apiv1/courses'));
    }

    public function test_unexpected_throwable_becomes_a_500_server_error_NFR85(): void
    {
        $payload = FailureResponse::payloadFor(new \RuntimeException('connection refused at 10.0.0.4'));

        $this->assertSame(500, $payload['status']);
        $this->assertFalse($payload['body']['success']);
        $this->assertSame('server_error', $payload['body']['error']['code']);
    }

    public function test_the_response_never_carries_the_internal_message_NFR85(): void
    {
        $encoded = json_encode(
            FailureResponse::payloadFor(new \RuntimeException('connection refused at 10.0.0.4')),
            JSON_UNESCAPED_UNICODE
        );

        $this->assertStringNotContainsString('connection refused', (string) $encoded);
        $this->assertStringNotContainsString('10.0.0.4', (string) $encoded);
        $this->assertStringNotContainsString('RuntimeException', (string) $encoded);
    }

    public function test_a_domain_exception_keeps_its_status_and_code_NFR85(): void
    {
        $payload = FailureResponse::payloadFor(new NotFoundException('course_not_found'));

        $this->assertSame(404, $payload['status']);
        $this->assertSame('course_not_found', $payload['body']['error']['code']);
    }

    public function test_a_php_error_is_answered_like_any_other_failure_NFR85(): void
    {
        $payload = FailureResponse::payloadFor(new \Error('Call to a member function id() on null'));

        $this->assertSame(500, $payload['status']);
        $this->assertSame('server_error', $payload['body']['error']['code']);
    }

    public function test_fatal_error_types_are_recognised_NFR85(): void
    {
        $this->assertTrue(FailureResponse::isFatal(['type' => E_ERROR, 'message' => 'x', 'file' => 'f', 'line' => 1]));
        $this->assertTrue(FailureResponse::isFatal(['type' => E_PARSE, 'message' => 'x', 'file' => 'f', 'line' => 1]));
        $this->assertFalse(FailureResponse::isFatal(['type' => E_WARNING, 'message' => 'x', 'file' => 'f', 'line' => 1]));
        $this->assertFalse(FailureResponse::isFatal(null));
    }
}
