<?php

declare(strict_types=1);

namespace EduQR\Http;

use EduQR\Controllers\ApiController;
use EduQR\Exceptions\DomainException;
use EduQR\Support\Url;

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

    /**
     * True when the caller asked for an API route and therefore expects the
     * envelope.
     *
     * Strips the deployment base path the same way {@see Router::dispatch()}
     * does before testing the prefix — a subfolder mount (NFR-15) must not
     * make an API request fall through to the HTML branch. [NFR-85]
     */
    public static function wantsJson(?string $requestUri): bool
    {
        if ($requestUri === null || $requestUri === '') {
            return false;
        }

        $path = parse_url($requestUri, PHP_URL_PATH);
        if (! is_string($path)) {
            return false;
        }

        return str_starts_with(Url::stripBasePath($path), self::API_PREFIX);
    }

    private const FATAL_TYPES = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

    /**
     * True when error_get_last() describes a failure that killed the request
     * before the exception handler could run.
     *
     * @param array{type:int,message:string,file:string,line:int}|null $lastError
     */
    public static function isFatal(?array $lastError): bool
    {
        return $lastError !== null && in_array($lastError['type'], self::FATAL_TYPES, true);
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
