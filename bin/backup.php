#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * bin/backup.php — Nightly mysqldump backup script (T-1014, SEC §17).
 *
 * Creates a gzip-compressed SQL dump of the eduQR database.
 * Backup files are written to BACKUP_DIR (configured in .env) which MUST be
 * outside the web document root. Backups older than --keep-days are pruned.
 *
 * Usage:
 *   php bin/backup.php [--keep-days=30] [--dry-run]
 *
 * Cron example (2 AM daily):
 *   0 2 * * * /usr/bin/php /home/user/eduqr/bin/backup.php >> /home/user/logs/backup.log 2>&1
 *
 * .env keys:
 *   BACKUP_DIR  — absolute path for backup files (default: project/../backups)
 *   DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS — standard DB credentials
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this script from the command line.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
\EduQR\Config::load($root . '/.env');

$dryRun = in_array('--dry-run', $argv, true);
$keepDays = 30;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--keep-days=')) {
        $keepDays = max(1, (int) substr($arg, 12));
    }
}

$host = \EduQR\Config::get('DB_HOST', 'localhost');
$port = \EduQR\Config::get('DB_PORT', '3306');
$dbName = \EduQR\Config::get('DB_NAME', 'eduqr');
$dbUser = \EduQR\Config::get('DB_USER', '');
$dbPass = \EduQR\Config::get('DB_PASS', '');
$backDir = \EduQR\Config::get('BACKUP_DIR', realpath($root . '/..') . '/backups');

// ── Ensure backup directory exists ────────────────────────────────────────────
if (! is_dir($backDir)) {
    if ($dryRun) {
        echo "[backup] Would create: {$backDir}\n";
    } else {
        if (! mkdir($backDir, 0700, true)) {
            fwrite(STDERR, "[backup] Cannot create backup directory: {$backDir}\n");
            exit(1);
        }
        echo "[backup] Created backup directory: {$backDir}\n";
    }
}

// ── Build filename ────────────────────────────────────────────────────────────
$stamp = date('Y-m-d_His');
$filename = "{$backDir}/eduqr_{$stamp}.sql.gz";

echo "[backup] Database  : {$dbName}@{$host}:{$port}\n";
echo "[backup] Backup to : {$filename}\n";
echo "[backup] Keep days : {$keepDays}\n";

if ($dryRun) {
    echo "[backup] Dry-run — no backup file written.\n";
} else {
    // Build mysqldump command
    // Use --password= form to avoid password appearing in process list
    $passOption = $dbPass !== ''
        ? '--password=' . escapeshellarg($dbPass)
        : '--password=';  // empty password still needs the flag on some hosts

    $cmd = sprintf(
        'mysqldump --host=%s --port=%s --user=%s %s'
        . ' --single-transaction --quick --routines --triggers'
        . ' %s | gzip > %s 2>&1',
        escapeshellarg($host),
        escapeshellarg($port),
        escapeshellarg($dbUser),
        $passOption,
        escapeshellarg($dbName),
        escapeshellarg($filename),
    );

    $exitCode = 0;
    passthru($cmd, $exitCode);

    if ($exitCode !== 0) {
        fwrite(STDERR, "[backup] mysqldump failed with exit code {$exitCode}\n");
        exit(1);
    }

    $size = file_exists($filename) ? round(filesize($filename) / 1024, 1) : 0;
    echo "[backup] Written: {$filename} ({$size} KiB)\n";
}

// ── Rotate old backups ─────────────────────────────────────────────────────────
$cutoff = time() - $keepDays * 86400;
$found = glob("{$backDir}/eduqr_*.sql.gz") ?: [];
$pruned = 0;

foreach ($found as $file) {
    if (filemtime($file) < $cutoff) {
        if ($dryRun) {
            echo "[backup] Would prune: {$file}\n";
        } else {
            unlink($file);
            echo "[backup] Pruned: {$file}\n";
        }
        $pruned++;
    }
}

if ($pruned === 0) {
    echo "[backup] Rotation: no old backups to prune.\n";
} else {
    echo "[backup] Rotation: {$pruned} backup(s) pruned.\n";
}

exit(0);
