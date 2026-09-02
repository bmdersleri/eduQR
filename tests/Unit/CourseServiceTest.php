<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit;

use EduQR\Contracts\CourseRepositoryInterface;
use EduQR\Contracts\UserRepositoryInterface;
use EduQR\Services\CourseService;
use PHPUnit\Framework\TestCase;

class CourseServiceTest extends TestCase
{
    // ── Stub factories ─────────────────────────────────────────────────────────

    /**
     * @param array<int,array<int,string>> $access course id => [user id => role] (FR-97).
     *                                             Owner rows are derived from
     *                                             instructor_id automatically.
     */
    private function makeRepo(array $store = [], array $access = []): CourseRepositoryInterface
    {
        return new class ($store, $access) implements CourseRepositoryInterface {
            public array $created = [];
            public array $updated = [];
            public array $archived = [];
            public array $restored = [];
            public array $addedInstructors = [];
            public array $removedInstructors = [];

            private array $rows;
            private int   $nextId;

            public function __construct(array $rows, private array $access = [])
            {
                $this->rows = $rows;
                $this->nextId = $rows ? (max(array_column($rows, 'id')) + 1) : 1;

                // Mirror the migration backfill: every course has one owner row.
                foreach ($rows as $row) {
                    $this->access[(int) $row['id']][(int) $row['instructor_id']] = 'owner';
                }
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
                $filtered = array_values(array_filter(
                    $this->rows,
                    fn ($r) => $this->roleFor((int) $r['id'], $instructorId) !== null
                ));

                return array_slice($filtered, ($page - 1) * $perPage, $perPage);
            }

            public function countByInstructor(int $instructorId): int
            {
                return count(array_filter(
                    $this->rows,
                    fn ($r) => $this->roleFor((int) $r['id'], $instructorId) !== null
                ));
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
                // The real repository writes this row in the same transaction.
                $this->access[$id][$instructorId] = 'owner';

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

            public function restore(int $id): void
            {
                $this->restored[] = $id;
            }

            public function roleFor(int $courseId, int $userId): ?string
            {
                return $this->access[$courseId][$userId] ?? null;
            }

            public function listInstructors(int $courseId): array
            {
                $out = [];
                foreach ($this->access[$courseId] ?? [] as $userId => $role) {
                    $out[] = [
                        'user_id' => $userId,
                        'email' => 'user' . $userId . '@example.org',
                        'display_name' => 'User ' . $userId,
                        'role' => $role,
                        'created_at' => '2026-05-14 00:00:00',
                    ];
                }
                usort($out, static fn ($a, $b) => ($b['role'] === 'owner' ? 1 : 0) <=> ($a['role'] === 'owner' ? 1 : 0));

                return $out;
            }

            public function addInstructor(int $courseId, int $userId, string $role): void
            {
                $this->addedInstructors[] = ['course_id' => $courseId, 'user_id' => $userId, 'role' => $role];
                $this->access[$courseId][$userId] = $role;
            }

            public function removeInstructor(int $courseId, int $userId): bool
            {
                if (! isset($this->access[$courseId][$userId])) {
                    return false;
                }
                $this->removedInstructors[] = ['course_id' => $courseId, 'user_id' => $userId];
                unset($this->access[$courseId][$userId]);

                return true;
            }
        };
    }

    /**
     * @param array<string,int>                $usersByEmail email => user id
     * @param array<string,array<string,mixed>> $overrides    email => extra user columns
     */
    private function makeUsers(array $usersByEmail = [], array $overrides = []): UserRepositoryInterface
    {
        return new class ($usersByEmail, $overrides) implements UserRepositoryInterface {
            public function __construct(private array $usersByEmail, private array $overrides = [])
            {
            }

            public function findByEmail(string $email): ?array
            {
                $email = mb_strtolower($email);
                $id = $this->usersByEmail[$email] ?? null;

                return $id === null ? null : array_merge([
                    'id' => $id,
                    'email' => $email,
                    'display_name' => 'User ' . $id,
                    'role' => 'instructor',
                    'is_active' => 1,
                ], $this->overrides[$email] ?? []);
            }

            public function create(string $email, string $passwordHash, string $displayName, string $role, string $preferredLanguage): int
            {
                return 0;
            }

            public function touchLastLogin(int $id): void
            {
            }

            public function updatePassword(int $id, string $passwordHash): void
            {
            }
        };
    }

    private function makeService(
        CourseRepositoryInterface $repo,
        ?UserRepositoryInterface $users = null,
    ): CourseService {
        return new CourseService($repo, $users ?? $this->makeUsers());
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
        $service = $this->makeService($repo);
        $id = $service->createCourse(10, ['title' => 'New Course', 'default_language' => 'en']);
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
    }

    public function testCreateCourseDefaultsLanguageToEn(): void
    {
        $repo = $this->makeRepo();
        $service = $this->makeService($repo);
        $service->createCourse(10, ['title' => 'Course']);
        $this->assertSame('en', $repo->created[0]['defaultLanguage']);
    }

    public function testCreateCourseRequiresTitle(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('title:required');
        $this->makeService($this->makeRepo())->createCourse(10, ['title' => '', 'default_language' => 'en']);
    }

    public function testCreateCourseTitleTooLongIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('title:too_long');
        $this->makeService($this->makeRepo())->createCourse(
            10,
            ['title' => str_repeat('x', 201), 'default_language' => 'en']
        );
    }

