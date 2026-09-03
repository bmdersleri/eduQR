<?php

declare(strict_types=1);

namespace EduQR\Controllers\Api;

use EduQR\Controllers\ApiController;
use EduQR\Middleware\AuthMiddleware;
use EduQR\Middleware\CsrfMiddleware;
use EduQR\Repositories\AuditLogRepository;
use EduQR\Repositories\CourseRepository;
use EduQR\Repositories\UserRepository;
use EduQR\Services\CourseService;

final class CourseController extends ApiController
{
    private CourseService $service;
    private AuditLogRepository $auditLog;

    public function __construct()
    {
        $this->service = new CourseService(new CourseRepository(), new UserRepository());
        $this->auditLog = new AuditLogRepository();
    }

    // ── GET /api/v1/courses ────────────────────────────────────────────────────

    public function index(): void
    {
        $user = AuthMiddleware::require();
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($_GET['per_page'] ?? 20)));

        $result = $this->service->listMyCourses((int) $user['id'], $page, $perPage);

        $this->json(200, [
            'success' => true,
            'data' => $result['data'],
            'meta' => $result['meta'],
        ]);
    }

    // ── POST /api/v1/courses ───────────────────────────────────────────────────

    public function create(): void
    {
        $user = AuthMiddleware::require();
        CsrfMiddleware::verify();

        $body = $this->jsonBody();

        try {
            $id = $this->service->createCourse((int) $user['id'], $body);
        } catch (\InvalidArgumentException $e) {
            $this->handleValidationException($e);
        }

        // Audit: FR-90
        try {
            $this->auditLog->write('instructor', (int) $user['id'], 'course.create', 'course', $id);
        } catch (\Throwable) {
        }

        $this->json(201, [
            'success' => true,
            'data' => ['id' => $id],
            'message' => t('course.created'),
        ]);
    }

    // ── GET /api/v1/courses/{id} ───────────────────────────────────────────────

    public function show(int $id): void
    {
        $user = AuthMiddleware::require();

        try {
            $course = $this->service->getCourse($id, (int) $user['id']);
        } catch (\RuntimeException $e) {
            $this->handleRuntimeException($e);
        }

        $this->json(200, [
            'success' => true,
            'data' => $this->coursePayload($course),
        ]);
    }

    // ── PATCH /api/v1/courses/{id} ─────────────────────────────────────────────

    public function update(int $id): void
    {
        $user = AuthMiddleware::require();
        CsrfMiddleware::verify();

        $body = $this->jsonBody();

        try {
            $this->service->updateCourse($id, (int) $user['id'], $body);
        } catch (\RuntimeException $e) {
            $this->handleRuntimeException($e);
        } catch (\InvalidArgumentException $e) {
            $this->handleValidationException($e);
        }

        $this->json(200, [
            'success' => true,
            'data' => null,
            'message' => t('course.updated'),
        ]);
    }

    // ── DELETE /api/v1/courses/{id} ────────────────────────────────────────────

    public function archive(int $id): void
    {
        $user = AuthMiddleware::require();
        CsrfMiddleware::verify();

        try {
            $this->service->archiveCourse($id, (int) $user['id']);
        } catch (\RuntimeException $e) {
            $this->handleRuntimeException($e);
        }

        // Audit: FR-90
        try {
            $this->auditLog->write('instructor', (int) $user['id'], 'course.archive', 'course', $id);
        } catch (\Throwable) {
        }

        $this->json(200, [
            'success' => true,
            'data' => null,
            'message' => t('course.archived'),
        ]);
    }

    public function restore(int $id): void
    {
        $user = AuthMiddleware::require();
        CsrfMiddleware::verify();

        try {
            $this->service->restoreCourse($id, (int) $user['id']);
        } catch (\RuntimeException $e) {
            $this->handleRuntimeException($e);
        }

        try {
            $this->auditLog->write('instructor', (int) $user['id'], 'course.restore', 'course', $id);
        } catch (\Throwable) {
        }

        $this->json(200, [
            'success' => true,
            'data' => null,
            'message' => t('course.restored'),
        ]);
    }

    // ── GET /api/v1/courses/{id}/instructors ───────────────────────────────────

    /** @requirement FR-97 */
    public function listInstructors(int $id): void
    {
        $user = AuthMiddleware::require();

        try {
            $instructors = $this->service->listInstructors($id, (int) $user['id']);
        } catch (\RuntimeException $e) {
            $this->handleRuntimeException($e);
        }

        $this->json(200, [
            'success' => true,
            'data' => $instructors,
        ]);
    }

    // ── POST /api/v1/courses/{id}/instructors ──────────────────────────────────

    /** @requirement FR-97 */
    public function addInstructor(int $id): void
    {
        $user = AuthMiddleware::require();
        CsrfMiddleware::verify();

        $body = $this->jsonBody();

        try {
            $added = $this->service->addInstructor($id, (int) $user['id'], $body);
        } catch (\RuntimeException $e) {
            $this->handleRuntimeException($e);
        } catch (\InvalidArgumentException $e) {
            $this->handleValidationException($e);
        }

        // Audit: FR-90
        try {
            $this->auditLog->write(
                'instructor',
                (int) $user['id'],
                'course.instructor_added',
                'course',
                $id,
                ['user_id' => $added['user_id'], 'role' => $added['role']]
            );
        } catch (\Throwable) {
        }

        $this->json(201, [
            'success' => true,
            'data' => $added,
            'message' => t('course.instructor.added'),
        ]);
    }

    // ── DELETE /api/v1/courses/{id}/instructors/{userId} ───────────────────────

    /** @requirement FR-97 */
    public function removeInstructor(int $id, int $userId): void
    {
        $user = AuthMiddleware::require();
        CsrfMiddleware::verify();

        try {
            $this->service->removeInstructor($id, (int) $user['id'], $userId);
        } catch (\RuntimeException $e) {
            $this->handleRuntimeException($e);
        }

        // Audit: FR-90
        try {
            $this->auditLog->write(
                'instructor',
                (int) $user['id'],
                'course.instructor_removed',
                'course',
                $id,
                ['user_id' => $userId]
            );
        } catch (\Throwable) {
        }

        $this->json(200, [
            'success' => true,
            'data' => null,
            'message' => t('course.instructor.removed'),
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function coursePayload(array $course): array
    {
        return [
            'id' => (int) $course['id'],
            'instructor_id' => (int) $course['instructor_id'],
            'title' => $course['title'],
            'code' => $course['code'],
            'semester' => $course['semester'],
            'description' => $course['description'],
            'default_language' => $course['default_language'],
            'status' => $course['status'],
            'created_at' => $course['created_at'],
            'updated_at' => $course['updated_at'],
        ];
    }

    private function handleValidationException(\InvalidArgumentException $e): never
    {
        $parts = explode(':', $e->getMessage(), 2);
        $field = $parts[0];
        $key = $parts[1] ?? 'validation_error';
        $message = match (true) {
            $key === 'required' => t('validation.required'),
            $key === 'too_long' => t('validation.text_too_long'),
            $key === 'invalid' && $field === 'email' => t('validation.invalid_email'),
            $key === 'invalid' => t('validation.invalid_language'),
            default => t('common.error'),
        };
        $this->json(400, [
            'success' => false,
            'error' => ['code' => 'validation_error', 'message' => $message, 'field' => $field],
        ]);
    }
}
