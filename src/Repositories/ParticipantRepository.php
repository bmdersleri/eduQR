<?php

declare(strict_types=1);

namespace EduQR\Repositories;

use EduQR\Contracts\ParticipantRepositoryInterface;
use EduQR\Database;

final class ParticipantRepository implements ParticipantRepositoryInterface
{
    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::connection();
    }

    public function register(
        int    $sessionId,
        string $nickname,
        string $nicknameNormalized,
        ?string $deviceHash,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO participants (session_id, nickname, nickname_normalized, device_hash)
             VALUES (:session_id, :nickname, :nickname_normalized, :device_hash)'
        );
        $stmt->execute([
            ':session_id'          => $sessionId,
            ':nickname'            => $nickname,
            ':nickname_normalized' => $nicknameNormalized,
            ':device_hash'         => $deviceHash,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function existsByNicknameNormalized(int $sessionId, string $nicknameNormalized): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM participants WHERE session_id = :sid AND nickname_normalized = :nn LIMIT 1'
        );
        $stmt->execute([':sid' => $sessionId, ':nn' => $nicknameNormalized]);
        return (bool) $stmt->fetchColumn();
    }

    public function countBySession(int $sessionId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM participants WHERE session_id = :sid'
        );
        $stmt->execute([':sid' => $sessionId]);
        return (int) $stmt->fetchColumn();
    }

    public function findBySession(int $sessionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM participants WHERE session_id = :sid ORDER BY joined_at ASC'
        );
        $stmt->execute([':sid' => $sessionId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM participants WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
