<?php

declare(strict_types=1);

namespace EduQR\Services;

use EduQR\Contracts\CourseRepositoryInterface;
use EduQR\Contracts\UserRepositoryInterface;
use EduQR\Exceptions\ConflictException;
use EduQR\Exceptions\DomainException;
use EduQR\Exceptions\ForbiddenException;
use EduQR\Exceptions\NotFoundException;
use EduQR\Exceptions\ValidationException;

/**
 * Business logic for course management.
 *
 * Access rule (FR-14, FR-97): access is decided by the caller's row in
 * course_instructors, never by comparing courses.instructor_id directly.
 *
 *   getCourse()    — owner OR co_instructor. The read/write choke point.
 *   requireOwner() — owner only. Archive, restore, and instructor management.
 *
 * Both throw NotFoundException('course_not_found') when no such course exists
 * and ForbiddenException('forbidden') when the caller lacks the needed role.
 *
 * Validation failures throw ValidationException carrying the status, the
 * published code and the offending field (NFR-83). Every one of them answers
 * 400 validation_error; what differs between them is the field and the reason
 * code that selects the message.
 */
final class CourseService
{
    private const ALLOWED_LANGS = ['en', 'tr'];
    private const MAX_TITLE_LEN = 200;
    private const MAX_CODE_LEN = 40;
    private const MAX_SEM_LEN = 40;
    private const MAX_EMAIL_LEN = 190;

    public const ROLE_OWNER = 'owner';
    public const ROLE_CO_INSTRUCTOR = 'co_instructor';

    public function __construct(
        private readonly CourseRepositoryInterface $courses,
        private readonly UserRepositoryInterface   $users,
    ) {
    }

    // ── Read ───────────────────────────────────────────────────────────────────

