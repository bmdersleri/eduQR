<?php

declare(strict_types=1);

namespace EduQR\Repositories;

use EduQR\Contracts\LoginAttemptRepositoryInterface;
use EduQR\Support\Database;
use PDO;

final class LoginAttemptRepository implements LoginAttemptRepositoryInterface
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    public function record(string $email, ?string $ipHash, bool $succeeded): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO login_attempts (email, ip_hash, succeeded) VALUES (?, ?, ?)'
        );
        $stmt->execute([$email, $ipHash, $succeeded ? 1 : 0]);
    }

    public function countRecentFailures(string $email, int $windowSeconds): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
              WHERE email     = ?
                AND succeeded = 0
                AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? SECOND)'
        );
        $stmt->execute([$email, $windowSeconds]);

        return (int) $stmt->fetchColumn();
    }
}
