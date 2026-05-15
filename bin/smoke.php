#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * bin/smoke.php — Smoke-test every public GET endpoint (T-1011).
 *
 * Sends HTTP GET requests using file_get_contents() + stream context and
 * verifies each returns the expected status code.
 *
 * Auth-gated endpoints are expected to return 401 (JSON) or 302 (HTML redirect
 * to /login). Either is treated as a pass for smoke purposes.
 *
 * Usage:
 *   php bin/smoke.php [--url=https://example.org] [--verbose]
 *
 * Exit codes:
 *   0 — all checks passed
 *   1 — one or more checks failed
 *   2 — configuration error
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this script from the command line.\n");
    exit(2);
}

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
\EduQR\Config::load($root . '/.env');

$base    = rtrim(\EduQR\Config::get('APP_URL', 'http://localhost'), '/');
$verbose = in_array('--verbose', $argv, true);

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--url=')) {
        $base = rtrim(substr($arg, 6), '/');
    }
}

if (!$base) {
    fwrite(STDERR, "Error: APP_URL not configured. Pass --url=https://example.org\n");
    exit(2);
}

// ─────────────────────────────────────────────────────────────────────────────
// Endpoint list: [path, expected HTTP status]
//   401 / 302 are both accepted for auth-gated routes.
//   Use the exact status your production server returns for each.
// ─────────────────────────────────────────────────────────────────────────────
$checks = [
    // Public pages
    ['/', 200],
    ['/login', 200],

    // Student flow (no active session — just check page loads)
    ['/join/XXXXXX', 404],          // non-existent code → 404 or the join page (200)
    ['/play/XXXXXX', 200],          // play page always renders (JS handles session state)

    // API: public
    ['/api/v1/locales', 200],

    // API: auth-gated (expect 401 JSON or 302 redirect)
    ['/api/v1/auth/me', 401],
    ['/api/v1/courses', 401],
];

// ─────────────────────────────────────────────────────────────────────────────
// Execute checks
// ─────────────────────────────────────────────────────────────────────────────
$failed = 0;
$ctx = stream_context_create([
    'http' => [
        'method'          => 'GET',
        'follow_location' => 0,          // Do NOT follow redirects — we check status directly
        'timeout'         => 10,
        'ignore_errors'   => true,       // Don't throw on 4xx/5xx
        'header'          => "Accept: application/json, text/html\r\n",
    ],
    'ssl'  => [
        'verify_peer'      => false,     // Local / staging: allow self-signed certs
        'verify_peer_name' => false,
    ],
]);

foreach ($checks as [$path, $expected]) {
    $url     = $base . $path;
    $body    = @file_get_contents($url, false, $ctx);
    $headers = $http_response_header ?? [];
    $status  = 0;

    foreach ($headers as $h) {
        if (preg_match('#HTTP/\S+ (\d+)#', $h, $m)) {
            $status = (int) $m[1];
        }
    }

    // Auth-gated routes: accept 401 JSON or 302 redirect
    $ok = match (true) {
        $expected === 401 => in_array($status, [401, 302, 403], true),
        default           => $status === $expected,
    };

    if (!$ok) {
        $failed++;
    }

    $icon = $ok ? 'PASS' : 'FAIL';

    if ($verbose || !$ok) {
        printf("[%s] %-50s  expected=%d  got=%d\n", $icon, $path, $expected, $status);
    } else {
        echo '.';
    }
}

echo "\n";

if ($failed === 0) {
    echo "All " . count($checks) . " smoke checks passed.\n";
    exit(0);
} else {
    echo "{$failed} check(s) failed.\n";
    exit(1);
}
