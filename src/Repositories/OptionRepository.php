<?php

declare(strict_types=1);

namespace EduQR\Repositories;

use EduQR\Contracts\OptionRepositoryInterface;
use EduQR\Support\Database;
use PDO;

final class OptionRepository implements OptionRepositoryInterface
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    public function createBulk(int $questionId, array $options): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO options (question_id, option_text, option_value, is_correct, order_no)
             VALUES (?, ?, ?, ?, ?)'
        );

        foreach ($options as $option) {
            $stmt->execute([
                $questionId,
                $option['option_text'],
                $option['option_value'] ?? null,
                $option['is_correct'] ?? 0,
                $option['order_no'] ?? 0,
            ]);
        }
    }

    public function findByQuestion(int $questionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM options WHERE question_id = ? ORDER BY order_no ASC, id ASC'
        );
        $stmt->execute([$questionId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function deleteByQuestion(int $questionId): void
    {
        $this->pdo->prepare('DELETE FROM options WHERE question_id = ?')->execute([$questionId]);
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM options WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }
}
