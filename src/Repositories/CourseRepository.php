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

    /** Owned or co-instructed courses (FR-97), newest first. */
    public function listByInstructor(int $instructorId, int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;
        $stmt = $this->pdo->prepare(
            'SELECT c.*
               FROM courses c
               JOIN course_instructors ci ON ci.course_id = c.id
              WHERE ci.user_id = ?
              ORDER BY c.created_at DESC
              LIMIT ? OFFSET ?'
        );
        $stmt->execute([$instructorId, $perPage, $offset]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countByInstructor(int $instructorId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
               FROM courses c
               JOIN course_instructors ci ON ci.course_id = c.id
              WHERE ci.user_id = ?'
        );
        $stmt->execute([$instructorId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Inserts the course and its owner row atomically, so a course can never
     * exist without an owner in course_instructors (FR-97).
     */
    public function create(
        int     $instructorId,
        string  $title,
        ?string $code,
        ?string $semester,
        ?string $description,
        string  $defaultLanguage
    ): int {
        $ownTransaction = ! $this->pdo->inTransaction();

        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO courses (instructor_id, title, code, semester, description, default_language)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$instructorId, $title, $code, $semester, $description, $defaultLanguage]);

            $id = (int) $this->pdo->lastInsertId();

            $this->pdo
                ->prepare("INSERT INTO course_instructors (course_id, user_id, role) VALUES (?, ?, 'owner')")
                ->execute([$id, $instructorId]);

            if ($ownTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        return $id;
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

    public function restore(int $id): void
    {
        $this->pdo
            ->prepare("UPDATE courses SET status = 'active' WHERE id = ?")
            ->execute([$id]);
    }

    // ── Course instructors (FR-97) ─────────────────────────────────────────────

    public function roleFor(int $courseId, int $userId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT role FROM course_instructors WHERE course_id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$courseId, $userId]);
        $role = $stmt->fetchColumn();

        if ($role !== false) {
            return (string) $role;
        }

        // An admin reaches every course at co-instructor level, and never as its
        // owner — archiving and the instructor list stay with the owner. [FR-99]
        return $this->isAdmin($userId) ? 'co_instructor' : null;
    }

    private function isAdmin(int $userId): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM users WHERE id = ? AND role = 'admin' LIMIT 1");
        $stmt->execute([$userId]);

        return $stmt->fetchColumn() !== false;
    }

    public function listInstructors(int $courseId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT ci.user_id, u.email, u.display_name, ci.role, ci.created_at
               FROM course_instructors ci
               JOIN users u ON u.id = ci.user_id
              WHERE ci.course_id = ?
              ORDER BY (ci.role = 'owner') DESC, ci.created_at ASC, ci.id ASC"
        );
        $stmt->execute([$courseId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addInstructor(int $courseId, int $userId, string $role): void
    {
        $this->pdo
            ->prepare('INSERT INTO course_instructors (course_id, user_id, role) VALUES (?, ?, ?)')
            ->execute([$courseId, $userId, $role]);
    }

    public function removeInstructor(int $courseId, int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM course_instructors WHERE course_id = ? AND user_id = ?'
        );
        $stmt->execute([$courseId, $userId]);

        return $stmt->rowCount() > 0;
    }
}
