<?php

declare(strict_types=1);

namespace EduQR\Controllers\Admin;

use EduQR\Controllers\HtmlController;

/**
 * The audit log viewer (T-1112, NFR-81).
 *
 * The page only. It holds no data of its own: the table is filled by
 * {@see \EduQR\Controllers\Api\AuditLogController} over JSON, and all this
 * renders is the shell around it and the filter the shell starts on.
 *
 * The one admin page that is not merely signed-in-only. `requireRole('admin')`
 * is the same call the template made, moved above it.
 *
 * @requirement NFR-81
 */
final class AuditLogController extends HtmlController
{
    /** The actor types the log can be filtered by; anything else means no filter. */
    private const ACTOR_TYPES = ['instructor', 'admin', 'system'];

    // ── GET /admin/audit-logs ─────────────────────────────────────────────────

    public function index(): void
    {
        $this->requireUser('admin');

        $this->render(
            'admin/audit-logs.php',
            ['filterActor' => $this->requestedActorType()],
            self::titleWithAppName(t('audit.title')),
            self::LAYOUT_ADMIN,
        );
    }

    /**
     * The `actor_type` query parameter, narrowed to the values that mean
     * something. Whitelisting rather than escaping is what makes it safe to
     * compare against option values in the template.
     */
    private function requestedActorType(): string
    {
        $requested = $_GET['actor_type'] ?? '';

        return \in_array($requested, self::ACTOR_TYPES, true) ? (string) $requested : '';
    }
}
