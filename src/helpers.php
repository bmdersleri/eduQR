<?php

declare(strict_types=1);

use EduQR\I18n\I18nService;
use EduQR\I18n\LocaleHelper;
use EduQR\Support\Url;

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

/**
 * Return the configured deployment base path, if any.
 */
function eduqr_base_path(): string
{
    return Url::basePath();
}

/**
 * Build a path relative to the app mount point.
 */
function eduqr_path(string $path = ''): string
{
    return Url::path($path);
}

/**
 * Build an absolute public URL using APP_URL.
 */
function eduqr_url(string $path = ''): string
{
    return Url::absolute($path);
}

/**
 * Render a small inline icon used across the UI.
 */
function eduqr_icon(string $name, string $class = ''): string
{
    $classAttr = trim('eduqr-icon-inline ' . $class);
    $classAttr = htmlspecialchars($classAttr, ENT_QUOTES, 'UTF-8');

    return match ($name) {
        'spark' => '<svg class="' . $classAttr . '" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2.5 13.8 8.2 19.5 10 13.8 11.8 12 17.5 10.2 11.8 4.5 10 10.2 8.2 12 2.5Z"/></svg>',
        'qr' => '<svg class="' . $classAttr . '" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h6v6H4V4Zm2 2v2h2V6H6Zm8-2h6v6h-6V4Zm2 2v2h2V6h-2ZM4 14h6v6H4v-6Zm2 2v2h2v-2H6Zm10-2h2v2h-2v-2Zm-4 0h2v2h-2v-2Zm4 4h2v2h-2v-2Zm-4 0h2v2h-2v-2Zm4-8h2v2h-2V8Zm-4 4h2v2h-2v-2Z"/></svg>',
        'user' => '<svg class="' . $classAttr . '" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 12.2a4.8 4.8 0 1 0 0-9.6 4.8 4.8 0 0 0 0 9.6Zm0 2c-4.1 0-8 2.1-8 5.3V22h16v-2.5c0-3.2-3.9-5.3-8-5.3Z"/></svg>',
        'chart' => '<svg class="' . $classAttr . '" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 19h14v2H3V3h2v16Zm3-2V9h3v8H8Zm5 0V5h3v12h-3Zm5 0v-6h3v6h-3Z"/></svg>',
        'check' => '<svg class="' . $classAttr . '" viewBox="0 0 24 24" aria-hidden="true"><path d="M9.2 16.2 5.4 12.4 4 13.8l5.2 5.2L20 8.2 18.6 6.8 9.2 16.2Z"/></svg>',
        'clock' => '<svg class="' . $classAttr . '" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2Zm1 10.4 3.5 2.1-.9 1.5L11 13V6h2Z"/></svg>',
        'shield' => '<svg class="' . $classAttr . '" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2 4 5v6c0 5.2 3.3 9.8 8 11 4.7-1.2 8-5.8 8-11V5l-8-3Zm0 4.2 4 1.5V11c0 3.4-2 6.5-4 7.9-2-1.4-4-4.5-4-7.9V7.7l4-1.5Z"/></svg>',
        default => '<svg class="' . $classAttr . '" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/></svg>',
    };
}
