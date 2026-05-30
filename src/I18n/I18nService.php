<?php

declare(strict_types=1);

namespace EduQR\I18n;

final class I18nService
{
    private static string $locale = 'en';
    private static string $localesPath = '';
    /** @var array<string, string> */
    private static array $strings = [];
    /** @var array<string, string> */
    private static array $fallback = [];
    private static bool $fallbackLoaded = false;

    /**
     * Called once per request by I18nMiddleware.
     */
    public static function init(string $localesPath, string $locale = 'en'): void
    {
        self::$localesPath = $localesPath;
        self::$strings = [];
        self::$fallback = [];
        self::$fallbackLoaded = false;
        self::$locale = 'en';
        self::setLocale($locale);
    }

    public static function setLocale(string $locale): void
    {
        if ($locale === self::$locale && ! empty(self::$strings)) {
            return;
        }

        self::$locale = $locale;
        self::$strings = self::loadFile($locale);

        if ($locale !== 'en') {
            self::$fallback = self::loadFile('en');
            self::$fallbackLoaded = true;
        } else {
            self::$fallback = [];
            self::$fallbackLoaded = false;
        }
    }

    public static function getLocale(): string
    {
        return self::$locale;
    }

    /** @return string[] */
    public static function availableLocales(): array
    {
        return ['en', 'tr'];
    }

    /**
     * Translate a key with optional {placeholder} substitution.
     * Fallback chain: locale → en → key itself.
     *
     * @param array<string, scalar> $params
     */
    public static function translate(string $key, array $params = []): string
    {
        $raw = self::$strings[$key] ?? self::$fallback[$key] ?? $key;

        return empty($params) ? $raw : self::interpolate($raw, $params);
    }

    /**
     * Translate a plural form.
     * Looks for "{key}.one" (count === 1) or "{key}.other", falls back to base key.
     *
     * @param array<string, scalar> $params
     */
    public static function translatePlural(string $key, int $count, array $params = []): string
    {
        $form = $count === 1 ? 'one' : 'other';
        $pluralKey = $key . '.' . $form;

        $raw = self::$strings[$pluralKey]
            ?? self::$fallback[$pluralKey]
            ?? self::$strings[$key]
            ?? self::$fallback[$key]
            ?? $key;

        $params['count'] = $count;

        return self::interpolate($raw, $params);
    }

    /** @return array<string, string> */
    private static function loadFile(string $locale): array
    {
        if (self::$localesPath === '') {
            return [];
        }
        $path = rtrim(self::$localesPath, '/') . '/' . $locale . '.json';
        if (! is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, scalar> $params */
    private static function interpolate(string $text, array $params): string
    {
        foreach ($params as $key => $value) {
            $text = str_replace('{' . $key . '}', (string) $value, $text);
        }

        return $text;
    }
}
