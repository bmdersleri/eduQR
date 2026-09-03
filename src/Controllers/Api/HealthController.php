<?php

declare(strict_types=1);

namespace EduQR\Controllers\Api;

use EduQR\Controllers\ApiController;
use EduQR\Support\Database;

final class HealthController extends ApiController
{
    /**
     * Not the `/api/v1/` envelope: the body is the probe document a load
     * balancer reads, and it carries a `Cache-Control` header the envelope has
     * no place for. Emitted here rather than through {@see self::json()}.
     */
    public function check(): never
    {
        $checks = $this->runChecks();
        $status = self::aggregateStatus($checks);

        http_response_code($status === 'ok' ? 200 : 503);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, no-store');
        echo json_encode([
            'status' => $status,
            'checks' => $checks,
            'php' => PHP_VERSION,
            'timestamp' => gmdate('c'),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }

    public static function buildStatus(): array
    {
        $checks = (new self())->runChecks();

        return ['status' => self::aggregateStatus($checks), 'checks' => $checks];
    }

    public static function aggregateStatus(array $checks): string
    {
        return in_array('error', $checks, true) ? 'degraded' : 'ok';
    }

    private function runChecks(): array
    {
        $checks = [];

        $checks['php_version'] = PHP_VERSION_ID >= 80200 ? 'ok' : 'error';

        try {
            Database::connection()->query('SELECT 1');
            $checks['database'] = 'ok';
        } catch (\Throwable) {
            $checks['database'] = 'error';
        }

        return $checks;
    }
}
