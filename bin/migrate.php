#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Migration runner. [NFR-53]
 *
 * Scans database/migrations/*.sql, runs un-applied files in order,
 * records applied filenames in schema_migrations table.
 *
 * Idempotent: safe to run multiple times.
 */

$projectRoot = dirname(__DIR__);
$isCli = PHP_SAPI === 'cli';

if (! $isCli) {
    http_response_code(403);
    exit('This script must be run from the command line.');
}

require_once $projectRoot . '/vendor/autoload.php';

// Optional --env=<path> so a verification run can point the runner at a
// throwaway database without touching the developer's .env [NFR-86].
$envPath = $projectRoot . '/.env';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--env=')) {
        $envPath = substr($arg, 6);

        continue;
    }

    // An unrecognised argument is refused rather than ignored: --env= exists
    // specifically so a verification run cannot touch the developer's real
    // database, and silently falling back to the default on a typo (e.g.
    // --en=) would defeat that.
    fwrite(STDERR, "Unknown argument: {$arg}\n");
    exit(1);
}

if (! file_exists($envPath)) {
    fwrite(STDERR, "Config file not found: {$envPath}\n");
    exit(1);
}

\EduQR\Config::load($envPath);

$pdo = \EduQR\Support\Database::connection();

// Ensure schema_migrations table exists
$pdo->exec('
    CREATE TABLE IF NOT EXISTS schema_migrations (
        filename   VARCHAR(120) PRIMARY KEY,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
');

// Fetch already-applied migrations
$applied = $pdo->query('SELECT filename FROM schema_migrations')->fetchAll(\PDO::FETCH_COLUMN);
$applied = array_flip($applied);

// Find migration files
$dir = $projectRoot . '/database/migrations';
$files = glob($dir . '/*.sql');
sort($files);

if (empty($files)) {
    echo "No migration files found in {$dir}\n";
    exit(0);
}

$ran = 0;
foreach ($files as $file) {
    $filename = basename($file);
    if (isset($applied[$filename])) {
        echo "[skip] {$filename}\n";

        continue;
    }

    $sql = file_get_contents($file);
    if ($sql === false || trim($sql) === '') {
        echo "[warn] {$filename} is empty — skipping\n";

        continue;
    }

    try {
        $pdo->exec($sql);
        $stmt = $pdo->prepare('INSERT INTO schema_migrations (filename) VALUES (?)');
        $stmt->execute([$filename]);
        echo "[done] {$filename}\n";
        $ran++;
    } catch (\PDOException $e) {
        echo "[ERR]  {$filename}: " . $e->getMessage() . "\n";
        exit(1);
    }
}

echo $ran > 0
    ? "\nApplied {$ran} migration(s).\n"
    : "\nAll migrations already applied.\n";
