<?php

declare(strict_types=1);

namespace EduQR\Controllers\Admin;

use EduQR\Container;
use EduQR\Controllers\HtmlController;
use EduQR\Services\CourseService;

/**
 * The instructor's landing page (NFR-81).
 *
 * One method, because the dashboard is one screen and belongs to no other
 * resource: folding it into {@see CourseController} would put a page that is
 * not about a course into a class whose every other method loads one.
 *
 * Nothing here can fail. `listMyCourses()` answers with an empty page rather
 * than throwing, so this is the only admin page with no `catch`.
 *
 * @requirement NFR-81
 */
final class DashboardController extends HtmlController
{
    private CourseService $courses;

    public function __construct()
    {
        $this->courses = Container::courseService();
    }

    // ── GET /admin/dashboard ──────────────────────────────────────────────────

    public function index(): void
    {
        $instructor = $this->requireUser();

        $courses = $this->courses->listMyCourses((int) $instructor['id'], 1, 3);

        $this->render(
            'admin/dashboard.php',
            [
                // The greeting names the instructor, so the page needs the user
                // in its own scope and not only in the layout's.
                'instructor' => $instructor,
                'recentCourses' => $courses['data'] ?? [],
            ],
            self::titleWithAppName(t('instructor.dashboard.title')),
            self::LAYOUT_ADMIN,
        );
    }
}
