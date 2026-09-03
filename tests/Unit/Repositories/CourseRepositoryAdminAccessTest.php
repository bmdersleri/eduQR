<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use EduQR\Repositories\CourseRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * FR-99: the admin role reaches every course at co-instructor level.
 * SQLite stands in for MySQL — the queries under test are portable.
 */
final class CourseRepositoryAdminAccessTest extends TestCase
{
    private PDO $pdo;
    private CourseRepository $repo;

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
            display_name TEXT NOT NULL,
            role TEXT NOT NULL
        )');
        $this->pdo->exec('CREATE TABLE courses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            instructor_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT \'active\',
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )');
        $this->pdo->exec('CREATE TABLE course_instructors (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            course_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            role TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            UNIQUE (course_id, user_id)
        )');

        // 1 = owner instructor, 2 = unrelated instructor, 3 = admin
        $this->pdo->exec("INSERT INTO users (id, email, display_name, role) VALUES
            (1, 'owner@example.edu', 'Owner', 'instructor'),
            (2, 'other@example.edu', 'Other', 'instructor'),
            (3, 'admin@example.edu', 'Admin', 'admin')");
        $this->pdo->exec("INSERT INTO courses (id, instructor_id, title) VALUES (10, 1, 'Fizik 101')");
        $this->pdo->exec("INSERT INTO course_instructors (course_id, user_id, role) VALUES (10, 1, 'owner')");

        $this->repo = new CourseRepository($this->pdo);
    }

    public function test_admin_reaches_a_course_they_are_not_listed_on_FR99(): void
    {
        $this->assertSame('co_instructor', $this->repo->roleFor(10, 3));
    }

    public function test_an_unrelated_instructor_still_has_no_role_FR99(): void
    {
        $this->assertNull($this->repo->roleFor(10, 2));
    }

    public function test_a_listed_owner_keeps_owner_FR99(): void
    {
        $this->assertSame('owner', $this->repo->roleFor(10, 1));
    }

    public function test_an_admin_who_owns_a_course_is_still_its_owner_FR99(): void
    {
        $this->pdo->exec("INSERT INTO courses (id, instructor_id, title) VALUES (11, 3, 'Kimya 101')");
        $this->pdo->exec("INSERT INTO course_instructors (course_id, user_id, role) VALUES (11, 3, 'owner')");

        $this->assertSame('owner', $this->repo->roleFor(11, 3));
    }

    public function test_an_unknown_user_has_no_role_FR99(): void
    {
        $this->assertNull($this->repo->roleFor(10, 999));
    }
}
