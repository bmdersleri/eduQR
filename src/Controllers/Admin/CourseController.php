<?php

declare(strict_types=1);

namespace EduQR\Controllers\Admin;

use EduQR\Container;
use EduQR\Contracts\CourseAnalyticsServiceInterface;
use EduQR\Contracts\SessionRepositoryInterface;
use EduQR\Controllers\HtmlController;
use EduQR\Exceptions\DomainException;
use EduQR\Middleware\CsrfMiddleware;
use EduQR\Services\CourseService;

/**
 * The five course screens of the instructor panel (NFR-81).
 *
 * The pages only; `Api\CourseController` does the creating, updating and
 * archiving that their forms post. Grouped by resource for the same reason the
 * API controllers are: five classes with one method each would put five copies
 * of the same authenticate-then-load-a-course opening in five files.
 *
 * Each of these five templates used to open with its own copy of that opening,
 * and close with its own copy of `ob_get_clean()` / `$pageTitle` / `include` of
 * the admin layout. All of it is here now, and the templates are markup.
 *
 * @requirement NFR-81
 */
final class CourseController extends HtmlController
{
    private CourseService $courses;
    private CourseAnalyticsServiceInterface $reports;
    private SessionRepositoryInterface $sessions;

    public function __construct()
    {
        $this->courses = Container::courseService();
        $this->reports = Container::courseAnalyticsService();
        $this->sessions = Container::sessionRepository();
    }

    // ── GET /admin/courses ────────────────────────────────────────────────────

    /**
     * The page number is read here rather than in the template because a query
     * parameter is request input, and reading request input is what a
     * controller is for.
     */
    public function index(): void
    {
        $instructor = $this->requireUser();

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $result = $this->courses->listMyCourses((int) $instructor['id'], $page, 20);

        $this->render(
            'admin/courses/list.php',
            [
                'instructor' => $instructor,
                'csrfToken' => CsrfMiddleware::getToken(),
                'courses' => $result['data'],
                'meta' => $result['meta'],
            ],
            self::titleWithAppName(t('course.list.title')),
            self::LAYOUT_ADMIN,
        );
    }

    // ── GET /admin/courses/new ────────────────────────────────────────────────

    /**
     * An empty form. Nothing to look up, so nothing to fail — the only reason
     * this page needs a controller at all is that it needs to be authenticated,
     * and under NFR-81 that no longer happens inside a template.
     */
    public function create(): void
    {
        $this->requireUser();

        $this->render(
            'admin/courses/create.php',
            ['csrfToken' => CsrfMiddleware::getToken()],
            self::titleWithAppName(t('course.new.title')),
            self::LAYOUT_ADMIN,
        );
    }

    // ── GET /admin/courses/{id} ───────────────────────────────────────────────

    public function show(int $courseId): void
    {
        $instructor = $this->requireUser();

        try {
            $course = $this->courses->getCourse($courseId, (int) $instructor['id']);
        } catch (DomainException $e) {
            $this->renderDomainError($e);

            return;
        }

        $sessions = $this->sessions->listByCourse($courseId);

        $this->render(
            'admin/courses/detail.php',
            [
                'csrfToken' => CsrfMiddleware::getToken(),
                'course' => $course,
                'sessions' => $sessions,
                // Owner-only controls (FR-97). The API enforces the same rule
                // server-side; this only decides what is drawn.
                'isCourseOwner' => (int) $course['instructor_id'] === (int) $instructor['id'],
            ],
            // The title is escaped here and again by the layout. That double
            // escape predates this move and is preserved by it: undoing it
            // changes the rendered bytes of every course whose title contains
            // an ampersand or a quote, which is a change of its own.
            self::titleWithAppName($course['title']),
            self::LAYOUT_ADMIN,
        );
    }

    // ── GET /admin/courses/{id}/analytics ─────────────────────────────────────

    public function analytics(int $courseId): void
    {
        $instructor = $this->requireUser();

        try {
            $analytics = $this->reports->buildCourseAnalytics($courseId, (int) $instructor['id']);
        } catch (DomainException $e) {
            $this->renderDomainError($e);

            return;
        }

        $course = $analytics['course'];

        $this->render(
            'admin/courses/analytics.php',
            [
                'course' => $course,
                'summary' => $analytics['summary'],
                'sessions' => $analytics['sessions'],
                // A type with no questions of that type is not a data point.
                'questionTypeBreakdown' => array_filter(
                    $analytics['question_type_breakdown'],
                    static fn (array $row): bool => (int) $row['count'] > 0,
                ),
                // FR-85: locale-aware percent — tr renders "%83,4", en renders "83.4%".
                'formatRate' => static fn (float $rate): string => fmt_percent($rate * 100),
            ],
            // The one title that ends in the course rather than the app name,
            // deliberately: two analytics tabs are told apart by their course.
            t('course.analytics.title') . ' — ' . $course['title'],
            self::LAYOUT_ADMIN,
        );
    }

    // ── GET /admin/courses/{id}/edit ──────────────────────────────────────────

    public function edit(int $courseId): void
    {
        $instructor = $this->requireUser();

        try {
            $course = $this->courses->getCourse($courseId, (int) $instructor['id']);
            // FR-97. listInstructors() re-checks access, so it belongs inside
            // the same try; in the template it sat outside one, where a failure
            // would have escaped as an unhandled exception.
            $courseInstructors = $this->courses->listInstructors($courseId, (int) $instructor['id']);
        } catch (DomainException $e) {
            $this->renderDomainError($e);

            return;
        }

        $this->render(
            'admin/courses/edit.php',
            [
                'csrfToken' => CsrfMiddleware::getToken(),
                'course' => $course,
                'courseInstructors' => self::withOwnerFlag($courseInstructors),
                'isCourseOwner' => $this->isOwner($courseInstructors, (int) $instructor['id']),
            ],
            self::titleWithAppName(t('course.edit.title')),
            self::LAYOUT_ADMIN,
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Decide the owner badge here rather than in the template.
     *
     * The template used to compare each row against `CourseService::ROLE_OWNER`
     * without importing the class. Templates carry no namespace, so that
     * resolved to a global `\CourseService` which does not exist, and the page
     * fatalled the moment a course had a second instructor to list — the only
     * reason it was survivable is that a single-instructor course never enters
     * the loop. A prepared boolean is what NFR-81 asks for anyway.
     *
     * @param  list<array<string, mixed>> $courseInstructors
     * @return list<array<string, mixed>>
     */
    private static function withOwnerFlag(array $courseInstructors): array
    {
        return array_map(
            static fn (array $row): array => $row + ['is_owner' => $row['role'] === CourseService::ROLE_OWNER],
            $courseInstructors
        );
    }

    /**
     * Owner is a per-course role, not a property of the user (FR-97), so it is
     * read off the course's own instructor list.
     *
     * @param list<array{user_id:int,role:string}> $courseInstructors
     */
    private function isOwner(array $courseInstructors, int $userId): bool
    {
        foreach ($courseInstructors as $courseInstructor) {
            if ($courseInstructor['user_id'] === $userId
                && $courseInstructor['role'] === CourseService::ROLE_OWNER) {
                return true;
            }
        }

        return false;
    }
}
