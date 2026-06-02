<?php

use EduQR\Repositories\SessionRepository;

$shortCode = $p['short_code'] ?? '';

$sessionRepo = new SessionRepository();
$session     = $sessionRepo->findByShortCode($shortCode);

if ($session === null || $session['status'] === 'closed') {
    http_response_code(404);
    include __DIR__ . '/../../templates/errors/404.php';
    exit;
}

ob_start();
?>
<div class="row justify-content-center py-4 py-lg-5">
    <div class="col-12 col-lg-8 col-xl-7">
        <div class="eduqr-student-shell">
            <div class="eduqr-student-hero">
                <div class="eduqr-kicker mb-3">
                    <span class="eduqr-icon-badge"><?= eduqr_icon('clock') ?></span>
                    <span><code><?= htmlspecialchars($session['short_code'], ENT_QUOTES, 'UTF-8') ?></code></span>
                </div>
                <h1 class="display-6 fw-bold mb-2"><?= htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8') ?></h1>
                <p class="text-muted mb-0"><?= htmlspecialchars(t('student.waiting.message'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>

            <div class="eduqr-student-stage eduqr-student-stage--center eduqr-student-stage--quiet">
                <div class="eduqr-hourglass" aria-hidden="true">
                    <div class="eduqr-hourglass-frame"></div>
                    <div class="eduqr-hourglass-sand top"></div>
                    <div class="eduqr-hourglass-stream"></div>
                    <div class="eduqr-hourglass-sand bottom"></div>
                </div>

                <div class="eduqr-kicker justify-content-center mb-3">
                    <span><?= htmlspecialchars(t('common.loading'), ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <h2 class="h3 mb-2"><?= htmlspecialchars(t('student.waiting.title'), ENT_QUOTES, 'UTF-8') ?></h2>
                <p class="text-muted mb-4"><?= htmlspecialchars(t('student.waiting.message'), ENT_QUOTES, 'UTF-8') ?></p>

                <div class="d-flex justify-content-center flex-wrap gap-2">
                    <span class="eduqr-chip"><?= eduqr_icon('spark') ?> <?= htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="eduqr-chip"><?= eduqr_icon('qr') ?> <code><?= htmlspecialchars($session['short_code'], ENT_QUOTES, 'UTF-8') ?></code></span>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const SHORT_CODE = <?= json_encode($shortCode) ?>;

function extractActiveQuestion(payload) {
    if (!payload || !payload.success || !payload.data) {
        return null;
    }

    if (Object.prototype.hasOwnProperty.call(payload.data, 'question')) {
        return payload.data.question;
    }

    return payload.data;
}

// FR-45: poll for an active question every 3 seconds
async function pollActiveQuestion() {
    try {
        const res  = await fetch('/api/v1/sessions/' + SHORT_CODE + '/active-question');
        const data = await res.json();
        const question = extractActiveQuestion(data);
        if (question) {
            // A question is now active — navigate to the play screen (Phase 6+)
            window.location.href = '/play/' + SHORT_CODE;
        }
    } catch {
        // Network error — keep polling silently
    }
}

pollActiveQuestion();
setInterval(pollActiveQuestion, 3000);
</script>
<?php
$content   = ob_get_clean();
$pageTitle = htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8') . ' — ' . t('app.name');
include __DIR__ . '/../layouts/public.php';
