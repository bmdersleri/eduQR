<?php

declare(strict_types=1);

namespace EduQR\Http;

use EduQR\Controllers\ApiController;
use EduQR\Exceptions\DomainException;

/**
 * Decides how a failure that escaped every controller is answered. [NFR-85]
 *
 * The global handlers in Bootstrap own the side effects — logging, status line,
 * headers, echo. Everything decidable is decided here, so it can be tested
 * without a request.
 */
final class FailureResponse
{
    private const API_PREFIX = '/api/v1/';

    /** True when the caller asked for an API route and therefore expects the envelope. */
    public static function wantsJson(?string $requestUri): bool
    {
        if ($requestUri === null || $requestUri === '') {
            return false;
        }

        $path = parse_url($requestUri, PHP_URL_PATH);

        return is_string($path) && str_starts_with($path, self::API_PREFIX);
    }

    /**
     * The envelope for a throwable. A DomainException keeps its published status
     * and code; anything else is a 500 with no detail — the detail belongs in
     * logs/app.log, never in the response.
     *
     * @return array{status:int, body:array<string,mixed>}
     */
    public static function payloadFor(\Throwable $e): array
    {
        if ($e instanceof DomainException) {
            return ApiController::domainEnvelope($e);
        }

        return [
            'status' => 500,
            'body' => [
                'success' => false,
                'error' => [
                    'code' => 'server_error',
                    'message' => self::message('server_error'),
                ],
            ],
        ];
    }

    /**
     * Translations may not be loaded yet — the handler can fire before
     * I18nMiddleware::resolve(). Fall back to the bare code rather than fataling
     * inside the failure path.
     */
    private static function message(string $errorCode): string
    {
        try {
            return ApiController::messageFor($errorCode);
        } catch (\Throwable) {
            return $errorCode;
        }
    }
}