    public function listMyCourses(int $instructorId, int $page = 1, int $perPage = 20): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        return [
            'data' => $this->courses->listByInstructor($instructorId, $page, $perPage),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $this->courses->countByInstructor($instructorId),
            ],
        ];
    }

    /**
     * Returns the course if the caller owns or co-instructs it (FR-97).
     *
     * @throws NotFoundException  if no such course
     * @throws ForbiddenException if the caller has no role on it
     */
    public function getCourse(int $id, int $instructorId): array
    {
        $course = $this->courses->findById($id);

        if ($course === null) {
            throw new NotFoundException('course_not_found');
        }
        if ($this->courses->roleFor($id, $instructorId) === null) {
            throw new ForbiddenException('forbidden');
        }

        return $course;
    }

    /**
     * Returns the course only if the caller is its owner (FR-97).
     *
     * Owner-only actions: archive, restore, and managing the instructor list.
     * Deliberately a separate method rather than a flag on getCourse() so a
     * call site cannot widen its own permissions by accident.
     *
     * A caller with no role at all gets 'forbidden'; a co-instructor — who can
     * legitimately see the course — gets the more precise 'course_owner_only'.
     * Both surface as HTTP 403 with the `forbidden` error code.
     *
     * @throws DomainException course_not_found | forbidden | course_owner_only
     */
    public function requireOwner(int $id, int $instructorId): array
    {
        $course = $this->courses->findById($id);

        if ($course === null) {
            throw new NotFoundException('course_not_found');
        }

        $role = $this->courses->roleFor($id, $instructorId);

        if ($role === null) {
            throw new ForbiddenException('forbidden');
        }
        if ($role !== self::ROLE_OWNER) {
            throw new ForbiddenException('course_owner_only', 403, 'forbidden');
        }

        return $course;
    }

    // ── Write ──────────────────────────────────────────────────────────────────

    /** @throws ValidationException on validation failure */
    public function createCourse(int $instructorId, array $data): int
    {
        $title = $this->validateTitle($data['title'] ?? '');
        $code = $this->validateOptionalStr($data['code'] ?? null, self::MAX_CODE_LEN, 'code');
        $semester = $this->validateOptionalStr($data['semester'] ?? null, self::MAX_SEM_LEN, 'semester');
        $description = isset($data['description']) && $data['description'] !== ''
                        ? (string) $data['description']
                        : null;
        $lang = $this->validateLanguage($data['default_language'] ?? 'en');

        return $this->courses->create($instructorId, $title, $code, $semester, $description, $lang);
    }

    /** @throws DomainException on access or validation failure */
    public function updateCourse(int $id, int $instructorId, array $data): void
    {
        $this->getCourse($id, $instructorId);

        $fields = [];

        if (array_key_exists('title', $data)) {
            $fields['title'] = $this->validateTitle($data['title']);
        }
        if (array_key_exists('code', $data)) {
            $fields['code'] = $this->validateOptionalStr($data['code'], self::MAX_CODE_LEN, 'code');
        }
        if (array_key_exists('semester', $data)) {
            $fields['semester'] = $this->validateOptionalStr($data['semester'], self::MAX_SEM_LEN, 'semester');
        }
        if (array_key_exists('description', $data)) {
            $fields['description'] = ($data['description'] !== null && $data['description'] !== '')
                                     ? (string) $data['description']
                                     : null;
        }
        if (array_key_exists('default_language', $data)) {
            $fields['default_language'] = $this->validateLanguage($data['default_language']);
        }

        $this->courses->update($id, $fields);
    }

    /**
     * Owner only (FR-97).
     *
     * @throws DomainException on access failure
     */
    public function archiveCourse(int $id, int $instructorId): void
    {
        $this->requireOwner($id, $instructorId);
        $this->courses->archive($id);
    }

    /**
     * Owner only (FR-97).
     *
     * @throws DomainException on access or state failure
     */
    public function restoreCourse(int $id, int $instructorId): void
    {
        $course = $this->requireOwner($id, $instructorId);
        if ($course['status'] !== 'archived') {
            throw new ConflictException('invalid_course_state');
        }

        $this->courses->restore($id);
    }

    // ── Instructor list (FR-97) ────────────────────────────────────────────────

    /**
     * Visible to the owner and to co-instructors.
     *
     * @throws DomainException course_not_found | forbidden
     */
    public function listInstructors(int $courseId, int $userId): array
    {
        $this->getCourse($courseId, $userId);

        return array_map(
            static fn (array $row): array => [
                'user_id' => (int) $row['user_id'],
                'email' => (string) $row['email'],
                'display_name' => (string) $row['display_name'],
                'role' => (string) $row['role'],
                'created_at' => (string) $row['created_at'],
            ],
            $this->courses->listInstructors($courseId)
        );
    }

    /**
     * Grants co-instructor access to an existing instructor account, addressed
     * by email. There is no invitation flow: an unknown email is rejected.
     *
     * Owner only.
     *
     * @return array{user_id:int,role:string}
     *
     * @throws DomainException required | invalid_email | course_not_found | forbidden
     *                           | instructor_not_found | already_course_instructor
     */
    public function addInstructor(int $courseId, int $ownerId, array $data): array
    {
        $this->requireOwner($courseId, $ownerId);

        $email = $this->validateEmail($data['email'] ?? null);
        $user = $this->users->findByEmail($email);

        // A co-instructor must be an active instructor account (DATA_MODEL §2.2).
        // Accounts that are not eligible are reported as not found rather than
        // confirming that the address belongs to someone.
        if ($user === null
            || ($user['role'] ?? null) !== 'instructor'
            || ! (bool) ($user['is_active'] ?? 1)) {
            throw new NotFoundException('instructor_not_found');
        }

        $userId = (int) $user['id'];

        // Covers both "already a co-instructor" and "this is the owner".
        if ($this->courses->roleFor($courseId, $userId) !== null) {
            throw new ConflictException('already_course_instructor');
        }

        $this->courses->addInstructor($courseId, $userId, self::ROLE_CO_INSTRUCTOR);

        return ['user_id' => $userId, 'role' => self::ROLE_CO_INSTRUCTOR];
    }

    /**
     * Revokes co-instructor access. The owner cannot be removed — that would
     * leave the course without an owner.
     *
     * Owner only.
     *
     * @throws DomainException course_not_found | forbidden
     *                           | cannot_remove_course_owner | course_instructor_not_found
     */
    public function removeInstructor(int $courseId, int $ownerId, int $userId): void
    {
        $this->requireOwner($courseId, $ownerId);

        $role = $this->courses->roleFor($courseId, $userId);

        if ($role === null) {
            throw new NotFoundException('course_instructor_not_found');
        }
        if ($role === self::ROLE_OWNER) {
            throw new ConflictException('cannot_remove_course_owner');
        }

        if (! $this->courses->removeInstructor($courseId, $userId)) {
            throw new NotFoundException('course_instructor_not_found');
        }
    }

    // ── Validators ─────────────────────────────────────────────────────────────

    private function validateTitle(mixed $raw): string
    {
        $title = trim((string) $raw);
        if ($title === '') {
            throw new ValidationException('required', 400, 'validation_error', 'title');
        }
        if (mb_strlen($title) > self::MAX_TITLE_LEN) {
            throw new ValidationException('text_too_long', 400, 'validation_error', 'title');
        }

        return $title;
    }

    private function validateOptionalStr(mixed $raw, int $maxLen, string $field): ?string
    {
        if ($raw === null || (string) $raw === '') {
            return null;
        }
        $val = trim((string) $raw);
        if ($val === '') {
            return null;
        }
        if (mb_strlen($val) > $maxLen) {
            throw new ValidationException('text_too_long', 400, 'validation_error', $field);
        }

        return $val;
    }

    private function validateLanguage(mixed $raw): string
    {
        $lang = (string) $raw;
        if (! in_array($lang, self::ALLOWED_LANGS, true)) {
            throw new ValidationException('invalid_language', 400, 'validation_error', 'default_language');
        }

        return $lang;
    }

    private function validateEmail(mixed $raw): string
    {
        if (! is_string($raw)) {
            throw new ValidationException('required', 400, 'validation_error', 'email');
        }

        $email = mb_strtolower(trim($raw));

        if ($email === '') {
            throw new ValidationException('required', 400, 'validation_error', 'email');
        }
        if (mb_strlen($email) > self::MAX_EMAIL_LEN || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException('invalid_email', 400, 'validation_error', 'email');
        }

        return $email;
    }
}
