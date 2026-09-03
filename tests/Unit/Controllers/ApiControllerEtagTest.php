<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit\Controllers;

use EduQR\Controllers\ApiController;
use PHPUnit\Framework\TestCase;

/**
 * Conditional answers to a poll — NFR-76.
 *
 * The mechanism lives in ApiController rather than in the five polled
 * controllers, so what an ETag is, what counts as a match, and what a 304
 * carries are decided once. All three are asserted here.
 *
 * Sending the response cannot be asserted: every terminal method of
 * ApiController ends in `exit`, which no test can survive. So the decision is
 * split from the emission — etagMatches() decides, notModifiedResponse()
 * describes what is emitted, and etagOrNotModified() only glues them together.
 * The half that is left untested is four lines of header() calls.
 *
 * @requirement NFR-76
 */
final class ApiControllerEtagTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_IF_NONE_MATCH']);
    }

    // ── What an ETag is ───────────────────────────────────────────────────────

    /**
     * @requirement NFR-76
     */
    public function testTheSameVersionAlwaysProducesTheSameQuotedEtag(): void
    {
        $etag = ApiController::etagFor('1|active|2026-09-03 10:00:00');

        self::assertSame($etag, ApiController::etagFor('1|active|2026-09-03 10:00:00'));
        self::assertMatchesRegularExpression('/^"[0-9a-f]{64}"$/', $etag);
    }

    /**
     * The version is hashed, not sent: it is a list of internal ids and row
     * counts, and none of that needs to reach a browser.
     *
     * @requirement NFR-76
     */
    public function testADifferentVersionProducesADifferentEtag(): void
    {
        self::assertNotSame(
            ApiController::etagFor('1|active|3|9|0'),
            ApiController::etagFor('1|active|4|10|0'),
        );
        self::assertStringNotContainsString('active', ApiController::etagFor('1|active|3|9|0'));
    }

    // ── What counts as a match ────────────────────────────────────────────────

    /**
     * @requirement NFR-76
     */
    public function testAnExactlyEqualIfNoneMatchMatches(): void
    {
        $etag = ApiController::etagFor('v1');

        self::assertTrue(ApiController::etagMatches($etag, $etag));
    }

    /**
     * @requirement NFR-76
     */
    public function testADifferentIfNoneMatchDoesNotMatch(): void
    {
        self::assertFalse(
            ApiController::etagMatches(ApiController::etagFor('v1'), ApiController::etagFor('v2')),
        );
    }

    /**
     * A first request carries no If-None-Match and is always answered 200 —
     * which is why no existing test of these endpoints had to change.
     *
     * @requirement NFR-76
     */
    public function testAMissingOrEmptyIfNoneMatchNeverMatches(): void
    {
        $etag = ApiController::etagFor('v1');

        self::assertFalse(ApiController::etagMatches($etag, null));
        self::assertFalse(ApiController::etagMatches($etag, ''));
        self::assertFalse(ApiController::etagMatches($etag, '   '));
    }

    /**
     * The three shapes RFC 9110 §13.1.2 allows besides a bare tag.
     *
     * @requirement NFR-76
     */
    public function testTheWildcardAListAndTheWeakPrefixAllMatch(): void
    {
        $etag = ApiController::etagFor('v1');
        $other = ApiController::etagFor('v2');

        self::assertTrue(ApiController::etagMatches($etag, '*'));
        self::assertTrue(ApiController::etagMatches($etag, $other . ', ' . $etag));
        self::assertTrue(ApiController::etagMatches($etag, 'W/' . $etag));
        self::assertFalse(ApiController::etagMatches($etag, $other . ', ' . ApiController::etagFor('v3')));
    }

    // ── What a 304 carries ────────────────────────────────────────────────────

    /**
     * A 304 carries the ETag header and no body; a 200 carries both.
     *
     * @requirement NFR-76
     */
    public function testANotModifiedResponseIsTheEtagAndNothingElse(): void
    {
        $etag = ApiController::etagFor('v1');
        $response = ApiController::notModifiedResponse($etag);

        self::assertSame(304, $response['status']);
        self::assertSame(['ETag: ' . $etag], $response['headers']);
        self::assertSame('', $response['body']);
    }

    // ── The glue ──────────────────────────────────────────────────────────────

    /**
     * With no matching header the endpoint carries on and the ETag is kept for
     * the 200 that follows.
     *
     * @requirement NFR-76
     */
    public function testAnUnmatchedPollCarriesOnAndRemembersItsEtag(): void
    {
        $controller = $this->controller();
        $controller->poll('v1');

        self::assertSame(ApiController::etagFor('v1'), $this->rememberedEtag($controller));
    }

    /**
     * A header that matches a *different* resource's version does not stop this
     * one.
     *
     * @requirement NFR-76
     */
    public function testAPollWhoseStateMovedIsNotAnsweredFromTheHeader(): void
    {
        $_SERVER['HTTP_IF_NONE_MATCH'] = ApiController::etagFor('v1');

        $controller = $this->controller();
        $controller->poll('v2');

        self::assertSame(ApiController::etagFor('v2'), $this->rememberedEtag($controller));
    }

    /**
     * Endpoints that never call it are untouched: no ETag is remembered, so
     * nothing new is emitted for them.
     *
     * @requirement NFR-76
     */
    public function testAnEndpointThatAsksForNoEtagHasNone(): void
    {
        self::assertNull($this->rememberedEtag($this->controller()));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** A concrete ApiController that exposes the protected conditional hook. */
    private function controller(): ApiController
    {
        return new class () extends ApiController {
            public function poll(string $version): void
            {
                $this->etagOrNotModified($version);
            }
        };
    }

    private function rememberedEtag(ApiController $controller): ?string
    {
        $property = new \ReflectionProperty(ApiController::class, 'etag');

        /** @var string|null */
        return $property->getValue($controller);
    }
}
