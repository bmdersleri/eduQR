<?php

declare(strict_types=1);

namespace EduQR\Repositories;

use EduQR\Contracts\QuestionBankRepositoryInterface;
use EduQR\Support\Database;
use PDO;

final class QuestionBankRepository implements QuestionBankRepositoryInterface
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function create(int $courseId, int $userId, string $sourceKind, array $payload, ?string $sourceTitle = null): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO question_bank_items
                (course_id, created_by_user_id, source_kind, source_title, payload_json)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $courseId,
            $userId,
            $sourceKind,
            $sourceTitle,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM question_bank_items WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    public function findByCourse(int $courseId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM question_bank_items WHERE course_id = ? ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute([$courseId]);

        return array_map(fn (array $row) => $this->hydrate($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findByIds(int $courseId, array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            'SELECT * FROM question_bank_items WHERE course_id = ? AND id IN (' . $placeholders . ') ORDER BY created_at ASC, id ASC'
        );
        $stmt->execute(array_merge([$courseId], $ids));

        return array_map(fn (array $row) => $this->hydrate($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function hydrate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'course_id' => (int) $row['course_id'],
            'created_by_user_id' => (int) $row['created_by_user_id'],
            'source_kind' => $row['source_kind'],
            'source_title' => $row['source_title'],
            'question' => json_decode((string) $row['payload_json'], true, 512, JSON_THROW_ON_ERROR),
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }
}
