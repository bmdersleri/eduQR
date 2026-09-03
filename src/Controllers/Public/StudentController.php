<?php

declare(strict_types=1);

namespace EduQR\Controllers\Public;

use EduQR\Container;
use EduQR\Contracts\OptionRepositoryInterface;
use EduQR\Contracts\ParticipantRepositoryInterface;
use EduQR\Contracts\QuestionRepositoryInterface;
use EduQR\Contracts\SessionRepositoryInterface;
use EduQR\Controllers\HtmlController;
use EduQR\Services\ParticipantService;

/**
 * The screens a student sees on their own phone (NFR-81).
 *
 * Grouped by audience for the reason the admin controllers are grouped by
 * resource: every one of these pages opens by resolving a session from the
 * short code in the URL and answering a miss with the 404 page, and a class per
 * route would put five copies of that opening in five files.
 *
 * Each template used to carry that opening itself, along with the cookie reads,
 * the redirects and its own copy of `ob_get_clean()` / `$pageTitle` / `include`
 * of the public layout. All of it is here now, and the templates are markup.
 *
 * @requirement NFR-81
 */
final class StudentController extends HtmlController
{
    /** The most questions the batch screen puts on one page. */
    private const BATCH_QUESTION_LIMIT = 4;

    private SessionRepositoryInterface $sessions;
    private QuestionRepositoryInterface $questions;
    private OptionRepositoryInterface $options;
    private ParticipantRepositoryInterface $participants;
    private ParticipantService $participantService;

    public function __construct()
    {
        $this->sessions = Container::sessionRepository();
        $this->questions = Container::questionRepository();
        $this->options = Container::optionRepository();
        $this->participants = Container::participantRepository();
        $this->participantService = Container::participantService();
    }

    // ── GET /join/{short_code}/wait ───────────────────────────────────────────

