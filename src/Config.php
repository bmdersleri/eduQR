<?php

declare(strict_types=1);

namespace EduQR;

/**
 * Tiny .env file parser and config registry.
 *
 * Fails loudly if .env is missing in production (APP_ENV=production).
 * Values are accessed via Config::get('KEY', $default).
 */
final class Config
{
    private static array $data = [];
    private static bool $loaded = false;

    public static function load(string $envPath): void
    {
        if (self::$loaded) {
            return;
        }

        if (!file_exists($envPath)) {
            $env = getenv('APP_ENV') ?: 'production';
            if ($env === 'production') {
                http_response_code(500);
                error_log('[eduQR] FATAL: .env file missing at ' . $envPath);
                exit('Configuration error. Please contact the administrator.');
            }
            // Development without .env — use only system env vars
            self::$loaded = true;
            return;
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);

            // Strip surrounding quotes
            if (
                strlen($value) >= 2
                && (
                    (str_starts_with($value, '"') && str_ends_with($value, '"'))
                    || (str_starts_with($value, "'") && str_ends_with($value, "'"))
                )
            ) {
                $value = substr($value, 1, -1);
            }

            self::$data[$key] = $value;

            // Also expose to getenv() / $_ENV for libraries that use those
            if (!array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $value;
                putenv("{$key}={$value}");
            }
        }

        self::$loaded = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$data)) {
            return self::$data[$key];
        }
        $env = getenv($key);
        return $env !== false ? $env : $default;
    }

    public static function require(string $key): string
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            throw new \RuntimeException("Required config key '{$key}' is missing or empty.");
        }
        return (string) $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $val = self::get($key);
        if ($val === null) {
            return $default;
        }
        return in_array(strtolower((string) $val), ['true', '1', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $val = self::get($key);
        return $val !== null ? (int) $val : $default;
    }
}
