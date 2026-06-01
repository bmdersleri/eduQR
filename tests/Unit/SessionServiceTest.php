<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit;

use EduQR\Contracts\CourseRepositoryInterface;
use EduQR\Contracts\SessionRepositoryInterface;
use EduQR\Services\SessionService;
use EduQR\Support\ShortCode;
use PHPUnit\Framework\TestCase;

class SessionServiceTest extends TestCase
{
    // ── ShortCode ──────────────────────────────────────────────────────────────

    public function testShortCodeHasCorrectLength(): void
    {
        $this->assertSame(6, strlen(ShortCode::generate()));
    }

    public function testShortCodeUsesOnlyAllowedCharset(): void
    {
        $charset = ShortCode::charset();
        for ($i = 0; $i < 100; $i++) {
            $code = ShortCode::generate();
            for ($j = 0; $j < strlen($code); $j++) {
                $this->assertStringContainsString(
                    $code[$j],
                    $charset,
                    "Character '{$code[$j]}' not in allowed charset"
                );
            }
        }
    }

    public function testShortCodeExcludesAmbiguousChars(): void
    {
        $charset = ShortCode::charset();
        $this->assertStringNotContainsString('I', $charset);
        $this->assertStringNotContainsString('O', $charset);
        $this->assertStringNotContainsString('0', $charset);
        $this->assertStringNotContainsString('1', $charset);
    }

    public function testShortCodeGeneratesVariedOutput(): void
    {
        $codes = [];
        for ($i = 0; $i < 100; $i++) {
            $codes[] = ShortCode::generate();
        }
        // 100 codes from 32^6 = 1B space; expect > 95 unique
        $this->assertGreaterThan(95, count(array_unique($codes)));
    }

    // ── Stub factories ─────────────────────────────────────────────────────────

    private function makeCourseRepo(array $courses = []): CourseRepositoryInterface
    {
        return new class ($courses) implements CourseRepositoryInterface {
            public function __construct(private array $rows)
            {
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
                return [];
            }
            public function countByInstructor(int $instructorId): int
            {
                return 0;
            }
            public function create(int $instructorId, string $title, ?string $code, ?string $semester, ?string $description, string $defaultLanguage): int
            {
                return 1;
            }
            public function update(int $id, array $fields): void
            {
            }
            public function archive(int $id): void
            {
            }
        };
    }

    private function makeSessionRepo(array $sessions = []): SessionRepositoryInterface
    {
        return new class ($sessions) implements SessionRepositoryInterface {
            public array $created = [];
            public array $updated = [];
            private array $rows;
            private int $nextId;

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

            public function findByShortCode(string $code): ?array
            {
                foreach ($this->rows as $r) {
                    if ($r['short_code'] === $code) {
                        return $r;
                    }
                }

                return null;
            }

            public function shortCodeExists(string $code): bool
            {
                return $this->findByShortCode($code) !== null;
            }

            public function create(int $courseId, string $title, string $shortCode, string $language, int $isQuiz = 0): int
            {
                $id = $this->nextId++;
                $this->created[] = compact('courseId', 'title', 'shortCode', 'language');
                $this->rows[] = [
                    'id' => $id,
                    'course_id' => $courseId,
                    'title' => $title,
                    'short_code' => $shortCode,
                    'status' => 'active',
                    'language' => $language,
                    'show_results_to_students' => 0,
                    'moderation_mode' => 0,
                    'started_at' => date('Y-m-d H:i:s'),
                    'paused_at' => null,
                    'closed_at' => null,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ];

                return $id;
            }

            public function update(int $id, array $fields): void
            {
                $this->updated[] = ['id' => $id, 'fields' => $fields];
                foreach ($this->rows as &$r) {
                    if ((int) $r['id'] === $id) {
                        foreach ($fields as $k => $v) {
                            $r[$k] = $v;
                        }

                        break;
                    }
                }
            }

            public function listByCourse(int $courseId): array
            {
                return [];
            }
            public function countParticipants(int $sessionId): int
            {
                return 0;
            }
            public function anonymize(int $sessionId): void
            {
            }
        };
    }

    private function sampleCourse(int $id = 1, int $instructorId = 10): array
    {
        return [
            'id' => $id,
            'instructor_id' => $instructorId,
            'title' => 'Test Course',
            'default_language' => 'en',
            'status' => 'active',
        ];
    }

