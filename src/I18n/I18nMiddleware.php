<?php

declare(strict_types=1);

namespace EduQR\I18n;

final class I18nMiddleware
{
    private const SUPPORTED = ['en', 'tr'];
    private const COOKIE    = 'locale';
    private const COOKIE_TTL = 365 * 24 * 3600; // 1 year

    /**
     * Determine locale for this request, init I18nService, and set/refresh the locale cookie.
     *
     * Priority: URL prefix → ?lang= query → locale cookie → Accept-Language header → 'en'
     */
    public static function resolve(string $localesPath): string
    {
        $locale = self::fromUri()
            ?? self::fromQuery()
            ?? self::fromCookie()
            ?? self::fromAcceptLanguage()
            ?? 'en';

        if (!headers_sent()) {
            setcookie(self::COOKIE, $locale, [
                'expires'  => time() + self::COOKIE_TTL,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure'   => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            ]);
        }

        I18nService::init($localesPath, $locale);

        return $locale;
    }

    private static function fromUri(): ?string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        $first = explode('/', ltrim((string) $path, '/'))[0] ?? '';
        return in_array($first, self::SUPPORTED, true) ? $first : null;
    }

    private static function fromQuery(): ?string
    {
        $lang = $_GET['lang'] ?? '';
        return in_array($lang, self::SUPPORTED, true) ? $lang : null;
    }

    private static function fromCookie(): ?string
    {
        $cookie = $_COOKIE[self::COOKIE] ?? '';
        return in_array($cookie, self::SUPPORTED, true) ? $cookie : null;
    }

    private static function fromAcceptLanguage(): ?string
    {
        $header = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        if ($header === '') {
            return null;
        }
        // Parse "tr-TR,tr;q=0.9,en-US;q=0.8,en;q=0.7"
        foreach (explode(',', $header) as $part) {
            $base = strtolower(substr(trim(explode(';', $part)[0]), 0, 2));
            if (in_array($base, self::SUPPORTED, true)) {
                return $base;
            }
        }
        return null;
    }
}
