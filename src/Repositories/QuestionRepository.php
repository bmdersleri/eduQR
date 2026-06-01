<?php

declare(strict_types=1);

namespace EduQR\Repositories;

use EduQR\Contracts\QuestionRepositoryInterface;
use EduQR\Support\Database;
use PDO;

final class QuestionRepository implements QuestionRepositoryInterface
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    public function create(
        int    $sessionId,
        string $questionText,
        string $questionType,
        bool   $showResults,
        bool   $allowMultipleAnswers,
        string $stage = 'middle'
    ): int {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(MAX(order_no), 0) + 1 FROM questions WHERE session_id = ?'
        );
        $stmt->execute([$sessionId]);
        $orderNo = (int) $stmt->fetchColumn();

        $stmt = $this->pdo->prepare(
            'INSERT INTO questions
                (session_id, question_text, question_type, show_results, allow_multiple_answers, order_no, stage)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $sessionId,
            $questionText,
            $questionType,
            $showResults ? 1 : 0,
            $allowMultipleAnswers ? 1 : 0,
            $orderNo,
            $stage,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM questions WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function findBySession(int $sessionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM questions WHERE session_id = ? ORDER BY order_no ASC, id ASC'
        );
        $stmt->execute([$sessionId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findActiveBySessionCode(string $shortCode): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT q.* FROM questions q
             JOIN sessions s ON s.id = q.session_id
             WHERE s.short_code = ? AND q.status = 'active'
             LIMIT 1"
        );
        $stmt->execute([$shortCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function update(int $id, array $fields): void
    {
        $allowed = ['question_text', 'image_path', 'show_results', 'allow_multiple_answers'];
        $sets = [];
        $values = [];

        foreach ($allowed as $col) {
            if (array_key_exists($col, $fields)) {
                $sets[] = "{$col} = ?";
                $values[] = $fields[$col];
            }
        }

        if (empty($sets)) {
            return;
        }

        $values[] = $id;
        $this->pdo
            ->prepare('UPDATE questions SET ' . implode(', ', $sets) . ' WHERE id = ?')
            ->execute($values);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM questions WHERE id = ?')->execute([$id]);
    }

    public function activate(int $id, int $sessionId): void
    {
        $this->pdo->beginTransaction();

        try {
            $this->pdo
                ->prepare(
                    "UPDATE questions
                     SET status = 'closed', closed_at = UTC_TIMESTAMP()
                     WHERE session_id = ? AND status = 'active'"
                )
                ->execute([$sessionId]);

            $this->pdo
                ->prepare(
                    "UPDATE questions
                     SET status = 'active', activated_at = UTC_TIMESTAMP()
                     WHERE id = ?"
                )
                ->execute([$id]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }

    public function close(int $id): void
    {
        $this->pdo
            ->prepare(
                "UPDATE questions SET status = 'closed', closed_at = UTC_TIMESTAMP() WHERE id = ?"
            )
            ->execute([$id]);
    }

    public function reorder(int $sessionId, array $orderedIds): void
    {
        if (empty($orderedIds)) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE questions SET order_no = ? WHERE id = ? AND session_id = ?'
        );

        foreach ($orderedIds as $index => $questionId) {
            $stmt->execute([$index + 1, (int) $questionId, $sessionId]);
        }
    }
}
