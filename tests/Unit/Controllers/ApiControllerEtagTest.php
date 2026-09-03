<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit\Controllers;

use EduQR\Controllers\ApiController;
use PHPUnit\Framework\Attributes\DataProvider;
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
     * A 304 carries the ETag, the terms the caller may keep it under, and no
     * body.
     *
     * @requirement NFR-76
     */
    public function testANotModifiedResponseIsTheEtagAndTheCachingTerms(): void
    {
        $etag = ApiController::etagFor('v1');
        $response = ApiController::notModifiedResponse($etag);

        self::assertSame(304, $response['status']);
        self::assertSame(
            array_merge(['ETag: ' . $etag], ApiController::revalidationHeaders()),
            $response['headers'],
        );
        self::assertSame('', $response['body']);
    }

    // ── Why the caching terms are sent at all ─────────────────────────────────

    /**
     * `no-store` anywhere in a poll response makes the whole mechanism dead
     * code: the browser keeps nothing, so it sends no `If-None-Match`, so the
     * server is never asked a question it could answer 304.
     *
     * @requirement NFR-76
     */
    public function testThePollResponseNeverForbidsStoring(): void
    {
        $cacheControl = null;

        foreach (ApiController::revalidationHeaders() as $header) {
            if (stripos($header, 'Cache-Control:') === 0) {
                $cacheControl = $header;
            }
        }

        self::assertNotNull($cacheControl, 'A poll response must state its caching terms.');
        self::assertStringNotContainsStringIgnoringCase('no-store', $cacheControl);
        // Per-user data: storable by the browser that asked, by nothing between.
        self::assertStringContainsString('private', $cacheControl);
        // Every reuse is a revalidation, so a stored body is only ever shown
        // again after this class has said 304.
        self::assertStringContainsString('no-cache', $cacheControl);
    }

    /**
     * The header this replaces, pinned so the reason survives.
     *
     * Four of the five polled endpoints authenticate, authentication starts a
     * PHP session, and PHP's default `session.cache_limiter` sends `no-store`.
     * If this default ever changes, the override above stops being load-bearing
     * and this test says so.
     *
     * @requirement NFR-76
     */
    public function testPhpsSessionDefaultIsTheHeaderBeingOverridden(): void
    {
        self::assertSame('nocache', ini_get('session.cache_limiter'));
    }

    /**
     * The 200 path sends the same terms as the 304 path.
     *
     * Asserted on the source because `json()` ends in `exit`. If a 200 were
     * allowed to keep PHP's `no-store`, the browser would discard the very
     * response whose `ETag` the next poll depends on.
     *
     * @requirement NFR-76
     */
    public function testTheTwoHundredPathAlsoSendsTheCachingTerms(): void
    {
        $json = self::methodBody('src/Controllers/ApiController.php', 'json');

        self::assertStringContainsString('emitRevalidationHeaders', $json);
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

    // ── The five polled endpoints ─────────────────────────────────────────────

    /**
     * Every endpoint API_SPEC §1.9 lists asks for a conditional answer, and asks
     * for it before it builds a body. The inventory is asserted from the source
     * because none of these methods can be dispatched from a test.
     *
     * @requirement NFR-76
     */
    #[DataProvider('polledEndpoints')]
    public function testEveryPolledEndpointAnswersConditionally(string $file, string $method): void
    {
        $body = self::methodBody($file, $method);

        self::assertStringContainsString(
            '$this->etagOrNotModified(',
            $body,
            $method . '() is polled on a timer and must be answerable with a 304.',
        );
    }

    /**
     * §1.9 gives the participant count no version query of its own: the count is
     * the whole body, so the count is the version. It is therefore the one
     * endpoint whose ETag is built in the controller, and it is still built from
     * a value the authorization check has already produced.
     *
     * @requirement NFR-76
     */
    public function testTheParticipantCountIsItsOwnVersion(): void
    {
        $body = self::methodBody('src/Controllers/Api/SessionController.php', 'participantCount');

        self::assertStringContainsString('$count = $this->service->getParticipantCount(', $body);
        self::assertStringContainsString("\$this->etagOrNotModified('participants|' . \$id . '|' . \$count)", $body);
        self::assertLessThan(
            strpos($body, '$this->etagOrNotModified('),
            strpos($body, 'getParticipantCount('),
            'The count is authorized before it is turned into an ETag.',
        );
    }

    /** @return array<string, array{string, string}> */
    public static function polledEndpoints(): array
    {
        return [
            'active-question' => ['src/Controllers/Api/PublicQuestionController.php', 'activeQuestion'],
            'results' => ['src/Controllers/Api/ResultsController.php', 'instructorResults'],
            'participants/count' => ['src/Controllers/Api/SessionController.php', 'participantCount'],
            'reactions' => ['src/Controllers/Api/ReactionController.php', 'aggregates'],
            'questions' => ['src/Controllers/Api/QuestionController.php', 'index'],
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function methodBody(string $file, string $method): string
    {
        $path = \dirname(__DIR__, 3) . '/' . $file;
        $source = file_get_contents($path);
        self::assertIsString($source, $file . ' must be readable.');

        $start = strpos($source, 'function ' . $method . '(');
        self::assertIsInt($start, $method . '() must exist in ' . $file . '.');

        $end = strpos($source, "\n    }", $start);
        self::assertIsInt($end, $method . '() must be a method of ' . $file . '.');

        return substr($source, $start, $end - $start);
    }

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
