<?php
/**
 * Answer confirmation screen — /play/{short_code}/answered  (T-711)
 *
 * Shown after a student successfully submits an answer.
 * Polls for the next active question every 3 seconds (like wait.php does).
 */

use EduQR\Repositories\QuestionRepository;
use EduQR\Repositories\SessionRepository;

$shortCode = $p['short_code'] ?? '';

$sessionRepo  = new SessionRepository();
$session      = $sessionRepo->findByShortCode($shortCode);

if ($session === null) {
    http_response_code(404);
    include __DIR__ . '/../../templates/errors/404.php';
    exit;
}

$answeredQuestionId = (int) ($_GET['answered_q'] ?? 0);

ob_start();
?>
<div class="row justify-content-center py-4 py-lg-5">
    <div class="col-12 col-lg-7 col-xl-6 text-center">
        <div class="eduqr-student-shell">
            <div class="eduqr-student-stage eduqr-student-stage--center">
                <span class="eduqr-icon-badge mx-auto mb-3"><?= eduqr_icon('check') ?></span>
                <div class="eduqr-kicker justify-content-center mb-3">
                    <span><?= htmlspecialchars(t('student.answer.submitted'), ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <h1 class="h3 mb-2"><?= htmlspecialchars(t('student.answer.submitted'), ENT_QUOTES, 'UTF-8') ?></h1>
                <p class="text-muted mb-4"><?= htmlspecialchars(t('student.answer.waiting_next'), ENT_QUOTES, 'UTF-8') ?></p>

                <div class="d-flex justify-content-center flex-wrap gap-2 mb-4">
                    <span class="eduqr-chip"><?= eduqr_icon('qr') ?> <code><?= htmlspecialchars($shortCode, ENT_QUOTES, 'UTF-8') ?></code></span>
                    <span class="eduqr-chip"><?= eduqr_icon('spark') ?> <?= htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8') ?></span>
                </div>

                <div id="polling-indicator" class="d-inline-flex align-items-center gap-2 text-muted small">
                    <span class="spinner-border spinner-border-sm text-secondary" role="status" aria-hidden="true"></span>
                    <span><?= htmlspecialchars(t('common.loading'), ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const SHORT_CODE          = <?= json_encode($shortCode) ?>;
const ANSWERED_QUESTION_ID = <?= $answeredQuestionId ?>;

function extractActiveQuestion(payload) {
    if (!payload || !payload.success || !payload.data) {
        return null;
    }

    if (Object.prototype.hasOwnProperty.call(payload.data, 'question')) {
        return payload.data.question;
    }

    return payload.data;
}

/**
 * Poll for a *new* active question.
 * If a new question appears (different ID from the one just answered),
 * navigate to /play/{short_code} so the student can answer it.
 */
async function pollForNextQuestion() {
    try {
        const res  = await fetch('/api/v1/sessions/' + SHORT_CODE + '/active-question');
        const data = await res.json();
        const question = extractActiveQuestion(data);

        if (question) {
            const newId = question.id;
            // If we know the answered question id, move only when a newer/different one is active.
            // If param is missing (legacy link), any active question should move to play.
            if (newId && (ANSWERED_QUESTION_ID === 0 || newId !== ANSWERED_QUESTION_ID)) {
                window.location.href = '/play/' + SHORT_CODE;
            }
        }
    } catch {
        // Network error — keep polling silently
    }
}

pollForNextQuestion();
setInterval(pollForNextQuestion, 3000);
</script>
<?php
$content   = ob_get_clean();
$pageTitle = htmlspecialchars(t('student.answer.submitted'), ENT_QUOTES, 'UTF-8') . ' — ' . t('app.name');
include __DIR__ . '/../layouts/public.php';
