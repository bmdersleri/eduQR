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

$questionRepo    = new QuestionRepository();
$activeQuestion  = $questionRepo->findActiveBySessionCode($shortCode);
$activeQuestionId = $activeQuestion !== null ? (int) $activeQuestion['id'] : 0;

ob_start();
?>
<div class="row justify-content-center mt-4 mt-md-5">
    <div class="col-12 col-sm-8 col-md-6 col-lg-4 text-center">

        <!-- Success badge -->
        <div class="mb-4">
            <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" fill="currentColor"
                 class="bi bi-check-circle-fill text-success" viewBox="0 0 16 16" aria-hidden="true">
                <path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zm-3.97-3.03a.75.75 0 0 0-1.08.022L7.477
                    9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0
                    1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/>
            </svg>
        </div>

        <h1 class="h4 mb-2">
            <?= htmlspecialchars(t('student.answer.submitted'), ENT_QUOTES, 'UTF-8') ?>
        </h1>
        <p class="text-muted">
            <?= htmlspecialchars(t('student.answer.waiting_next'), ENT_QUOTES, 'UTF-8') ?>
        </p>

        <p class="mt-3 text-muted small">
            <code><?= htmlspecialchars($shortCode, ENT_QUOTES, 'UTF-8') ?></code>
            &mdash;
            <?= htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8') ?>
        </p>

        <!-- Spinner shown while polling -->
        <div id="polling-indicator" class="mt-4">
            <div class="spinner-border spinner-border-sm text-secondary" role="status">
                <span class="visually-hidden">
                    <?= htmlspecialchars(t('common.loading'), ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>
        </div>

    </div>
</div>

<script>
const SHORT_CODE          = <?= json_encode($shortCode) ?>;
const CURRENT_QUESTION_ID = <?= $activeQuestionId ?>;

/**
 * Poll for a *new* active question.
 * If a new question appears (different ID from the one just answered),
 * navigate to /play/{short_code} so the student can answer it.
 */
async function pollForNextQuestion() {
    try {
        const res  = await fetch('/api/v1/sessions/' + SHORT_CODE + '/active-question');
        const data = await res.json();

        if (data.success && data.data) {
            const newId = data.data.id;
            // Only navigate if a genuinely new question is active
            if (newId && newId !== CURRENT_QUESTION_ID) {
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
