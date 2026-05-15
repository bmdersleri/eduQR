<?php

declare(strict_types=1);

namespace EduQR\Repositories;

use EduQR\Contracts\AnswerRepositoryInterface;
use EduQR\Support\Database;
use PDO;

final class AnswerRepository implements AnswerRepositoryInterface
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    public function insert(
        int     $questionId,
        int     $participantId,
        ?int    $selectedOptionId,
        ?string $answerText
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO answers
                (question_id, participant_id, selected_option_id, answer_text)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$questionId, $participantId, $selectedOptionId, $answerText]);
        return (int) $this->pdo->lastInsertId();
    }

    public function countByQuestion(int $questionId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM answers WHERE question_id = ?'
        );
        $stmt->execute([$questionId]);
        return (int) $stmt->fetchColumn();
    }

    public function fetchByQuestion(int $questionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, participant_id, selected_option_id, answer_text, is_hidden, created_at
               FROM answers
              WHERE question_id = ?
              ORDER BY created_at ASC'
        );
        $stmt->execute([$questionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function existsByParticipantAndQuestion(int $participantId, int $questionId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM answers
              WHERE participant_id = ? AND question_id = ?
              LIMIT 1'
        );
        $stmt->execute([$participantId, $questionId]);
        return $stmt->fetchColumn() !== false;
    }
}
