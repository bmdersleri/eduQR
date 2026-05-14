<?php

declare(strict_types=1);

/**
 * Front controller — the only web-accessible PHP entry point.
 *
 * cPanel deployment layout:
 *   public_html/eduqr/index.php  →  this file
 *   home/<user>/eduqr-app/       →  everything outside public/
 *
 * For development (php -S localhost:8080 -t public/), the project root
 * is one directory up from this file.
 */

// Detect project root: one level up from public/
$projectRoot = dirname(__DIR__);

// Support cPanel layout where app files live outside the web root
// If running from public_html/eduqr/, look for app at ../../eduqr-app/
$cpanelRoot = dirname(dirname($projectRoot)) . '/eduqr-app';
if (is_dir($cpanelRoot . '/src')) {
    $projectRoot = $cpanelRoot;
}

require_once $projectRoot . '/src/Bootstrap.php';

\EduQR\Bootstrap::run($projectRoot);
