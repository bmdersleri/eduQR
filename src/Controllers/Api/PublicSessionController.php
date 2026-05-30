<?php

declare(strict_types=1);

namespace EduQR\Controllers\Api;

use EduQR\Repositories\CourseRepository;
use EduQR\Repositories\SessionRepository;
use EduQR\Services\SessionService;

final class PublicSessionController
{
    private SessionService $service;

    public function __construct()
    {
        $this->service = new SessionService(new SessionRepository(), new CourseRepository());
    }

    // ── GET /api/v1/public/sessions/{short_code} ──────────────────────────────

    public function resolve(string $code): void
    {
        try {
            $data = $this->service->resolveByShortCode($code);
        } catch (\RuntimeException $e) {
            match ($e->getMessage()) {
                'session_not_found' => $this->error(404, 'session_not_found', t('error.session_not_found')),
                'session_closed' => $this->error(410, 'session_closed', t('error.session_closed')),
                default => $this->error(500, 'server_error', t('error.server_error')),
            };
        }

        $this->json(200, [
            'success' => true,
            'data' => $data,
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function json(int $status, array $payload): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }

    private function error(int $status, string $code, string $message): never
    {
        $this->json($status, [
            'success' => false,
            'error' => ['code' => $code, 'message' => $message],
        ]);
    }
}
