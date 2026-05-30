#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * bin/rotate-secret.php — Rotate APP_SECRET (T-1008, SEC §19).
 *
 * Generates 32 cryptographically-secure random bytes (URL-safe base64) and
 * either prints the new value or updates .env in place.
 *
 * WARNING: Rotating the secret invalidates all existing device hashes.
 * Students currently in a session will not be affected during the session
 * itself (their participant cookie stays valid), but device-hash deduplication
 * across future joins will reset.
 *
 * Usage:
 *   php bin/rotate-secret.php              # print new secret only
 *   php bin/rotate-secret.php --apply      # update .env in place
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$apply = in_array('--apply', $argv, true);
$envFile = dirname(__DIR__) . '/.env';

echo "Generated APP_SECRET: {$secret}\n\n";

if (! $apply) {
    echo "Add this line to your .env file:\n";
    echo "  APP_SECRET={$secret}\n\n";
    echo "Run with --apply to update .env automatically.\n";
    exit(0);
}

if (! file_exists($envFile)) {
    fwrite(STDERR, "Error: .env not found at {$envFile}\n");
    exit(1);
}

$contents = file_get_contents($envFile);
if ($contents === false) {
    fwrite(STDERR, "Error: could not read .env\n");
    exit(1);
}

// Replace existing APP_SECRET line or append if absent
if (preg_match('/^APP_SECRET=.*/m', $contents)) {
    $contents = preg_replace('/^APP_SECRET=.*/m', "APP_SECRET={$secret}", $contents);
} else {
    $contents .= "\nAPP_SECRET={$secret}\n";
}

if (file_put_contents($envFile, $contents) === false) {
    fwrite(STDERR, "Error: could not write .env\n");
    exit(1);
}

echo ".env updated with new APP_SECRET.\n";
echo "Note: Existing device-hash cookies will be invalidated on next join attempt.\n";
exit(0);
