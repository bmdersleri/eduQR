<?php

declare(strict_types=1);

namespace EduQR\Services;

use EduQR\Contracts\CourseRepositoryInterface;

/**
 * Business logic for course management.
 *
 * Ownership rule (FR-14): every mutating method receives $instructorId and
 * throws RuntimeException('forbidden') if the course belongs to another user.
 *
 * Validation failures throw \InvalidArgumentException with message format
 * "field:error_key" (e.g. "title:required") so the controller can build a
 * structured error response with field and message.
 */
final class CourseService
{
    private const ALLOWED_LANGS = ['en', 'tr'];
    private const MAX_TITLE_LEN = 200;
    private const MAX_CODE_LEN = 40;
    private const MAX_SEM_LEN = 40;

    public function __construct(private readonly CourseRepositoryInterface $courses)
    {
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
     * Returns the course, enforcing ownership.
     *
     * @throws \RuntimeException('course_not_found') if no such course
     * @throws \RuntimeException('forbidden') if owned by another instructor
     */
    public function getCourse(int $id, int $instructorId): array
    {
        $course = $this->courses->findById($id);

        if ($course === null) {
            throw new \RuntimeException('course_not_found');
        }
        if ((int) $course['instructor_id'] !== $instructorId) {
            throw new \RuntimeException('forbidden');
        }

        return $course;
    }

    // ── Write ──────────────────────────────────────────────────────────────────

    /** @throws \InvalidArgumentException on validation failure */
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

    /** @throws \RuntimeException on ownership failure; \InvalidArgumentException on validation */
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

    /** @throws \RuntimeException on ownership failure */
    public function archiveCourse(int $id, int $instructorId): void
    {
        $this->getCourse($id, $instructorId);
        $this->courses->archive($id);
    }

    // ── Validators ─────────────────────────────────────────────────────────────

    private function validateTitle(mixed $raw): string
    {
        $title = trim((string) $raw);
        if ($title === '') {
            throw new \InvalidArgumentException('title:required');
        }
        if (mb_strlen($title) > self::MAX_TITLE_LEN) {
            throw new \InvalidArgumentException('title:too_long');
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
            throw new \InvalidArgumentException("{$field}:too_long");
        }

        return $val;
    }

    private function validateLanguage(mixed $raw): string
    {
        $lang = (string) $raw;
        if (! in_array($lang, self::ALLOWED_LANGS, true)) {
            throw new \InvalidArgumentException('default_language:invalid');
        }

        return $lang;
    }
}
