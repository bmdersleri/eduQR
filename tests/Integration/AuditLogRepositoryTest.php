<?php

declare(strict_types=1);

namespace EduQR\Tests\Integration;

use EduQR\Repositories\AuditLogRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for AuditLogRepository (T-1010).
 *
 * Uses an in-memory SQLite database to verify INSERT behaviour without
 * requiring a live MySQL/MariaDB instance.
 *
 * SQLite does not support the ENUM type, so actor_type is declared as TEXT.
 * The repository itself is pure PDO and portable across both engines.
 */
class AuditLogRepositoryTest extends TestCase
{
    private PDO $pdo;
    private AuditLogRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->pdo->exec('
            CREATE TABLE audit_logs (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                actor_type    TEXT    NOT NULL,
                actor_id      INTEGER NULL,
                action        TEXT    NOT NULL,
                entity_type   TEXT    NULL,
                entity_id     INTEGER NULL,
                metadata_json TEXT    NULL,
                created_at    TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');

        $this->repo = new AuditLogRepository($this->pdo);
    }

    // ── write() ───────────────────────────────────────────────────────────────

    public function testWriteInsertsOneRow(): void
    {
        $this->repo->write('instructor', 42, 'user.login', 'user', 42);

        $rows = $this->pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
        $this->assertSame('1', (string) $rows);
    }

    public function testWriteStoresCorrectFields(): void
    {
        $this->repo->write('instructor', 7, 'session.create', 'session', 99);

        $row = $this->pdo->query('SELECT * FROM audit_logs')->fetch();
        $this->assertSame('instructor', $row['actor_type']);
        $this->assertSame('7', (string) $row['actor_id']);
        $this->assertSame('session.create', $row['action']);
        $this->assertSame('session', $row['entity_type']);
        $this->assertSame('99', (string) $row['entity_id']);
        $this->assertNull($row['metadata_json']);
    }

    public function testWriteWithMetadataSerializesToJson(): void
    {
        $this->repo->write('system', null, 'cleanup.run', null, null, ['rows' => 3]);

        $json = $this->pdo->query('SELECT metadata_json FROM audit_logs')->fetchColumn();
        $this->assertSame('{"rows":3}', $json);
    }

    public function testWriteNullActorIdForSystemAction(): void
    {
        $this->repo->write('system', null, 'session.auto_close', 'session', 5);

        $actorId = $this->pdo->query('SELECT actor_id FROM audit_logs')->fetchColumn();
        $this->assertNull($actorId);
    }

    public function testWriteMinimalCall(): void
    {
        // Only required: actorType + action
        $this->repo->write('system', null, 'cleanup.run');

        $row = $this->pdo->query('SELECT * FROM audit_logs')->fetch();
        $this->assertSame('cleanup.run', $row['action']);
        $this->assertNull($row['entity_type']);
        $this->assertNull($row['entity_id']);
        $this->assertNull($row['metadata_json']);
    }

    public function testWriteMultipleRowsAccumulate(): void
    {
        $this->repo->write('instructor', 1, 'course.create', 'course', 10);
        $this->repo->write('instructor', 1, 'session.create', 'session', 20);
        $this->repo->write('instructor', 1, 'question.activate', 'question', 30);

        $count = $this->pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
        $this->assertSame('3', (string) $count);
    }

    // ── list() ────────────────────────────────────────────────────────────────

    public function testListReturnsAllRowsWhenNoFilter(): void
    {
        $this->repo->write('instructor', 1, 'course.create', 'course', 10);
        $this->repo->write('admin', 2, 'user.login', 'user', 2);
        $this->repo->write('system', null, 'cleanup.run');

        $rows = $this->repo->list(50, 0);

        $this->assertCount(3, $rows);
    }

    public function testListFiltersByActorType(): void
    {
        $this->repo->write('instructor', 1, 'course.create');
        $this->repo->write('admin', 2, 'user.login');
        $this->repo->write('system', null, 'cleanup.run');

        $rows = $this->repo->list(50, 0, 'instructor');

        $this->assertCount(1, $rows);
        $this->assertSame('instructor', $rows[0]['actor_type']);
    }

    public function testListRespectsLimitAndOffset(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->repo->write('system', null, 'cleanup.run');
        }

        $page1 = $this->repo->list(3, 0);
        $page2 = $this->repo->list(3, 3);

        $this->assertCount(3, $page1);
        $this->assertCount(2, $page2);
    }

    public function testListRowsContainExpectedFields(): void
    {
        $this->repo->write('instructor', 7, 'session.close', 'session', 42, ['reason' => 'manual']);

        $row = $this->repo->list(1, 0)[0];

        $this->assertArrayHasKey('id', $row);
        $this->assertArrayHasKey('actor_type', $row);
        $this->assertArrayHasKey('actor_id', $row);
        $this->assertArrayHasKey('action', $row);
        $this->assertArrayHasKey('entity_type', $row);
        $this->assertArrayHasKey('entity_id', $row);
        $this->assertArrayHasKey('metadata_json', $row);
        $this->assertArrayHasKey('created_at', $row);
    }

    // ── count() ───────────────────────────────────────────────────────────────

    public function testCountReturnsZeroWhenEmpty(): void
    {
        $this->assertSame(0, $this->repo->count());
    }

    public function testCountReturnsAllRows(): void
    {
        $this->repo->write('instructor', 1, 'session.create');
        $this->repo->write('admin', 2, 'user.login');

        $this->assertSame(2, $this->repo->count());
    }

    public function testCountFiltersByActorType(): void
    {
        $this->repo->write('instructor', 1, 'session.create');
        $this->repo->write('admin', 2, 'user.login');
        $this->repo->write('system', null, 'cleanup.run');

        $this->assertSame(1, $this->repo->count('admin'));
    }
}
