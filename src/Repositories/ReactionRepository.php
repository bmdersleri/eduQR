<?php

declare(strict_types=1);

namespace EduQR\Repositories;

use EduQR\Contracts\ReactionRepositoryInterface;
use EduQR\Support\Database;
use PDO;

final class ReactionRepository implements ReactionRepositoryInterface
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    public function upsert(
        int    $sessionId,
        int    $questionId,
        int    $participantId,
        string $reaction
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO question_reactions
                (session_id, question_id, participant_id, reaction)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE reaction = VALUES(reaction)'
        );
        $stmt->execute([$sessionId, $questionId, $participantId, $reaction]);
    }

    public function aggregateBySession(int $sessionId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT question_id,
                    SUM(CASE WHEN reaction = 'got_it' THEN 1 ELSE 0 END) AS got_it,
                    SUM(CASE WHEN reaction = 'lost'   THEN 1 ELSE 0 END) AS lost
               FROM question_reactions
              WHERE session_id = ?
              GROUP BY question_id
              ORDER BY question_id ASC"
        );
        $stmt->execute([$sessionId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
