<?php

declare(strict_types=1);

namespace EduQR\Repositories;

use EduQR\Contracts\UserRepositoryInterface;
use EduQR\Support\Database;
use PDO;

final class UserRepository implements UserRepositoryInterface
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, email, password_hash, display_name, role,
                    preferred_language, is_active, last_login_at
               FROM users
              WHERE email = ?
              LIMIT 1'
        );
        $stmt->execute([$email]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, email, display_name, role,
                    preferred_language, is_active
               FROM users
              WHERE id = ?
              LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function create(
        string $email,
        string $passwordHash,
        string $displayName,
        string $role = 'instructor',
        string $preferredLanguage = 'en'
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (email, password_hash, display_name, role, preferred_language)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$email, $passwordHash, $displayName, $role, $preferredLanguage]);

        return (int) $this->pdo->lastInsertId();
    }

    public function touchLastLogin(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?'
        );
        $stmt->execute([$id]);
    }

    public function updatePassword(int $id, string $passwordHash): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET password_hash = ? WHERE id = ?'
        );
        $stmt->execute([$passwordHash, $id]);
    }
}
