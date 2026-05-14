#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * bin/locale-check.php — locale coverage gate
 *
 * Usage:
 *   php bin/locale-check.php [locale] [--min=N]
 *
 * Checks that the given locale has at least N% key coverage vs locales/en.json.
 * Exits 0 on pass, 1 on coverage failure, 2 on missing files.
 *
 * Examples:
 *   php bin/locale-check.php tr          # check Turkish, require >= 95%
 *   php bin/locale-check.php tr --min=100
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(2);
}

$locale     = 'tr';
$minPercent = 95;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--min=')) {
        $minPercent = (int) substr($arg, 6);
    } elseif (!str_starts_with($arg, '-')) {
        $locale = $arg;
    }
}

$root       = dirname(__DIR__);
$enFile     = $root . '/locales/en.json';
$targetFile = $root . '/locales/' . $locale . '.json';

if (!is_file($enFile)) {
    fwrite(STDERR, "Error: locales/en.json not found.\n");
    exit(2);
}

if (!is_file($targetFile)) {
    fwrite(STDERR, "Error: locales/{$locale}.json not found.\n");
    exit(2);
}

$en     = json_decode((string) file_get_contents($enFile),     true);
$target = json_decode((string) file_get_contents($targetFile), true);

if (!is_array($en) || !is_array($target)) {
    fwrite(STDERR, "Error: could not parse JSON files.\n");
    exit(2);
}

$enKeys     = array_keys($en);
$targetKeys = array_keys($target);

$total    = count($enKeys);
$missing  = array_diff($enKeys, $targetKeys);
$present  = $total - count($missing);
$coverage = $total > 0 ? ($present / $total) * 100.0 : 100.0;

echo "Locale    : {$locale}\n";
echo "Coverage  : {$present}/{$total} keys (" . round($coverage, 2) . "%)\n";
echo "Threshold : {$minPercent}%\n";

if (!empty($missing)) {
    echo "\nMissing keys (" . count($missing) . "):\n";
    foreach ($missing as $key) {
        echo "  - {$key}\n";
    }
}

echo "\n";

if ($coverage < $minPercent) {
    echo "FAIL: Coverage " . round($coverage, 2) . "% < required {$minPercent}%.\n";
    exit(1);
}

echo "PASS: Coverage " . round($coverage, 2) . "% >= required {$minPercent}%.\n";
exit(0);