    public function testCreateCourseRejectsUnsupportedLanguage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('default_language:invalid');
        $this->makeService($this->makeRepo())->createCourse(
            10,
            ['title' => 'Course', 'default_language' => 'de']
        );
    }

    // ── Get ────────────────────────────────────────────────────────────────────

    public function testGetCourseSucceedsForOwner(): void
    {
        $repo = $this->makeRepo([$this->sample(1, 10)]);
        $service = $this->makeService($repo);
        $result = $service->getCourse(1, 10);
        $this->assertSame('Test Course', $result['title']);
    }

    public function testGetCourseThrowsForbiddenForWrongInstructor(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('forbidden');
        $repo = $this->makeRepo([$this->sample(1, 10)]);
        $this->makeService($repo)->getCourse(1, 99);
    }

    public function testGetCourseThrowsNotFoundForMissingId(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('course_not_found');
        $this->makeService($this->makeRepo([]))->getCourse(999, 10);
    }

    // ── Update ─────────────────────────────────────────────────────────────────

    public function testUpdateCoursePassesTitleToRepository(): void
    {
        $repo = $this->makeRepo([$this->sample(1, 10)]);
        $service = $this->makeService($repo);
        $service->updateCourse(1, 10, ['title' => 'Updated']);
        $this->assertSame('Updated', $repo->updated[0]['fields']['title']);
    }

    public function testUpdateCourseEnforcesOwnership(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('forbidden');
        $repo = $this->makeRepo([$this->sample(1, 10)]);
        $this->makeService($repo)->updateCourse(1, 99, ['title' => 'Hack']);
    }

    // ── Archive ────────────────────────────────────────────────────────────────

    public function testArchiveCourseCallsRepository(): void
    {
        $repo = $this->makeRepo([$this->sample(1, 10)]);
        $service = $this->makeService($repo);
        $service->archiveCourse(1, 10);
        $this->assertContains(1, $repo->archived);
    }

    public function testArchiveCourseEnforcesOwnership(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('forbidden');
        $repo = $this->makeRepo([$this->sample(1, 10)]);
        $this->makeService($repo)->archiveCourse(1, 99);
    }

    // ── Restore ────────────────────────────────────────────────────────────────

    public function testRestoreCourseCallsRepositoryForArchivedCourse(): void
    {
        $course = array_merge($this->sample(1, 10), ['status' => 'archived']);
        $repo = $this->makeRepo([$course]);
        $service = $this->makeService($repo);
        $service->restoreCourse(1, 10);
        $this->assertContains(1, $repo->restored);
    }

    public function testRestoreCourseRejectsNonArchivedCourse(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid_course_state');
        $repo = $this->makeRepo([$this->sample(1, 10)]);
        $this->makeService($repo)->restoreCourse(1, 10);
    }

    public function testRestoreCourseEnforcesOwnership(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('forbidden');
        $course = array_merge($this->sample(1, 10), ['status' => 'archived']);
        $repo = $this->makeRepo([$course]);
        $this->makeService($repo)->restoreCourse(1, 99);
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
        $result = $this->makeService($repo)->listMyCourses(10, 1, 20);
        $this->assertCount(2, $result['data']);
        $this->assertSame(2, $result['meta']['total']);
    }

    // ── Co-instructor access (FR-97) ───────────────────────────────────────────

    /** Owner 10, co-instructor 20, unrelated instructor 99. */
    private function repoWithCoInstructor(int $courseId = 1, string $status = 'active'): CourseRepositoryInterface
    {
        $course = array_merge($this->sample($courseId, 10), ['status' => $status]);

        return $this->makeRepo([$course], [$courseId => [20 => 'co_instructor']]);
    }

    public function testCoInstructorCanReadCourse_FR97(): void
    {
        $service = $this->makeService($this->repoWithCoInstructor());
        $this->assertSame('Test Course', $service->getCourse(1, 20)['title']);
    }

    public function testCoInstructorCanUpdateCourse_FR97(): void
    {
        $repo = $this->repoWithCoInstructor();
        $this->makeService($repo)->updateCourse(1, 20, ['title' => 'Updated by co-instructor']);
        $this->assertSame('Updated by co-instructor', $repo->updated[0]['fields']['title']);
    }

    public function testCoInstructorCannotArchiveCourse_FR97(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('course_owner_only');
        $this->makeService($this->repoWithCoInstructor())->archiveCourse(1, 20);
    }

    public function testCoInstructorCannotRestoreCourse_FR97(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('course_owner_only');
        $this->makeService($this->repoWithCoInstructor(1, 'archived'))->restoreCourse(1, 20);
    }

    public function testCoInstructorCannotAddInstructor_FR97(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('course_owner_only');
        $service = $this->makeService($this->repoWithCoInstructor(), $this->makeUsers(['new@example.org' => 30]));
        $service->addInstructor(1, 20, ['email' => 'new@example.org']);
    }

    public function testCoInstructorCannotRemoveInstructor_FR97(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('course_owner_only');
        $this->makeService($this->repoWithCoInstructor())->removeInstructor(1, 20, 10);
    }

    public function testCoInstructorCanListInstructors_FR97(): void
    {
        $instructors = $this->makeService($this->repoWithCoInstructor())->listInstructors(1, 20);
        $this->assertCount(2, $instructors);
        $this->assertSame('owner', $instructors[0]['role']);
        $this->assertSame(10, $instructors[0]['user_id']);
    }

    // ── Regression: access must not widen to unrelated instructors (FR-14) ─────

    public function testUnrelatedInstructorIsStillForbiddenOnRead_FR97(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('forbidden');
        $this->makeService($this->repoWithCoInstructor())->getCourse(1, 99);
    }

    public function testUnrelatedInstructorIsStillForbiddenOnUpdate_FR97(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('forbidden');
        $this->makeService($this->repoWithCoInstructor())->updateCourse(1, 99, ['title' => 'Hack']);
    }

    public function testUnrelatedInstructorIsStillForbiddenOnArchive_FR97(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('forbidden');
        $this->makeService($this->repoWithCoInstructor())->archiveCourse(1, 99);
    }

    public function testUnrelatedInstructorIsStillForbiddenOnInstructorList_FR97(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('forbidden');
        $this->makeService($this->repoWithCoInstructor())->listInstructors(1, 99);
    }

    public function testUnrelatedInstructorIsStillForbiddenOnAddInstructor_FR97(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('forbidden');
        $service = $this->makeService($this->repoWithCoInstructor(), $this->makeUsers(['new@example.org' => 30]));
        $service->addInstructor(1, 99, ['email' => 'new@example.org']);
    }

    public function testUnrelatedInstructorIsStillForbiddenOnRemoveInstructor_FR97(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('forbidden');
        $this->makeService($this->repoWithCoInstructor())->removeInstructor(1, 99, 20);
    }

    // ── listMyCourses spans owned + co-instructed (FR-97) ─────────────────────

    public function testListMyCoursesIncludesCoInstructedCourses_FR97(): void
    {
        $store = [
            $this->sample(1, 10),   // owned by 10
            $this->sample(2, 99),   // owned by 99, co-instructed by 10
            $this->sample(3, 99),   // owned by 99, no access for 10
        ];
        $repo = $this->makeRepo($store, [2 => [10 => 'co_instructor']]);

        $result = $this->makeService($repo)->listMyCourses(10, 1, 20);

        $this->assertSame(2, $result['meta']['total']);
        $this->assertSame([1, 2], array_map(static fn ($r) => (int) $r['id'], $result['data']));
    }

    public function testListMyCoursesExcludesOtherPeoplesCourses_FR97(): void
    {
        $store = [$this->sample(1, 10), $this->sample(2, 99)];
        $repo = $this->makeRepo($store, [2 => [77 => 'co_instructor']]);

        $result = $this->makeService($repo)->listMyCourses(10, 1, 20);

        $this->assertSame(1, $result['meta']['total']);
        $this->assertSame(1, (int) $result['data'][0]['id']);
    }

    // ── Creating a course grants the owner row (FR-97) ────────────────────────

    public function testCreateCourseGrantsOwnerAccess_FR97(): void
    {
        $repo = $this->makeRepo();
        $id = $this->makeService($repo)->createCourse(10, ['title' => 'New Course']);
        $this->assertSame('owner', $repo->roleFor($id, 10));
    }

    // ── Add instructor (FR-97) ────────────────────────────────────────────────

    public function testAddInstructorGrantsCoInstructorRole_FR97(): void
    {
        $repo = $this->makeRepo([$this->sample(1, 10)]);
        $service = $this->makeService($repo, $this->makeUsers(['co@example.org' => 20]));

        $result = $service->addInstructor(1, 10, ['email' => 'co@example.org']);

        $this->assertSame(['user_id' => 20, 'role' => 'co_instructor'], $result);
        $this->assertSame('co_instructor', $repo->roleFor(1, 20));
        $this->assertSame(20, $repo->addedInstructors[0]['user_id']);
    }

    public function testAddInstructorRejectsUnknownEmail_FR97(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('instructor_not_found');
        $repo = $this->makeRepo([$this->sample(1, 10)]);
        $this->makeService($repo)->addInstructor(1, 10, ['email' => 'nobody@example.org']);
    }

    public function testAddInstructorRejectsDuplicate_FR97(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already_course_instructor');
        $repo = $this->repoWithCoInstructor();
        $service = $this->makeService($repo, $this->makeUsers(['co@example.org' => 20]));
        $service->addInstructor(1, 10, ['email' => 'co@example.org']);
    }

    public function testAddInstructorRejectsTheOwner_FR97(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already_course_instructor');
        $repo = $this->makeRepo([$this->sample(1, 10)]);
        $service = $this->makeService($repo, $this->makeUsers(['owner@example.org' => 10]));
        $service->addInstructor(1, 10, ['email' => 'owner@example.org']);
    }

    public function testAddInstructorRejectsNonInstructorAccount_FR97(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('instructor_not_found');
        $repo = $this->makeRepo([$this->sample(1, 10)]);
        $users = $this->makeUsers(['admin@example.org' => 20], ['admin@example.org' => ['role' => 'admin']]);
        $this->makeService($repo, $users)->addInstructor(1, 10, ['email' => 'admin@example.org']);
    }

    public function testAddInstructorRejectsDeactivatedAccount_FR97(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('instructor_not_found');
        $repo = $this->makeRepo([$this->sample(1, 10)]);
        $users = $this->makeUsers(['gone@example.org' => 20], ['gone@example.org' => ['is_active' => 0]]);
        $this->makeService($repo, $users)->addInstructor(1, 10, ['email' => 'gone@example.org']);
    }

    public function testAddInstructorRequiresEmail_FR97(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('email:required');
        $repo = $this->makeRepo([$this->sample(1, 10)]);
        $this->makeService($repo)->addInstructor(1, 10, []);
    }

    public function testAddInstructorRejectsMalformedEmail_FR97(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('email:invalid');
        $repo = $this->makeRepo([$this->sample(1, 10)]);
        $this->makeService($repo)->addInstructor(1, 10, ['email' => 'not-an-email']);
    }

    public function testAddInstructorRejectsMissingCourse_FR97(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('course_not_found');
        $service = $this->makeService($this->makeRepo([]), $this->makeUsers(['co@example.org' => 20]));
        $service->addInstructor(999, 10, ['email' => 'co@example.org']);
    }

    // ── Remove instructor (FR-97) ─────────────────────────────────────────────

    public function testRemoveInstructorRevokesCoInstructorAccess_FR97(): void
    {
        $repo = $this->repoWithCoInstructor();
        $this->makeService($repo)->removeInstructor(1, 10, 20);

        $this->assertNull($repo->roleFor(1, 20));
        $this->assertSame(20, $repo->removedInstructors[0]['user_id']);
    }

    public function testRemoveInstructorRejectsRemovingTheOwner_FR97(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot_remove_course_owner');
        $this->makeService($this->repoWithCoInstructor())->removeInstructor(1, 10, 10);
    }

    public function testRemoveInstructorRejectsUserNotOnCourse_FR97(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('course_instructor_not_found');
        $this->makeService($this->repoWithCoInstructor())->removeInstructor(1, 10, 77);
    }
}
