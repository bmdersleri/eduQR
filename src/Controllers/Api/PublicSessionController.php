<?php

declare(strict_types=1);

namespace EduQR\Controllers\Api;

use EduQR\Container;
use EduQR\Controllers\ApiController;
use EduQR\Services\SessionService;

final class PublicSessionController extends ApiController
{
    private SessionService $service;

    public function __construct()
    {
        $this->service = Container::sessionService();
    }

    // ── GET /api/v1/public/sessions/{short_code} ──────────────────────────────

    public function resolve(string $code): void
    {
        try {
            $data = $this->service->resolveByShortCode($code);
        } catch (\RuntimeException $e) {
            // Resolving a short code is the 410 side of the session_closed
            // divergence (SYSTEM_ARCHITECTURE.md §9.1).
            $this->handleRuntimeException($e);
        }

        $this->json(200, [
            'success' => true,
            'data' => $data,
        ]);
    }
}
