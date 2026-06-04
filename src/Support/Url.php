<?php

declare(strict_types=1);

namespace EduQR\Support;

use EduQR\Config;

final class Url
{
    private static ?string $basePath = null;

    public static function reset(): void
    {
        self::$basePath = null;
    }

    public static function basePath(): string
    {
        if (self::$basePath === null) {
            self::$basePath = self::basePathFromAppUrl((string) Config::get('APP_URL', ''));
        }

        return self::$basePath;
    }

    public static function basePathFromAppUrl(string $appUrl): string
    {
        $path = (string) (parse_url(rtrim($appUrl, '/'), PHP_URL_PATH) ?? '');

        return self::normalizeBasePath($path);
    }

    public static function path(string $path = ''): string
    {
        return self::join(self::basePath(), $path);
    }

    public static function pathFromBasePath(string $basePath, string $path = ''): string
    {
        return self::join(self::normalizeBasePath($basePath), $path);
    }

    public static function absolute(string $path = ''): string
    {
        $appUrl = rtrim((string) Config::get('APP_URL', ''), '/');
        if ($appUrl === '') {
            return self::path($path);
        }

        return $appUrl . self::normalizeRelativePath($path);
    }

    public static function requestPath(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

        return self::stripBasePath($path);
    }

    public static function stripBasePath(string $path, ?string $basePath = null): string
    {
        $base = self::normalizeBasePath($basePath ?? self::basePath());
        $path = self::normalizeRequestPath($path);

        if ($base === '' || $base === '/') {
            return $path;
        }

        if ($path === $base) {
            return '/';
        }

        if (str_starts_with($path, $base . '/')) {
            return substr($path, strlen($base));
        }

        return $path;
    }

    private static function join(string $basePath, string $path): string
    {
        $path = self::normalizeRelativePath($path);

        return $basePath === '' ? $path : $basePath . $path;
    }

    private static function normalizeBasePath(string $path): string
    {
        $path = '/' . trim($path, '/');

        return $path === '/' ? '' : $path;
    }

    private static function normalizeRequestPath(string $path): string
    {
        if ($path === '') {
            return '/';
        }

        return $path[0] === '/' ? rtrim($path, '/') ?: '/' : '/' . rtrim($path, '/');
    }

    private static function normalizeRelativePath(string $path): string
    {
        if ($path === '' || $path === '/') {
            return '/';
        }

        return '/' . ltrim($path, '/');
    }
}
