<?php

declare(strict_types=1);

namespace EduQR\Repositories;

use EduQR\Contracts\SessionRepositoryInterface;
use EduQR\Support\Database;
use PDO;

final class SessionRepository implements SessionRepositoryInterface
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.*, c.title AS course_title
             FROM sessions s
             LEFT JOIN courses c ON c.id = s.course_id
             WHERE s.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function findByShortCode(string $code): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.*, c.title AS course_title
             FROM sessions s
             LEFT JOIN courses c ON c.id = s.course_id
             WHERE s.short_code = ? LIMIT 1'
        );
        $stmt->execute([$code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function shortCodeExists(string $code): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM sessions WHERE short_code = ? LIMIT 1');
        $stmt->execute([$code]);

        return $stmt->fetchColumn() !== false;
    }

    public function create(int $courseId, string $title, string $shortCode, string $language, int $isQuiz = 0): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sessions (course_id, title, short_code, language, status, is_quiz, started_at)
             VALUES (?, ?, ?, ?, \'active\', ?, UTC_TIMESTAMP())'
        );
        $stmt->execute([$courseId, $title, $shortCode, $language, $isQuiz]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $fields): void
    {
        $allowed = ['title', 'status', 'show_results_to_students', 'moderation_mode', 'is_quiz', 'exam_mode',
                    'started_at', 'paused_at', 'closed_at', 'delete_requested_at', 'anonymized'];
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
            ->prepare('UPDATE sessions SET ' . implode(', ', $sets) . ' WHERE id = ?')
            ->execute($values);
    }

    public function listByCourse(int $courseId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM sessions WHERE course_id = ? ORDER BY created_at DESC'
        );
        $stmt->execute([$courseId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countParticipants(int $sessionId): int
    {
        try {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM participants WHERE session_id = ?');
            $stmt->execute([$sessionId]);

            return (int) $stmt->fetchColumn();
        } catch (\PDOException) {
            return 0;
        }
    }

    public function anonymize(int $sessionId): void
    {
        // Renumber participants in join order (id ASC) → "Participant 1", "Participant 2", …
        $stmt = $this->pdo->prepare(
            'SELECT id FROM participants WHERE session_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$sessionId]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $upd = $this->pdo->prepare(
            "UPDATE participants SET nickname = ?, nickname_normalized = ?, device_hash = '' WHERE id = ?"
        );
        foreach ($rows as $i => $pid) {
            $label = 'Participant ' . ($i + 1);
            $upd->execute([$label, mb_strtolower($label), $pid]);
        }

        // Mark session as anonymized
        $this->pdo
            ->prepare('UPDATE sessions SET anonymized = 1 WHERE id = ?')
            ->execute([$sessionId]);
    }
}
