<?php

declare(strict_types=1);

namespace EduQR\Repositories;

use EduQR\Contracts\CourseRepositoryInterface;
use EduQR\Support\Database;
use PDO;

final class CourseRepository implements CourseRepositoryInterface
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM courses WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function listByInstructor(int $instructorId, int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;
        $stmt = $this->pdo->prepare(
            'SELECT * FROM courses WHERE instructor_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?'
        );
        $stmt->execute([$instructorId, $perPage, $offset]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countByInstructor(int $instructorId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM courses WHERE instructor_id = ?');
        $stmt->execute([$instructorId]);

        return (int) $stmt->fetchColumn();
    }

    public function create(
        int     $instructorId,
        string  $title,
        ?string $code,
        ?string $semester,
        ?string $description,
        string  $defaultLanguage
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO courses (instructor_id, title, code, semester, description, default_language)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$instructorId, $title, $code, $semester, $description, $defaultLanguage]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $fields): void
    {
        $allowed = ['title', 'code', 'semester', 'description', 'default_language'];
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
            ->prepare('UPDATE courses SET ' . implode(', ', $sets) . ' WHERE id = ?')
            ->execute($values);
    }

    public function archive(int $id): void
    {
        $this->pdo
            ->prepare("UPDATE courses SET status = 'archived' WHERE id = ?")
            ->execute([$id]);
    }
}
