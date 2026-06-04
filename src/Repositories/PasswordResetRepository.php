<?php

declare(strict_types=1);

namespace EduQR\Repositories;

use EduQR\Contracts\PasswordResetRepositoryInterface;
use EduQR\Support\Database;
use PDO;

final class PasswordResetRepository implements PasswordResetRepositoryInterface
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    public function deleteForUser(int $userId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM password_resets WHERE user_id = ?');
        $stmt->execute([$userId]);
    }

    public function create(int $userId, string $email, string $tokenHash, string $expiresAt): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO password_resets (user_id, email, token_hash, expires_at)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $email, $tokenHash, $expiresAt]);
    }

    public function findByTokenHash(string $tokenHash): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, email, token_hash, expires_at, used_at, created_at
               FROM password_resets
              WHERE token_hash = ?
              LIMIT 1'
        );
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function markUsed(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE password_resets SET used_at = UTC_TIMESTAMP() WHERE id = ?'
        );
        $stmt->execute([$id]);
    }
}
