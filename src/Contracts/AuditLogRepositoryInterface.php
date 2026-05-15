<?php

declare(strict_types=1);

namespace EduQR\Contracts;

/**
 * Contract for recording audited system actions (FR-90).
 *
 * Implementations must never log PII (nicknames, device hashes, IP addresses).
 * Only entity IDs, action names, and safe metadata are permitted.
 */
interface AuditLogRepositoryInterface
{
    /**
     * Write one audit-log entry.
     *
     * @param string      $actorType  'instructor' | 'admin' | 'system'
     * @param int|null    $actorId    User ID; null for system-initiated actions
     * @param string      $action     Dot-namespaced verb, e.g. 'user.login', 'session.close'
     * @param string|null $entityType Affected table name, e.g. 'session', 'question', 'course'
     * @param int|null    $entityId   Primary key of the affected row
     * @param array|null  $metadata   Optional JSON-serializable context (no PII)
     */
    public function write(
        string  $actorType,
        ?int    $actorId,
        string  $action,
        ?string $entityType = null,
        ?int    $entityId   = null,
        ?array  $metadata   = null,
    ): void;

    /**
     * Retrieve audit-log entries ordered by created_at DESC.
     *
     * @return list<array<string,mixed>>
     */
    public function list(int $limit = 50, int $offset = 0, ?string $actorType = null): array;

    /** Total number of rows, optionally filtered by actor type. */
    public function count(?string $actorType = null): int;
}
