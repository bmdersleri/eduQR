<?php

declare(strict_types=1);

namespace EduQR\Repositories;

use EduQR\Contracts\AuditLogRepositoryInterface;
use EduQR\Support\Database;
use PDO;

/**
 * Persists audit-log entries to the audit_logs table (FR-90).
 *
 * Never stores PII. Callers are responsible for ensuring $metadata contains
 * no nicknames, device hashes, IP addresses, or answer text.
 */
final class AuditLogRepository implements AuditLogRepositoryInterface
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    public function write(
        string  $actorType,
        ?int    $actorId,
        string  $action,
        ?string $entityType = null,
        ?int    $entityId   = null,
        ?array  $metadata   = null,
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_logs
                 (actor_type, actor_id, action, entity_type, entity_id, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $actorType,
            $actorId,
            $action,
            $entityType,
            $entityId,
            $metadata !== null ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null,
        ]);
    }
}
