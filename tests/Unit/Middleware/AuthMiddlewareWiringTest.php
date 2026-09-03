<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use PHPUnit\Framework\TestCase;

/**
 * NFR-87: `AuthMiddleware::require()` must call `AuthService::authenticatedUser()`,
 * not the older `AuthService::currentUser()`.
 *
 * That line cannot be pinned by dispatching the middleware: on a session that
 * fails revalidation, `unauthorized()` is declared `: never` and ends the
 * request with `exit`, which would end the test process too. Reverting
 * `require()` to `currentUser()` removes the whole NFR-87 fix — a deactivated
 * or deleted account would keep its live session — yet every runtime test
 * would stay green, because `currentUser()` also returns an array shaped
 * like the one `authenticatedUser()` returns, for the request that never
 * revalidates. So this test reads the source instead of running it.
 */
final class AuthMiddlewareWiringTest extends TestCase
{
    public function test_require_calls_authenticated_user_not_current_user_NFR87(): void
    {
        $source = $this->sourceOf(\dirname(__DIR__, 3) . '/src/Middleware/AuthMiddleware.php');

        $this->assertStringContainsString(
            'authenticatedUser()',
            $source,
            'AuthMiddleware::require() must revalidate the session via AuthService::authenticatedUser().',
        );

        $this->assertStringNotContainsString(
            'AuthService::currentUser()',
            $source,
            'AuthMiddleware::require() must not read the unrevalidated session copy via AuthService::currentUser().',
        );
    }

    private function sourceOf(string $path): string
    {
        $source = file_get_contents($path);
        self::assertNotFalse($source, 'Could not read ' . $path);

        return $source;
    }
}
