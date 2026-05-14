<?php

declare(strict_types=1);

use EduQR\I18n\I18nService;
use EduQR\I18n\LocaleHelper;

/**
 * Translate a locale key with optional {placeholder} substitution.
 *
 * @param array<string, scalar> $params
 */
function t(string $key, array $params = []): string
{
    return I18nService::translate($key, $params);
}

/**
 * Translate a plural form.
 * Looks up "{key}.one" for count === 1, "{key}.other" otherwise.
 *
 * @param array<string, scalar> $params
 */
function tn(string $key, int $count, array $params = []): string
{
    return I18nService::translatePlural($key, $count, $params);
}

/**
 * Format a date using the active locale (or an explicit one).
 */
function fmt_date(int|string $ts, ?string $locale = null): string
{
    return LocaleHelper::formatDate($ts, $locale ?? I18nService::getLocale());
}

/**
 * Format a number using the active locale (or an explicit one).
 */
function fmt_number(float $n, ?string $locale = null): string
{
    return LocaleHelper::formatNumber($n, $locale ?? I18nService::getLocale());
}

/**
 * Format a percentage value (0–100) using the active locale (or an explicit one).
 */
function fmt_percent(float $n, ?string $locale = null): string
{
    return LocaleHelper::formatPercent($n, $locale ?? I18nService::getLocale());
}
