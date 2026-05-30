<?php

declare(strict_types=1);

namespace EduQR\Support;

final class ProfanityFilter
{
    /** @var array<string, string[]> cache keyed by locale */
    private static array $cache = [];

    public static function isProfane(string $word, string $locale, string $configDir): bool
    {
        $words = self::load($locale, $configDir);
        $needle = mb_strtolower(trim($word), 'UTF-8');

        foreach ($words as $banned) {
            // Exact match or contains the banned word as a substring
            if ($needle === $banned || str_contains($needle, $banned)) {
                return true;
            }
        }

        return false;
    }

    private static function load(string $locale, string $configDir): array
    {
        // Sanitize locale to prevent directory traversal
        $safe = preg_replace('/[^a-z]/', '', strtolower($locale));
        $key = $configDir . '/' . $safe;

        if (! isset(self::$cache[$key])) {
            $path = rtrim($configDir, '/') . '/' . $safe . '.txt';
            $lines = [];

            if (is_file($path) && is_readable($path)) {
                foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#')) {
                        continue;
                    }
                    $lines[] = mb_strtolower($line, 'UTF-8');
                }
            }

            self::$cache[$key] = $lines;
        }

        return self::$cache[$key];
    }
}
