#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Cleanup script — three housekeeping tasks (T-908):
 *
 *  1. Auto-close sessions inactive for more than $maxHours hours.
 *  2. Hard-delete sessions where delete_requested_at < NOW() - 7 days (FR-71).
 *  3. Auto-anonymize closed sessions older than 365 days (NFR-34).
 *
 * Usage: php bin/cleanup.php [--max-age-hours=12] [--dry-run]
 *
 * Cron example:
 *   0 2 * * * php /path/to/eduQR/bin/cleanup.php >> /path/to/logs/cleanup.log 2>&1
 */

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

EduQR\Config::load($root . '/.env');

$dryRun = in_array('--dry-run', $argv, true);
$maxHours = 12;

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
        echo sprintf(
            '  [dry-run] Would close session id=%d (%s) started_at=%s%s',
            $s['id'],
            $s['short_code'],
            $s['started_at'],
            PHP_EOL
        );
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
        echo sprintf(
            '  [cleanup] Closed session id=%d (%s)%s',
            $s['id'],
            $s['short_code'],
            PHP_EOL
        );
    }
}

echo sprintf('[cleanup] Done. %d session(s) closed.%s', $closed, PHP_EOL);

// ── Task 2: Hard-delete sessions past 7-day grace period (FR-71) ─────────────

$graceCutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
    ->modify('-7 days')
    ->format('Y-m-d H:i:s');

$findDelSql = '
    SELECT id, title, short_code, delete_requested_at
    FROM   sessions
    WHERE  delete_requested_at IS NOT NULL
      AND  delete_requested_at < ?
';
$stmt = $pdo->prepare($findDelSql);
$stmt->execute([$graceCutoff]);
$toDelete = $stmt->fetchAll(\PDO::FETCH_ASSOC);

if (! empty($toDelete)) {
    echo sprintf('[cleanup] Found %d session(s) to hard-delete.%s', count($toDelete), PHP_EOL);
    if ($dryRun) {
        foreach ($toDelete as $s) {
            echo sprintf(
                '  [dry-run] Would delete session id=%d (%s) requested_at=%s%s',
                $s['id'],
                $s['short_code'],
                $s['delete_requested_at'],
                PHP_EOL
            );
        }
    } else {
        $delStmt = $pdo->prepare('DELETE FROM sessions WHERE id = ?');
        $deleted = 0;
        foreach ($toDelete as $s) {
            $delStmt->execute([$s['id']]);
            $deleted++;
            echo sprintf(
                '  [cleanup] Deleted session id=%d (%s)%s',
                $s['id'],
                $s['short_code'],
                PHP_EOL
            );
        }
        echo sprintf('[cleanup] Hard-deleted %d session(s).%s', $deleted, PHP_EOL);
    }
} else {
    echo '[cleanup] No sessions to hard-delete.' . PHP_EOL;
}

// ── Task 3: Auto-anonymize sessions closed more than 365 days ago (NFR-34) ───

$anonCutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
    ->modify('-365 days')
    ->format('Y-m-d H:i:s');

$findAnonSql = "
    SELECT id, title, short_code, closed_at
    FROM   sessions
    WHERE  status    = 'closed'
      AND  anonymized = 0
      AND  closed_at  < ?
      AND  delete_requested_at IS NULL
";
$stmt = $pdo->prepare($findAnonSql);
$stmt->execute([$anonCutoff]);
$toAnon = $stmt->fetchAll(\PDO::FETCH_ASSOC);

if (! empty($toAnon)) {
    echo sprintf('[cleanup] Found %d session(s) to auto-anonymize.%s', count($toAnon), PHP_EOL);
    if ($dryRun) {
        foreach ($toAnon as $s) {
            echo sprintf(
                '  [dry-run] Would anonymize session id=%d (%s) closed_at=%s%s',
                $s['id'],
                $s['short_code'],
                $s['closed_at'],
                PHP_EOL
            );
        }
    } else {
        $repo = new EduQR\Repositories\SessionRepository($pdo);
        $anoned = 0;
        foreach ($toAnon as $s) {
            $repo->anonymize((int) $s['id']);
            $anoned++;
            echo sprintf(
                '  [cleanup] Anonymized session id=%d (%s)%s',
                $s['id'],
                $s['short_code'],
                PHP_EOL
            );
        }
        echo sprintf('[cleanup] Auto-anonymized %d session(s).%s', $anoned, PHP_EOL);
    }
} else {
    echo '[cleanup] No sessions to auto-anonymize.' . PHP_EOL;
}

exit(0);
