<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use EduQR\Repositories\UserRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * NFR-87: `findById()` is the lookup a live session revalidates against.
 * SQLite stands in for MySQL — the query under test is portable.
 */
final class UserRepositoryTest extends TestCase
{
    private PDO $pdo;
    private UserRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->pdo->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL,
            password_hash TEXT NOT NULL,
            display_name TEXT NOT NULL,
            role TEXT NOT NULL,
            preferred_language TEXT NOT NULL DEFAULT \'en\',
            is_active INTEGER NOT NULL DEFAULT 1
        )');

        $this->pdo->exec("INSERT INTO users (id, email, password_hash, display_name, role, preferred_language, is_active) VALUES
            (1, 'active@example.edu', 'hash1', 'Active Instructor', 'instructor', 'en', 1),
            (2, 'inactive@example.edu', 'hash2', 'Inactive Instructor', 'instructor', 'tr', 0)");

        $this->repo = new UserRepository($this->pdo);
    }

    public function test_find_by_id_returns_the_row_for_an_existing_id_NFR87(): void
    {
        $row = $this->repo->findById(1);

        $this->assertNotNull($row);
        $this->assertSame('active@example.edu', $row['email']);
        $this->assertSame('instructor', $row['role']);
        $this->assertSame('Active Instructor', $row['display_name']);
        $this->assertSame(1, (int) $row['is_active']);
    }

    public function test_find_by_id_on_an_inactive_user_still_returns_the_row_NFR87(): void
    {
        $row = $this->repo->findById(2);

        $this->assertNotNull($row);
        $this->assertArrayHasKey('is_active', $row);
        $this->assertSame(0, (int) $row['is_active']);
        $this->assertFalse((bool) $row['is_active']);
    }

    public function test_find_by_id_returns_null_for_an_unknown_id_NFR87(): void
    {
        $this->assertNull($this->repo->findById(999));
    }
}
