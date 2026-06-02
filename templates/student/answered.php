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
<div class="eduqr-confetti" aria-hidden="true" id="confetti">
    <span></span><span></span><span></span><span></span>
    <span></span><span></span><span></span><span></span>
</div>

<div class="eduqr-student-screen">
<div class="row justify-content-center w-100 g-0">
    <div class="col-12 col-lg-7 col-xl-6 text-center">
        <div class="eduqr-student-shell eduqr-stagger">
            <div class="eduqr-student-stage eduqr-student-stage--center">
                <svg class="eduqr-check-svg" viewBox="0 0 52 52" aria-hidden="true">
                    <circle cx="26" cy="26" r="24"/>
                    <path fill="none" d="M16 26l7 7 13-13"/>
                </svg>

                <div class="eduqr-kicker justify-content-center mb-3">
                    <span><?= htmlspecialchars(t('student.answer.submitted'), ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <h1 class="h3 mb-2"><?= htmlspecialchars(t('student.answer.submitted'), ENT_QUOTES, 'UTF-8') ?></h1>
                <p class="text-muted mb-4"><?= htmlspecialchars(t('student.answer.waiting_next'), ENT_QUOTES, 'UTF-8') ?></p>

                <?php $courseTitle = trim((string) ($session['course_title'] ?? '')); ?>
                <div class="d-flex justify-content-center flex-wrap gap-2 mb-4">
                    <span class="eduqr-chip"><?= eduqr_icon('qr') ?> <code><?= htmlspecialchars($shortCode, ENT_QUOTES, 'UTF-8') ?></code></span>
                    <?php if ($courseTitle !== ''): ?>
                        <span class="eduqr-chip"><?= eduqr_icon('chart') ?> <?= htmlspecialchars($courseTitle, ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endif; ?>
                    <span class="eduqr-chip"><?= eduqr_icon('spark') ?> <?= htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8') ?></span>
                </div>

                <div id="polling-indicator" class="d-inline-flex align-items-center gap-2 text-muted small eduqr-breathe">
                    <span class="eduqr-spinner" role="status" aria-hidden="true"></span>
                    <span><?= htmlspecialchars(t('common.loading'), ENT_QUOTES, 'UTF-8') ?></span>
                </div>
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

// ── Confetti clean-up ────────────────────────────────────────────
setTimeout(() => {
    const c = document.getElementById('confetti');
    if (c) c.style.display = 'none';
}, 3500);

// ── Poll for next question ───────────────────────────────────────
async function pollForNextQuestion() {
    try {
        const res  = await fetch('/api/v1/sessions/' + SHORT_CODE + '/active-question');
        const data = await res.json();
        const question = extractActiveQuestion(data);
        if (question) {
            const newId = question.id;
            if (newId && (ANSWERED_QUESTION_ID === 0 || newId !== ANSWERED_QUESTION_ID)) {
                window.location.href = '/play/' + SHORT_CODE;
            }
        }
    } catch {
        // keep polling
    }
}

pollForNextQuestion();
setInterval(pollForNextQuestion, 3000);
</script>
<?php
$content   = ob_get_clean();
$pageTitle = htmlspecialchars(t('student.answer.submitted'), ENT_QUOTES, 'UTF-8') . ' — ' . t('app.name');
include __DIR__ . '/../layouts/public.php';