    /**
     * The holding screen between joining and the first question. It polls for an
     * active question and sends the browser to `/play/{code}` when one appears.
     */
    public function wait(string $shortCode): void
    {
        $session = $this->sessions->findByShortCode($shortCode);

        if ($session === null || $session['status'] === 'closed') {
            $this->renderError(404);

            return;
        }

        $this->render(
            'student/wait.php',
            [
                'session' => $session,
                'shortCode' => $shortCode,
            ],
            // The title is escaped here and again by the layout. That double
            // escape predates this move and is preserved by it: undoing it
            // changes the rendered bytes of every session whose title contains
            // an ampersand or a quote, which is a change of its own.
            self::titleWithAppName(htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8')),
            self::LAYOUT_PUBLIC,
        );
    }

    // ── GET /play/{short_code}/answered ───────────────────────────────────────

    /**
     * The confirmation screen after an answer lands (T-711). It polls for the
     * next question, which is why it needs to know which one was just answered.
     *
     * `answered_q` is a query parameter, and reading request input is what a
     * controller is for.
     */
    public function answered(string $shortCode): void
    {
        $session = $this->sessions->findByShortCode($shortCode);

        if ($session === null) {
            $this->renderError(404);

            return;
        }

        $this->render(
            'student/answered.php',
            [
                'session' => $session,
                'shortCode' => $shortCode,
                'answeredQuestionId' => (int) ($_GET['answered_q'] ?? 0),
            ],
            // Escaped here and again by the layout, as above.
            self::titleWithAppName(htmlspecialchars(t('student.answer.submitted'), ENT_QUOTES, 'UTF-8')),
            self::LAYOUT_PUBLIC,
        );
    }

    // ── GET /play/{short_code}/batch ──────────────────────────────────────────

    /**
     * Several questions on one page, answered together.
     *
     * A visitor with no participant cookie is sent to the join page rather than
     * shown an error, exactly as before — and, as before, with no status of its
     * own, so the `Location` header goes out beside a 200.
     */
    public function batch(string $shortCode): void
    {
        $session = $this->sessions->findByShortCode($shortCode);

        if ($session === null || $session['status'] === 'closed') {
            $this->renderError(404);

            return;
        }

        $participantId = (int) ($_COOKIE['eduqr_participant'] ?? 0);

        if ($participantId <= 0) {
            $this->redirect(eduqr_path('/join/' . rawurlencode($shortCode)));
        }

        $questions = $this->openQuestionsWithOptions((int) $session['id']);

        $this->render(
            'student/batch.php',
            [
                'session' => $session,
                'shortCode' => $shortCode,
                'questions' => $questions,
                // The count was taken after `ob_start()`, which made it look
                // like part of the markup. It is view data, so it is prepared
                // here and handed over with everything else.
                'totalQ' => \count($questions),
            ],
            // The only one of these titles the template did not escape before
            // handing it to the layout. Left unescaped so the rendered bytes do
            // not change.
            self::titleWithAppName(t('student.batch.title')),
            self::LAYOUT_PUBLIC,
        );
    }

    // ── GET /join/{short_code} ────────────────────────────────────────────────

    /**
     * The nickname form, and the four answers that are not it.
     *
     * The template used to include the public layout in the middle of itself
     * and `exit`, once for the closed session and once for the paused one. Each
     * of those bodies is now a template of its own under `student/join/`, and
     * this method chooses between them. They are kept apart from the play
     * page's equivalents, which say the same thing in different markup;
     * unifying them would change rendered bytes.
     */
    public function join(string $shortCode): void
    {
        $session = $this->sessions->findByShortCode($shortCode);

        if ($session === null) {
            $this->renderError(404);

            return;
        }

        // A closed session answers 410 here and 200 when paused. Both statuses
        // are what the template sent; neither is reconsidered by this move.
        if ($session['status'] === 'closed') {
            $this->render('student/join/closed.php', [], t('app.name'), self::LAYOUT_PUBLIC, 410);

            return;
        }

        if ($session['status'] === 'paused') {
            $this->render('student/join/paused.php', [], t('app.name'), self::LAYOUT_PUBLIC);

            return;
        }

        $participantId = (int) ($_COOKIE['eduqr_participant'] ?? 0);

        if ($participantId > 0) {
            $participant = $this->participants->findById($participantId);

            // A cookie from another session is ignored rather than cleared, and
            // falls through to the form below.
            if ($participant !== null && (int) $participant['session_id'] === (int) $session['id']) {
                $this->setParticipantCookie($participantId);
                $this->redirect(eduqr_path('/play/' . rawurlencode($shortCode)), 302);
            }
        }

        // Returning on the same device after clearing the participant cookie.
        $deviceCookieId = $_COOKIE['eduqr_device'] ?? '';
        $returningParticipant = $this->participantService->restore(
            $shortCode,
            \is_string($deviceCookieId) && $deviceCookieId !== '' ? $deviceCookieId : null,
            $_SERVER['HTTP_USER_AGENT'] ?? '',
        );

        if ($returningParticipant !== null) {
            $this->setParticipantCookie((int) $returningParticipant['id']);
            $this->redirect(eduqr_path('/play/' . rawurlencode($shortCode)), 302);
        }

        $this->render(
            'student/join.php',
            [
                'session' => $session,
                'shortCode' => $shortCode,
            ],
            // Escaped here and again by the layout, as above.
            self::titleWithAppName(htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8')),
            self::LAYOUT_PUBLIC,
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * The first few questions of a session that are still open, each with its
     * options attached.
     *
     * The limit is applied while walking rather than in SQL because the closed
     * ones are skipped in PHP, exactly as the template did it.
     *
     * @return list<array<string, mixed>>
     */
    private function openQuestionsWithOptions(int $sessionId): array
    {
        $questions = [];

        foreach ($this->questions->findBySession($sessionId) as $question) {
            if (($question['status'] ?? '') === 'closed') {
                continue;
            }

            $question['options'] = $this->options->findByQuestion((int) $question['id']);
            $questions[] = $question;

            if (\count($questions) >= self::BATCH_QUESTION_LIMIT) {
                break;
            }
        }

        return $questions;
    }

    /**
     * Write the participant id back to its cookie.
     *
     * Setting a cookie is a decision about the response, not about the markup,
     * so it belongs here; the template held it in a closure. The options are
     * the template's, verbatim: a session cookie (`expires 0`), site-wide,
     * secure only where the request already was, unreadable from JavaScript,
     * and `SameSite=Lax` so it survives arriving from a scanned QR code.
     */
    private function setParticipantCookie(int $participantId): void
    {
        setcookie('eduqr_participant', (string) $participantId, [
            'expires' => 0,
            'path' => '/',
            'secure' => (bool) ($_SERVER['HTTPS'] ?? false),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * Send a redirect and stop.
     *
     * `render()` cannot express a redirect and should not, so this is the one
     * place these pages leave the render contract. The content type goes out
     * first because that is where the router closures being replaced sent it —
     * before the template ran, therefore before the template redirected — and
     * `HtmlController::requireUser()` keeps it on `AuthMiddleware`'s redirect
     * for the same reason. Passing no status leaves PHP's default 200 on the
     * wire beside the `Location`, which is what the batch redirect has always
     * done; the callers that sent 302 or 303 still send exactly that.
     */
    private function redirect(string $location, ?int $status = null): never
    {
        header('Content-Type: text/html; charset=utf-8');

        if ($status !== null) {
            http_response_code($status);
        }

        header('Location: ' . $location);

        exit;
    }
}
