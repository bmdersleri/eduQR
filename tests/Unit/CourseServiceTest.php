<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit;

use EduQR\Contracts\CourseRepositoryInterface;
use EduQR\Services\CourseService;
use PHPUnit\Framework\TestCase;

class CourseServiceTest extends TestCase
{
    // ── Stub factory ───────────────────────────────────────────────────────────

    private function makeRepo(array $store = []): CourseRepositoryInterface
    {
        return new class ($store) implements CourseRepositoryInterface {
            public array $created = [];
            public array $updated = [];
            public array $archived = [];

            private array $rows;
            private int   $nextId;

            public function __construct(array $rows)
            {
                $this->rows = $rows;
                $this->nextId = $rows ? (max(array_column($rows, 'id')) + 1) : 1;
            }

            public function findById(int $id): ?array
            {
                foreach ($this->rows as $r) {
                    if ((int) $r['id'] === $id) {
                        return $r;
                    }
                }

                return null;
            }

            public function listByInstructor(int $instructorId, int $page, int $perPage): array
            {
                $filtered = array_values(
                    array_filter($this->rows, fn ($r) => (int) $r['instructor_id'] === $instructorId)
                );

                return array_slice($filtered, ($page - 1) * $perPage, $perPage);
            }

            public function countByInstructor(int $instructorId): int
            {
                return count(
                    array_filter($this->rows, fn ($r) => (int) $r['instructor_id'] === $instructorId)
                );
            }

            public function create(int $instructorId, string $title, ?string $code, ?string $semester, ?string $description, string $defaultLanguage): int
            {
                $id = $this->nextId++;
                $this->created[] = compact('instructorId', 'title', 'code', 'semester', 'description', 'defaultLanguage');
                $this->rows[] = [
                    'id' => $id,
                    'instructor_id' => $instructorId,
                    'title' => $title,
                    'code' => $code,
                    'semester' => $semester,
                    'description' => $description,
                    'default_language' => $defaultLanguage,
                    'status' => 'active',
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ];

                return $id;
            }

            public function update(int $id, array $fields): void
            {
                $this->updated[] = ['id' => $id, 'fields' => $fields];
            }

            public function archive(int $id): void
            {
                $this->archived[] = $id;
            }
        };
    }

    private function sample(int $id = 1, int $instructorId = 10): array
    {
        return [
            'id' => $id,
            'instructor_id' => $instructorId,
            'title' => 'Test Course',
            'code' => 'CS101',
            'semester' => '2026-Spring',
            'description' => null,
            'default_language' => 'en',
            'status' => 'active',
            'created_at' => '2026-05-14 00:00:00',
            'updated_at' => '2026-05-14 00:00:00',
        ];
    }

    // ── Create ─────────────────────────────────────────────────────────────────

    public function testCreateCourseReturnsPositiveInt(): void
    {
        $repo = $this->makeRepo();
        $service = new CourseService($repo);
        $id = $service->createCourse(10, ['title' => 'New Course', 'default_language' => 'en']);
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
    }

    public function testCreateCourseDefaultsLanguageToEn(): void
    {
        $repo = $this->makeRepo();
        $service = new CourseService($repo);
        $service->createCourse(10, ['title' => 'Course']);
        $this->assertSame('en', $repo->created[0]['defaultLanguage']);
    }

    public function testCreateCourseRequiresTitle(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('title:required');
        (new CourseService($this->makeRepo()))->createCourse(10, ['title' => '', 'default_language' => 'en']);
    }

    public function testCreateCourseTitleTooLongIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('title:too_long');
        (new CourseService($this->makeRepo()))->createCourse(
            10,
            ['title' => str_repeat('x', 201), 'default_language' => 'en']
        );
    }

    public function testCreateCourseRejectsUnsupportedLanguage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('default_language:invalid');
        (new CourseService($this->makeRepo()))->createCourse(
            10,
            ['title' => 'Course', 'default_language' => 'de']
        );
    }

    // ── Get ────────────────────────────────────────────────────────────────────

    public function testGetCourseSucceedsForOwner(): void
    {
        $repo = $this->makeRepo([$this->sample(1, 10)]);
        $service = new CourseService($repo);
        $result = $service->getCourse(1, 10);
        $this->assertSame('Test Course', $result['title']);
    }

    public function testGetCourseThrowsForbiddenForWrongInstructor(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('forbidden');
        $repo = $this->makeRepo([$this->sample(1, 10)]);
        (new CourseService($repo))->getCourse(1, 99);
    }

    public function testGetCourseThrowsNotFoundForMissingId(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('course_not_found');
        (new CourseService($this->makeRepo([])))->getCourse(999, 10);
    }

    // ── Update ─────────────────────────────────────────────────────────────────

    public function testUpdateCoursePassesTitleToRepository(): void
    {
        $repo = $this->makeRepo([$this->sample(1, 10)]);
        $service = new CourseService($repo);
        $service->updateCourse(1, 10, ['title' => 'Updated']);
        $this->assertSame('Updated', $repo->updated[0]['fields']['title']);
    }

    public function testUpdateCourseEnforcesOwnership(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('forbidden');
        $repo = $this->makeRepo([$this->sample(1, 10)]);
        (new CourseService($repo))->updateCourse(1, 99, ['title' => 'Hack']);
    }

    // ── Archive ────────────────────────────────────────────────────────────────

    public function testArchiveCourseCallsRepository(): void
    {
        $repo = $this->makeRepo([$this->sample(1, 10)]);
        $service = new CourseService($repo);
        $service->archiveCourse(1, 10);
        $this->assertContains(1, $repo->archived);
    }

    public function testArchiveCourseEnforcesOwnership(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('forbidden');
        $repo = $this->makeRepo([$this->sample(1, 10)]);
        (new CourseService($repo))->archiveCourse(1, 99);
    }

    // ── List ───────────────────────────────────────────────────────────────────

    public function testListMyCoursesReturnsOnlyOwnCourses(): void
    {
        $store = [
            $this->sample(1, 10),
            $this->sample(2, 10),
            $this->sample(3, 99),
        ];
        $repo = $this->makeRepo($store);
        $result = (new CourseService($repo))->listMyCourses(10, 1, 20);
        $this->assertCount(2, $result['data']);
        $this->assertSame(2, $result['meta']['total']);
    }
}
