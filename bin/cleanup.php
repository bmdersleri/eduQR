#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Cleanup script — close sessions inactive for more than 12 hours.
 *
 * Usage: php bin/cleanup.php [--max-age-hours=12] [--dry-run]
 *
 * Designed to run as a cron job:
 *   0 * * * * php /path/to/eduQR/bin/cleanup.php >> /path/to/logs/cleanup.log 2>&1
 */

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

EduQR\Config::load($root . '/.env');

$dryRun    = in_array('--dry-run', $argv, true);
$maxHours  = 12;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--max-age-hours=')) {
        $maxHours = max(1, (int) substr($arg, 16));
    }
}

try {
    $pdo = EduQR\Support\Database::connection();
} catch (\Throwable $e) {
    fwrite(STDERR, '[cleanup] DB connect failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
    ->modify("-{$maxHours} hours")
    ->format('Y-m-d H:i:s');

// Find sessions that are active/paused and started more than $maxHours ago
$findSql = "
    SELECT id, title, short_code, status, started_at
    FROM   sessions
    WHERE  status IN ('active', 'paused')
      AND  started_at < ?
";
$stmt = $pdo->prepare($findSql);
$stmt->execute([$cutoff]);
$stale = $stmt->fetchAll(\PDO::FETCH_ASSOC);

if (empty($stale)) {
    echo '[cleanup] No stale sessions found.' . PHP_EOL;
    exit(0);
}

echo sprintf('[cleanup] Found %d stale session(s) (older than %dh).%s', count($stale), $maxHours, PHP_EOL);

if ($dryRun) {
    foreach ($stale as $s) {
        echo sprintf('  [dry-run] Would close session id=%d (%s) started_at=%s%s',
            $s['id'], $s['short_code'], $s['started_at'], PHP_EOL);
    }
    exit(0);
}

$closeSql = "
    UPDATE sessions
    SET    status    = 'closed',
           closed_at = UTC_TIMESTAMP()
    WHERE  id        = ?
      AND  status   IN ('active', 'paused')
";
$closeStmt = $pdo->prepare($closeSql);

$closed = 0;
foreach ($stale as $s) {
    $closeStmt->execute([$s['id']]);
    if ($closeStmt->rowCount() > 0) {
        $closed++;
        echo sprintf('  [cleanup] Closed session id=%d (%s)%s',
            $s['id'], $s['short_code'], PHP_EOL);
    }
}

echo sprintf('[cleanup] Done. %d session(s) closed.%s', $closed, PHP_EOL);
exit(0);
