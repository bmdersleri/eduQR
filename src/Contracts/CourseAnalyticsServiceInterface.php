<?php

declare(strict_types=1);

namespace EduQR\Contracts;

use EduQR\Exceptions\DomainException;

/**
 * Course-level analytics across every session of a course (FR-64, NFR-82).
 *
 * A roll-up, not a new measurement: each session contributes the summary its
 * own report already computes, so a number shown on the course screen and the
 * same number shown on the session screen can never disagree.
 */
interface CourseAnalyticsServiceInterface
{
    /**
     * Builds a course-level analytics view across every session in the course.
     *
     * @return array<string,mixed>
     * @throws DomainException course_not_found | forbidden
     */
    public function buildCourseAnalytics(int $courseId, int $userId): array;
}
