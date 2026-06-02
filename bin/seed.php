#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Seed runner. [NFR-53]
 *
 * Usage:
 *   php bin/seed.php demo     Create/refresh the demo instructor account.
 *
 * The demo account is configured entirely via .env (DEMO_EMAIL, DEMO_NAME,
 * DEMO_PASSWORD, DEMO_ROLE, DEMO_LANG) so no credentials live in source.
 *
 * Idempotent: re-running upserts the account (refreshes the password hash).
 */

$projectRoot = dirname(__DIR__);
$isCli = PHP_SAPI === 'cli';

if (! $isCli) {
    http_response_code(403);
    exit('This script must be run from the command line.');
}

$autoload = $projectRoot . '/vendor/autoload.php';
if (! file_exists($autoload)) {
    fwrite(STDERR, "Error: vendor/autoload.php not found. Run: composer install\n");
    exit(2);
}
require_once $autoload;

\EduQR\Config::load($projectRoot . '/.env');

$target = $argv[1] ?? '';
if ($target !== 'demo') {
    fwrite(STDERR, "Usage: php bin/seed.php demo\n");
    exit(1);
}

// ── Read demo credentials from .env ─────────────────────────────────────────────

$email = trim((string) \EduQR\Config::get('DEMO_EMAIL', ''));
$name = trim((string) \EduQR\Config::get('DEMO_NAME', 'Demo Instructor'));
$password = (string) \EduQR\Config::get('DEMO_PASSWORD', '');
$role = strtolower(trim((string) \EduQR\Config::get('DEMO_ROLE', 'instructor')));
$lang = strtolower(trim((string) \EduQR\Config::get('DEMO_LANG', 'en')));

if ($email === '' || $password === '') {
    fwrite(STDERR, "Error: DEMO_EMAIL and DEMO_PASSWORD must be set in .env.\n");
    exit(1);
}
if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Error: DEMO_EMAIL '{$email}' is not a valid email address.\n");
    exit(1);
}
if (! in_array($role, ['instructor', 'admin'], true)) {
    fwrite(STDERR, "Error: DEMO_ROLE must be 'instructor' or 'admin'.\n");
    exit(1);
}
if (! in_array($lang, ['en', 'tr'], true)) {
    fwrite(STDERR, "Error: DEMO_LANG must be 'en' or 'tr'.\n");
    exit(1);
}
if (mb_strlen($password) < 10) {
    fwrite(STDERR, "Error: DEMO_PASSWORD must be at least 10 characters.\n");
    exit(1);
}

// ── Upsert the demo account ─────────────────────────────────────────────────────

$hash = \EduQR\Services\AuthService::hashPassword($password);
$pdo = \EduQR\Support\Database::connection();

$stmt = $pdo->prepare(
    'INSERT INTO users (email, password_hash, display_name, role, preferred_language, is_active)
     VALUES (?, ?, ?, ?, ?, 1)
     ON DUPLICATE KEY UPDATE
        password_hash = VALUES(password_hash),
        display_name  = VALUES(display_name),
        role          = VALUES(role),
        preferred_language = VALUES(preferred_language),
        is_active     = 1'
);
$stmt->execute([$email, $hash, $name, $role, $lang]);

echo "Demo account seeded:\n";
echo "  Email: {$email}\n";
echo "  Role:  {$role}\n";
echo "  (password set from DEMO_PASSWORD in .env)\n";
