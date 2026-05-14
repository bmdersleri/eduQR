<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Load test environment — uses .env.testing if present, else .env
$envFile = dirname(__DIR__) . '/.env.testing';
if (!file_exists($envFile)) {
    $envFile = dirname(__DIR__) . '/.env';
}
\EduQR\Config::load($envFile);
