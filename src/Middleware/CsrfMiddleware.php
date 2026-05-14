<?php

declare(strict_types=1);

namespace EduQR\Middleware;

use EduQR\Config;

/**
 * Double-submit cookie CSRF protection (NFR-24).
 *
 * Pattern:
 *  1. A random token is stored in the `csrf_token` cookie (not HttpOnly — JS must read it).
 *  2. Every state-changing request must echo the token back via:
 *       - X-CSRF-Token HTTP header  (AJAX / fetch)
 *       - _csrf hidden form field   (traditional HTML form POST)
 *  3. verify() compares cookie value with submitted value using hash_equals().
 *
 * Safe methods (GET, HEAD, OPTIONS) are always allowed.
 */
final class CsrfMiddleware
{
    private const COOKIE = 'csrf_token';

    public static function getToken(): string
    {
        if (!empty($_COOKIE[self::COOKIE])) {
            return $_COOKIE[self::COOKIE];
        }

        $token  = bin2hex(random_bytes(32));
        $secure = Config::bool('COOKIE_SECURE', true);
        setcookie(self::COOKIE, $token, [
            'expires'  => 0,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => false, // must be readable by JS for fetch() requests
            'samesite' => 'Strict',
        ]);
        $_COOKIE[self::COOKIE] = $token;

        return $token;
    }

    public static function verify(): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }

        $cookie = $_COOKIE[self::COOKIE] ?? '';
        if ($cookie === '') {
            self::fail();
        }

        // Prefer X-CSRF-Token header (AJAX), fall back to _csrf body field (HTML form).
        $submitted = trim($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($submitted === '') {
            $body      = self::parseBody();
            $submitted = (string) ($body['_csrf'] ?? '');
        }

        if (!hash_equals($cookie, $submitted)) {
            self::fail();
        }
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private static function parseBody(): array
    {
        $ct = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($ct, 'application/json')) {
            $raw = (string) file_get_contents('php://input');
            return json_decode($raw, true) ?? [];
        }
        return $_POST;
    }

    private static function fail(): never
    {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error'   => ['code' => 'csrf_invalid', 'message' => 'CSRF token mismatch.'],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
}
