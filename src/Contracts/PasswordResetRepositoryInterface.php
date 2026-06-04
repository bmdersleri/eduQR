<?php

declare(strict_types=1);

namespace EduQR\Contracts;

interface PasswordResetRepositoryInterface
{
    public function deleteForUser(int $userId): void;

    public function create(int $userId, string $email, string $tokenHash, string $expiresAt): void;

    public function findByTokenHash(string $tokenHash): ?array;

    public function markUsed(int $id): void;
}
