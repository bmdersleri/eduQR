<?php

declare(strict_types=1);

namespace EduQR\Middleware;

use EduQR\Container;
use EduQR\Support\Url;

/**
 * Guards instructor / admin routes.
 *
 * JSON callers (Accept: application/json or /api/ in the URI) get a 401 JSON response.
 * HTML callers are redirected to /login.
 */
final class AuthMiddleware
{
    /**
     * Require an authenticated user of any role.
     * Returns the current user array, or exits with 401 / redirect.
     */
    public static function require(): array
    {
        // Every authenticated request confirms the account against its row, so
        // deactivating or deleting a user ends the session it already has and a
        // role change takes effect at once [NFR-87].
        $user = Container::authService()->authenticatedUser();
        if ($user === null) {
            self::unauthorized();
        }

        return $user;
    }

    /**
     * Require an authenticated user whose role is one of $roles.
     * Returns the current user array, or exits with 401 / 403.
     */
    public static function requireRole(string ...$roles): array
    {
        $user = self::require();
        if (! in_array($user['role'], $roles, true)) {
            self::forbidden();
        }

        return $user;
    }

    // ── Response helpers ───────────────────────────────────────────────────────

    private static function unauthorized(): never
    {
        if (self::wantsJson()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error' => [
                    'code' => 'not_authenticated',
                    'message' => function_exists('t') ? t('error.not_authenticated') : 'Please sign in.',
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } else {
            http_response_code(302);
            header('Location: ' . Url::path('/login'));
        }
        exit;
    }

    private static function forbidden(): never
    {
        if (self::wantsJson()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error' => [
                    'code' => 'forbidden',
                    'message' => function_exists('t') ? t('error.forbidden') : 'Access denied.',
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } else {
            http_response_code(403);
            if (file_exists(__DIR__ . '/../../templates/errors/403.php')) {
                include __DIR__ . '/../../templates/errors/403.php';
            } else {
                echo 'Forbidden.';
            }
        }
        exit;
    }

    private static function wantsJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $uri = $_SERVER['REQUEST_URI'] ?? '';

        return str_contains($accept, 'application/json')
            || str_contains($uri, '/api/');
    }
}
