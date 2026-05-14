#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * First-time setup helper. [NFR-61]
 *
 * Checks:
 *   - PHP version >= 8.2
 *   - Required extensions: pdo_mysql, mbstring, gd, intl, json
 *   - .env file exists (or scaffolds from .env.example)
 *   - logs/ directory is writable
 *   - vendor/ exists (composer install)
 *
 * Refuses to run twice in production (APP_ENV=production + .env already configured).
 */

$projectRoot = dirname(__DIR__);
$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    http_response_code(403);
    exit('This script must be run from the command line.');
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function ok(string $msg): void  { echo "\033[32m[OK]\033[0m  {$msg}\n"; }
function err(string $msg): void { echo "\033[31m[ERR]\033[0m {$msg}\n"; }
function info(string $msg): void{ echo "\033[34m[--]\033[0m  {$msg}\n"; }

$errors = 0;

// ── PHP version ───────────────────────────────────────────────────────────────

if (PHP_VERSION_ID >= 80200) {
    ok('PHP ' . PHP_VERSION);
} else {
    err('PHP 8.2+ required. Current: ' . PHP_VERSION);
    $errors++;
}

// ── Required extensions ───────────────────────────────────────────────────────

$required = ['pdo_mysql', 'mbstring', 'gd', 'intl', 'json'];
foreach ($required as $ext) {
    if (extension_loaded($ext)) {
        ok("ext-{$ext}");
    } else {
        err("Missing extension: {$ext}");
        $errors++;
    }
}

// ── vendor/ ───────────────────────────────────────────────────────────────────

if (is_dir($projectRoot . '/vendor')) {
    ok('vendor/ present');
} else {
    err('vendor/ missing — run: composer install');
    $errors++;
}

// ── .env scaffolding ──────────────────────────────────────────────────────────

$envPath     = $projectRoot . '/.env';
$examplePath = $projectRoot . '/.env.example';

if (file_exists($envPath)) {
    // Production guard: refuse to overwrite a configured .env
    $content = file_get_contents($envPath);
    if (
        str_contains($content, 'APP_ENV=production')
        && !str_contains($content, 'APP_SECRET=')
        === false  // APP_SECRET key exists
    ) {
        info('.env exists — skipping scaffold (production guard active)');
        info('Delete .env and re-run to reset. THIS WILL LOSE YOUR SETTINGS.');
    } else {
        ok('.env exists');
    }
} elseif (file_exists($examplePath)) {
    copy($examplePath, $envPath);
    ok('.env scaffolded from .env.example — fill in DB_PASS, APP_SECRET, APP_URL');
} else {
    err('.env.example not found — cannot scaffold .env');
    $errors++;
}

// ── logs/ directory ───────────────────────────────────────────────────────────

$logsDir = $projectRoot . '/logs';
if (!is_dir($logsDir)) {
    mkdir($logsDir, 0750, true);
}
if (is_writable($logsDir)) {
    ok('logs/ is writable');
} else {
    err('logs/ is not writable — run: chmod 750 logs/');
    $errors++;
}

// ── public/uploads/ ───────────────────────────────────────────────────────────

$uploadsDir = $projectRoot . '/public/uploads';
if (!is_dir($uploadsDir)) {
    mkdir($uploadsDir, 0750, true);
}
if (is_writable($uploadsDir)) {
    ok('public/uploads/ is writable');
} else {
    err('public/uploads/ is not writable');
    $errors++;
}

// ── Summary ───────────────────────────────────────────────────────────────────

echo "\n";
if ($errors === 0) {
    ok("All checks passed. Next steps:");
    info("  1. Edit .env with your DB credentials and APP_URL");
    info("  2. php bin/migrate.php");
    info("  3. php bin/seed.php demo    (optional demo data)");
    info("  4. php -S localhost:8080 -t public/");
} else {
    err("{$errors} check(s) failed. Fix the issues above and re-run.");
    exit(1);
}
