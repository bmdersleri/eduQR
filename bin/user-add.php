#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * CLI tool: create an instructor or admin account.
 *
 * Usage:
 *   php bin/user-add.php --email=<email> --name=<display_name> [--role=instructor|admin] [--lang=en|tr]
 *
 * The script will prompt for a password interactively.
 * Password requirements: >= 10 chars, must include 3 of: lowercase, uppercase, digit, symbol.
 */

$projectRoot = dirname(__DIR__);
$autoload    = $projectRoot . '/vendor/autoload.php';

if (!file_exists($autoload)) {
    fwrite(STDERR, "Error: vendor/autoload.php not found. Run: composer install\n");
    exit(2);
}
require_once $autoload;

EduQR\Config::load($projectRoot . '/.env');

// ── Parse arguments ────────────────────────────────────────────────────────────

$opts = getopt('', ['email:', 'name:', 'role::', 'lang::']);

$email = trim($opts['email'] ?? '');
$name  = trim($opts['name']  ?? '');
$role  = strtolower(trim($opts['role'] ?? 'instructor'));
$lang  = strtolower(trim($opts['lang'] ?? 'en'));

if ($email === '' || $name === '') {
    fwrite(STDERR, "Usage: php bin/user-add.php --email=<email> --name=<name> [--role=instructor|admin] [--lang=en|tr]\n");
    exit(1);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Error: '{$email}' is not a valid email address.\n");
    exit(1);
}

if (!in_array($role, ['instructor', 'admin'], true)) {
    fwrite(STDERR, "Error: --role must be 'instructor' or 'admin'.\n");
    exit(1);
}

if (!in_array($lang, ['en', 'tr'], true)) {
    fwrite(STDERR, "Error: --lang must be 'en' or 'tr'.\n");
    exit(1);
}

// ── Prompt for password (hidden input) ────────────────────────────────────────

function readPassword(string $prompt): string
{
    if (PHP_OS_FAMILY === 'Windows') {
        echo $prompt;
        $pass = '';
        while (true) {
            $ch = fgetc(STDIN);
            if ($ch === "\n" || $ch === "\r") {
                break;
            }
            $pass .= $ch;
        }
        echo "\n";
    } else {
        $pass = shell_exec('stty -echo; read -r P; stty echo; echo "$P"');
        $pass = trim((string) $pass);
        if ($pass === '') {
            // fallback
            echo $prompt;
            $pass = trim((string) fgets(STDIN));
        }
    }
    return $pass;
}

echo "Creating user: {$email} ({$role})\n";
$password = readPassword('Password: ');
$confirm  = readPassword('Confirm password: ');

if ($password !== $confirm) {
    fwrite(STDERR, "Error: Passwords do not match.\n");
    exit(1);
}

if (mb_strlen($password) < 10) {
    fwrite(STDERR, "Error: Password must be at least 10 characters.\n");
    exit(1);
}

$strength = 0;
if (preg_match('/[a-z]/', $password)) { $strength++; }
if (preg_match('/[A-Z]/', $password)) { $strength++; }
if (preg_match('/[0-9]/', $password)) { $strength++; }
if (preg_match('/[^a-zA-Z0-9]/', $password)) { $strength++; }

if ($strength < 3) {
    fwrite(STDERR, "Error: Password must include at least three of: lowercase, uppercase, digit, symbol.\n");
    exit(1);
}

// ── Insert into database ───────────────────────────────────────────────────────

$hash = EduQR\Services\AuthService::hashPassword($password);
$repo = new EduQR\Repositories\UserRepository();

try {
    $id = $repo->create($email, $hash, $name, $role, $lang);
    echo "Success: user created with ID {$id}.\n";
    echo "  Email:   {$email}\n";
    echo "  Name:    {$name}\n";
    echo "  Role:    {$role}\n";
    echo "  Lang:    {$lang}\n";
} catch (\PDOException $e) {
    if ($e->getCode() === '23000') {
        fwrite(STDERR, "Error: A user with email '{$email}' already exists.\n");
        exit(1);
    }
    fwrite(STDERR, 'Database error: ' . $e->getMessage() . "\n");
    exit(1);
}
