<?php

declare(strict_types=1);

namespace EduQR\Controllers\Api;

use EduQR\Middleware\AuthMiddleware;
use EduQR\Repositories\AuditLogRepository;

/**
 * Phase 11 — Audit Log Viewer API (T-1112, FR-91)
 *
 * Admin-only endpoint. Returns paginated audit-log entries.
 */
final class AuditLogController
{
    private AuditLogRepository $repo;

    public function __construct()
    {
        $this->repo = new AuditLogRepository();
    }

    // ── GET /api/v1/audit-logs ────────────────────────────────────────────────

    public function index(): void
    {
        AuthMiddleware::requireRole('admin');

        $limit = min(100, max(1, (int) ($_GET['limit'] ?? 50)));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $offset = ($page - 1) * $limit;
        $actorType = in_array($_GET['actor_type'] ?? '', ['instructor', 'admin', 'system'], true)
            ? $_GET['actor_type']
            : null;

        $total = $this->repo->count($actorType);
        $logs = $this->repo->list($limit, $offset, $actorType);

        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'data' => [
                'logs' => $logs,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => $total > 0 ? (int) ceil($total / $limit) : 1,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
}
