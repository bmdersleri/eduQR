<?php

declare(strict_types=1);

namespace EduQR\Contracts;

interface SessionRepositoryInterface
{
    public function findById(int $id): ?array;

    public function findByShortCode(string $code): ?array;

    public function shortCodeExists(string $code): bool;

    public function create(int $courseId, string $title, string $shortCode, string $language): int;

    public function update(int $id, array $fields): void;

    public function listByCourse(int $courseId): array;

    public function countParticipants(int $sessionId): int;

    /** Strip nicknames + device_hash from all participants; set anonymized = 1. */
    public function anonymize(int $sessionId): void;
}
