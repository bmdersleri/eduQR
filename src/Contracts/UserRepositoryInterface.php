<?php

declare(strict_types=1);

namespace EduQR\Contracts;

interface UserRepositoryInterface
{
    public function findByEmail(string $email): ?array;

    /** The row behind a live session, `is_active` included (NFR-87). */
    public function findById(int $id): ?array;

    public function create(
        string $email,
        string $passwordHash,
        string $displayName,
        string $role,
        string $preferredLanguage
    ): int;

    public function touchLastLogin(int $id): void;

    public function updatePassword(int $id, string $passwordHash): void;
}
