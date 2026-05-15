<?php

declare(strict_types=1);

namespace EduQR\Middleware;

/**
 * Best-effort IP-based rate limiter for student-facing endpoints (T-1003).
 *
 * Uses APCu when the extension is loaded and enabled.
 * Falls back silently when APCu is absent — application-layer duplicate checks
 * (unique DB constraints, participant cookie, CSRF) still apply.
 *
 * Usage:
 *   $ip = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|join');
 *   RateLimitMiddleware::check("join:{$ip}", 20, 600);
 *   // → exits with 429 if the same bucket exceeds 20 requests in 600 s
 */
final class RateLimitMiddleware
{
    /**
     * Increment the counter for $bucket.
     * Exits with HTTP 429 if the count exceeds $max within $windowSeconds.
     *
     * @param string $bucket      Unique identifier, e.g. "join:<ip-hash>"
     * @param int    $max         Maximum requests allowed in the window
     * @param int    $windowSeconds Window duration in seconds
     */
    public static function check(string $bucket, int $max, int $windowSeconds): void
    {
        if (!extension_loaded('apcu') || !apcu_enabled()) {
            // APCu not available — best-effort only; DB-level guards remain
            return;
        }

        $key   = 'rl.' . hash('sha256', $bucket);
        $count = apcu_inc($key, 1);

        if ($count === false) {
            // Key did not exist yet — create it with TTL = window size.
            // First request in this window is always allowed.
            apcu_store($key, 1, $windowSeconds);
            return;
        }

        if ($count > $max) {
            self::tooManyRequests($windowSeconds);
        }
    }

    private static function tooManyRequests(int $retryAfter): never
    {
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        header('Retry-After: ' . $retryAfter);
        echo json_encode([
            'success' => false,
            'error'   => [
                'code'    => 'rate_limited',
                'message' => 'Too many requests. Please wait and try again.',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
}
