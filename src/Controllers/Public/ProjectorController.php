<?php

declare(strict_types=1);

namespace EduQR\Controllers\Public;

use EduQR\Container;
use EduQR\Contracts\QuestionRepositoryInterface;
use EduQR\Contracts\SessionRepositoryInterface;
use EduQR\Controllers\HtmlController;
use EduQR\Services\SessionService;

/**
 * The two screens a session is projected onto (NFR-81).
 *
 * Both are reached by short code and neither authenticates: the projector is
 * the room's own display, and the code on it is the only credential the room
 * has. They are grouped because that is what they share — a lecture hall wall,
 * the `projector` layout, and a short code resolved before anything is drawn.
 *
 * Both templates used to open with their own copy of that resolution and close
 * with their own copy of `ob_get_clean()` / `$pageTitle` / `include` of the
 * layout. All of it is here now, and the templates are markup.
 *
 * @requirement NFR-81
 */
final class ProjectorController extends HtmlController
{
    private SessionService $sessionService;
    private SessionRepositoryInterface $sessions;
    private QuestionRepositoryInterface $questions;

    public function __construct()
    {
        $this->sessionService = Container::sessionService();
        $this->sessions = Container::sessionRepository();
        $this->questions = Container::questionRepository();
    }

    // ── GET /live/{short_code} ────────────────────────────────────────────────

    /**
     * The join screen: a QR code, the short code in very large type, and the
     * join URL for anyone who would rather type it.
     *
     * The short code is upper-cased and trimmed here because that is what the
     * template did. The student-facing pages do not, and making the two agree
     * would change which sessions resolve — a separate decision from this move.
     */
    public function session(string $shortCode): void
    {
        $shortCode = strtoupper(trim($shortCode));

        try {
            $sessionData = $this->sessionService->resolveByShortCode($shortCode);
        } catch (\RuntimeException) {
            $this->renderError(404);

            return;
        }

        // The QR endpoint is addressed by id, which resolveByShortCode() does
        // not return, so the row is read a second time for it alone. A session
        // that resolved cannot be missing here; the guard is what the template
        // had, and it keeps the id an int either way.
        $rawSession = $this->sessions->findByShortCode($shortCode);
        $sessionId = $rawSession ? (int) $rawSession['id'] : 0;

        $this->render(
            'live/session.php',
            [
                'sessionData' => $sessionData,
                'sessionId' => $sessionId,
                'joinUrl' => eduqr_url('/join/' . $sessionData['short_code']),
                'qrUrl' => eduqr_path('/api/v1/sessions/' . $sessionId . '/qr.png?size=600'),
            ],
            self::titleWithAppName($sessionData['title']),
            self::LAYOUT_PROJECTOR,
        );
    }

    // ── GET /live/{short_code}/results ────────────────────────────────────────

    /**
     * Projector live results view (T-807).
     *
     * Large-type display of current question results for classroom projection.
     * The page polls results every 3 seconds and renders a horizontal bar chart
     * with plain Bootstrap markup so it works without external JS assets.
     * No authentication required — it uses the public short_code.
     *
     * When session.show_results_to_students = 0, the projector still shows
     * results (instructor-controlled display, not student-facing).
     */
    public function results(string $shortCode): void
    {
        $shortCode = strtoupper(trim($shortCode));

        $session = $this->sessions->findByShortCode($shortCode);

        if ($session === null) {
            $this->renderError(404);

            return;
        }

        $activeQuestion = $this->questions->findActiveBySessionCode($shortCode);

        $this->render(
            'live/results.php',
            [
                'session' => $session,
                'sessionId' => (int) $session['id'],
                'shortCode' => $shortCode,
                'activeQ' => $activeQuestion,
                'activeQId' => $activeQuestion !== null ? (int) $activeQuestion['id'] : 0,
            ],
            self::titleWithAppName(t('results.title')),
            self::LAYOUT_PROJECTOR,
        );
    }
}
