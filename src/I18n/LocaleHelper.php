<?php

declare(strict_types=1);

namespace EduQR\I18n;

use IntlDateFormatter;
use NumberFormatter;

final class LocaleHelper
{
    private const ICU_MAP = [
        'en' => 'en_US',
        'tr' => 'tr_TR',
    ];

    /**
     * Format a Unix timestamp or parseable date string using the active locale.
     * Returns an ISO date string as a safe fallback if intl fails.
     */
    public static function formatDate(int|string $ts, string $locale = 'en'): string
    {
        $timestamp = is_string($ts) ? (int) strtotime($ts) : $ts;
        $icu = self::ICU_MAP[$locale] ?? 'en_US';

        $fmt = new IntlDateFormatter(
            $icu,
            IntlDateFormatter::MEDIUM,
            IntlDateFormatter::NONE,
            'UTC'
        );

        $result = $fmt->format($timestamp);
        return $result !== false ? $result : gmdate('Y-m-d', $timestamp);
    }

    /**
     * Format a number according to the active locale.
     */
    public static function formatNumber(float $n, string $locale = 'en'): string
    {
        $icu = self::ICU_MAP[$locale] ?? 'en_US';
        $fmt = new NumberFormatter($icu, NumberFormatter::DECIMAL);
        $result = $fmt->format($n);
        return $result !== false ? $result : (string) $n;
    }

    /**
     * Format a percentage value (pass 0–100, not 0–1).
     * E.g. formatPercent(75.5, 'tr') → "%75,5" (Turkish convention)
     */
    public static function formatPercent(float $n, string $locale = 'en'): string
    {
        $icu = self::ICU_MAP[$locale] ?? 'en_US';
        $fmt = new NumberFormatter($icu, NumberFormatter::PERCENT);
        $fmt->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 1);
        $result = $fmt->format($n / 100.0);
        return $result !== false ? $result : round($n, 1) . '%';
    }
}
