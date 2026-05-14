<?php

declare(strict_types=1);

namespace EduQR\Contracts;

interface UserRepositoryInterface
{
    public function findByEmail(string $email): ?array;

    public function create(
        string $email,
        string $passwordHash,
        string $displayName,
        string $role,
        string $preferredLanguage
    ): int;

    public function touchLastLogin(int $id): void;
}