    private function sampleSession(int $id = 1, int $courseId = 1, string $status = 'active'): array
    {
        return [
            'id' => $id,
            'course_id' => $courseId,
            'title' => 'Week 1',
            'short_code' => 'ABCD23',
            'status' => $status,
            'language' => 'en',
            'show_results_to_students' => 0,
            'moderation_mode' => 0,
            'started_at' => date('Y-m-d H:i:s'),
            'paused_at' => null,
            'closed_at' => null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
    }

    private function makeService(array $sessions = [], array $courses = []): SessionService
    {
        return new SessionService(
            $this->makeSessionRepo($sessions),
            $this->makeCourseRepo($courses)
        );
    }

    // ── createSession ──────────────────────────────────────────────────────────

    public function testCreateSessionReturnsIdAndShortCode(): void
    {
        $service = $this->makeService([], [$this->sampleCourse(1, 10)]);
        $result = $service->createSession(1, 10, ['title' => 'Week 1', 'language' => 'en']);

        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('short_code', $result);
        $this->assertSame(6, strlen($result['short_code']));
        $this->assertSame('active', $result['status']);
    }

    public function testCreateSessionThrowsForMissingCourse(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('course_not_found');
        $this->makeService()->createSession(99, 10, ['title' => 'Week 1']);
    }

    public function testCreateSessionThrowsForbiddenForWrongInstructor(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('forbidden');
        $service = $this->makeService([], [$this->sampleCourse(1, 10)]);
        $service->createSession(1, 99, ['title' => 'Week 1']);
    }

    public function testCreateSessionRequiresTitle(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('title:required');
        $service = $this->makeService([], [$this->sampleCourse(1, 10)]);
        $service->createSession(1, 10, ['title' => '']);
    }

    // ── getSession ─────────────────────────────────────────────────────────────

    public function testGetSessionReturnsSessionForOwner(): void
    {
        $service = $this->makeService(
            [$this->sampleSession(1, 1)],
            [$this->sampleCourse(1, 10)]
        );
        $session = $service->getSession(1, 10);
        $this->assertSame('Week 1', $session['title']);
    }

    public function testGetSessionThrowsNotFoundForMissingId(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('session_not_found');
        $this->makeService([], [$this->sampleCourse(1, 10)])->getSession(999, 10);
    }

    public function testGetSessionThrowsForbiddenForWrongInstructor(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('forbidden');
        $service = $this->makeService(
            [$this->sampleSession(1, 1)],
            [$this->sampleCourse(1, 10)]
        );
        $service->getSession(1, 99);
    }

    // ── pauseSession ───────────────────────────────────────────────────────────

    public function testPauseSessionTransitionsActiveTopaused(): void
    {
        $sessionRepo = $this->makeSessionRepo([$this->sampleSession(1, 1, 'active')]);
        $service = new SessionService($sessionRepo, $this->makeCourseRepo([$this->sampleCourse(1, 10)]));
        $service->pauseSession(1, 10);

        $this->assertSame('paused', $sessionRepo->updated[0]['fields']['status']);
        $this->assertNotNull($sessionRepo->updated[0]['fields']['paused_at']);
    }

    public function testPauseSessionThrowsWhenAlreadyPaused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid_state_transition');
        $service = $this->makeService(
            [$this->sampleSession(1, 1, 'paused')],
            [$this->sampleCourse(1, 10)]
        );
        $service->pauseSession(1, 10);
    }

    public function testPauseSessionThrowsWhenClosed(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid_state_transition');
        $service = $this->makeService(
            [$this->sampleSession(1, 1, 'closed')],
            [$this->sampleCourse(1, 10)]
        );
        $service->pauseSession(1, 10);
    }

    // ── resumeSession ──────────────────────────────────────────────────────────

    public function testResumeSessionTransitionsPausedToActive(): void
    {
        $sessionRepo = $this->makeSessionRepo([$this->sampleSession(1, 1, 'paused')]);
        $service = new SessionService($sessionRepo, $this->makeCourseRepo([$this->sampleCourse(1, 10)]));
        $service->resumeSession(1, 10);

        $this->assertSame('active', $sessionRepo->updated[0]['fields']['status']);
        $this->assertNull($sessionRepo->updated[0]['fields']['paused_at']);
    }

    public function testResumeSessionThrowsWhenActive(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid_state_transition');
        $service = $this->makeService(
            [$this->sampleSession(1, 1, 'active')],
            [$this->sampleCourse(1, 10)]
        );
        $service->resumeSession(1, 10);
    }

    // ── closeSession ───────────────────────────────────────────────────────────

    public function testCloseSessionFromActive(): void
    {
        $sessionRepo = $this->makeSessionRepo([$this->sampleSession(1, 1, 'active')]);
        $service = new SessionService($sessionRepo, $this->makeCourseRepo([$this->sampleCourse(1, 10)]));
        $service->closeSession(1, 10);

        $this->assertSame('closed', $sessionRepo->updated[0]['fields']['status']);
        $this->assertNotNull($sessionRepo->updated[0]['fields']['closed_at']);
    }

    public function testCloseSessionFromPaused(): void
    {
        $sessionRepo = $this->makeSessionRepo([$this->sampleSession(1, 1, 'paused')]);
        $service = new SessionService($sessionRepo, $this->makeCourseRepo([$this->sampleCourse(1, 10)]));
        $service->closeSession(1, 10);

        $this->assertSame('closed', $sessionRepo->updated[0]['fields']['status']);
    }

    public function testCloseSessionThrowsWhenAlreadyClosed(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid_state_transition');
        $service = $this->makeService(
            [$this->sampleSession(1, 1, 'closed')],
            [$this->sampleCourse(1, 10)]
        );
        $service->closeSession(1, 10);
    }
}
