<?php

declare(strict_types=1);

namespace EduQR\Controllers\Admin;

use EduQR\Config;
use EduQR\Container;
use EduQR\Contracts\CourseRepositoryInterface;
use EduQR\Contracts\QuestionRepositoryInterface;
use EduQR\Contracts\ReportBuilderInterface;
use EduQR\Controllers\HtmlController;
use EduQR\Exceptions\DomainException;
use EduQR\Middleware\CsrfMiddleware;
use EduQR\Services\CourseService;
use EduQR\Services\SessionService;

/**
 * The four session screens of the instructor panel (NFR-81).
 *
 * The pages only; `Api\SessionController` and its neighbours do the starting,
 * pausing, closing and exporting that their controls post. Grouped by resource
 * the way the API controllers are.
 *
 * The new-session form lives here rather than in {@see CourseController}
 * because the page it renders is `admin/sessions/create.php` and everything it
 * prepares is about the session being made; that its route is nested under a
 * course is a fact about the URL, not about the screen.
 *
 * @requirement NFR-81
 */
final class SessionController extends HtmlController
{
    private SessionService $sessions;
    private CourseService $courses;
    private ReportBuilderInterface $reports;
    private CourseRepositoryInterface $courseRepository;
    private QuestionRepositoryInterface $questions;

    public function __construct()
    {
        $this->sessions = Container::sessionService();
        $this->courses = Container::courseService();
        $this->reports = Container::reportBuilder();
        $this->courseRepository = Container::courseRepository();
        $this->questions = Container::questionRepository();
    }

    // ── GET /admin/courses/{id}/sessions/new ──────────────────────────────────

    /**
     * The form for a new session in one course. The id in the path is the
     * course's, and the course is loaded only to prove the caller may add a
     * session to it — and to put its title at the top of the form.
     */
    public function create(int $courseId): void
    {
        $instructor = $this->requireUser();

        try {
            $course = $this->courses->getCourse($courseId, (int) $instructor['id']);
        } catch (DomainException $e) {
            $this->renderDomainError($e);

            return;
        }

        $this->render(
            'admin/sessions/create.php',
            [
                'csrfToken' => CsrfMiddleware::getToken(),
                'course' => $course,
            ],
            self::titleWithAppName(t('session.new.title')),
            self::LAYOUT_ADMIN,
        );
    }

    // ── GET /admin/sessions/{id} ──────────────────────────────────────────────

    public function show(int $sessionId): void
    {
        $instructor = $this->requireUser();

        try {
            $session = $this->sessions->getSession($sessionId, (int) $instructor['id']);
        } catch (DomainException $e) {
            $this->renderDomainError($e);

            return;
        }

        $this->render(
            'admin/sessions/detail.php',
            [
                'csrfToken' => CsrfMiddleware::getToken(),
                'session' => $session,
                'sessionId' => $sessionId,
                // getSession() has already established that this course exists
                // and that the caller may see it, so the row is only being read
                // again here for its title.
                'course' => $this->courseRepository->findById((int) $session['course_id']),
                'joinUrl' => $this->joinUrl($session['short_code']),
                'pollIntervalMs' => $this->sessionPollIntervalMs(),
                // The three status flags the page draws its controls from. They
                // are computed here so the template compares no strings.
                'isActive' => $session['status'] === 'active',
                'isPaused' => $session['status'] === 'paused',
                'isClosed' => $session['status'] === 'closed',
            ],
            self::titleWithAppName($session['title']),
            self::LAYOUT_ADMIN,
        );
    }

    // ── GET /admin/sessions/{id}/results ──────────────────────────────────────

    /** T-805. The page polls the results API; this only draws its first frame. */
    public function results(int $sessionId): void
    {
        $instructor = $this->requireUser();

        try {
            $session = $this->sessions->getSession($sessionId, (int) $instructor['id']);
        } catch (DomainException $e) {
            $this->renderDomainError($e);

            return;
        }

        $this->render(
            'admin/sessions/results.php',
            [
                'csrfToken' => CsrfMiddleware::getToken(),
                'session' => $session,
                'sessionId' => $sessionId,
                'questions' => $this->questions->findBySession($sessionId),
                'pollIntervalMs' => $this->resultsPollIntervalMs(),
            ],
            self::titleWithAppName(t('results.title')),
            self::LAYOUT_ADMIN,
        );
    }

    // ── GET /admin/sessions/{id}/report ───────────────────────────────────────

    /**
     * T-905. Not anonymised: this is the instructor's own view, and the
     * anonymised copy is what the export endpoints produce (T-906).
     */
    public function report(int $sessionId): void
    {
        $instructor = $this->requireUser();

        try {
            $report = $this->reports->buildReport($sessionId, (int) $instructor['id'], false);
        } catch (DomainException $e) {
            $this->renderDomainError($e);

            return;
        }

        $this->render(
            'admin/sessions/report.php',
            [
                'csrfToken' => CsrfMiddleware::getToken(),
                'report' => $report,
                'session' => $report['session'],
                'summary' => $report['summary'],
                'sessionId' => $sessionId,
            ],
            self::titleWithAppName(t('report.title')),
            self::LAYOUT_ADMIN,
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * How often the session detail screen re-reads the live counters, in
     * milliseconds (NFR-76, API_SPEC.md §1.10).
     *
     * The key is read here rather than in the template because a template that
     * asks `Config` a question is a template doing a controller's job — the
     * thing NFR-81 has just finished taking out of these five files. The default
     * is the number `admin/sessions/detail.php` hardcoded before this change, so
     * a deployment that sets nothing polls exactly as it did.
     *
     * @requirement NFR-76
     */
    private function sessionPollIntervalMs(): int
    {
        return Config::int('POLL_INTERVAL_INSTRUCTOR_SESSION_MS', 5000);
    }

    /**
     * How often the live results screen re-reads the answers, in milliseconds.
     *
     * The bare `INSTRUCTOR` key means this screen specifically; `.env.example`
     * published the two names before NFR-76 split the screens apart, and
     * API_SPEC.md §1.10 keeps them.
     *
     * @requirement NFR-76
     */
    private function resultsPollIntervalMs(): int
    {
        return Config::int('POLL_INTERVAL_INSTRUCTOR_MS', 2000);
    }

    /**
     * The address a student types or scans.
     *
     * Unescaped, like every other value a controller hands a template. This
     * used to pre-escape the short code on the theory that its two uses in
     * `admin/sessions/detail.php` expected it that way; they do not — the
     * template escapes `$joinUrl` again for both the href and the link text.
     * Nothing rendered differently only because a short code is `[A-Z0-9]`.
     */
    private function joinUrl(string $shortCode): string
    {
        return rtrim(Config::get('APP_URL', ''), '/') . '/join/' . $shortCode;
    }
}
