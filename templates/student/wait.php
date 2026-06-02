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
<div class="eduqr-student-screen">
<div class="row justify-content-center w-100 g-0">
    <div class="col-12 col-lg-8 col-xl-7">
        <div class="eduqr-student-shell eduqr-stagger">
            <div class="eduqr-student-hero">
                <div class="eduqr-kicker mb-3">
                    <span class="eduqr-icon-badge eduqr-breathe"><?= eduqr_icon('clock') ?></span>
                    <span><code><?= htmlspecialchars($session['short_code'], ENT_QUOTES, 'UTF-8') ?></code></span>
                </div>
                <?php $courseTitle = trim((string) ($session['course_title'] ?? '')); ?>
                <h1 class="display-6 fw-bold mb-2"><?= htmlspecialchars($courseTitle !== '' ? $courseTitle : $session['title'], ENT_QUOTES, 'UTF-8') ?></h1>
                <?php if ($courseTitle !== ''): ?>
                    <p class="text-muted mb-1 fw-semibold"><?= htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
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
                <p class="text-muted mb-4 eduqr-breathe"><?= htmlspecialchars(t('student.waiting.message'), ENT_QUOTES, 'UTF-8') ?></p>

                <div class="d-flex justify-content-center flex-wrap gap-2">
                    <span class="eduqr-chip"><?= eduqr_icon('spark') ?> <?= htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="eduqr-chip"><?= eduqr_icon('qr') ?> <code><?= htmlspecialchars($session['short_code'], ENT_QUOTES, 'UTF-8') ?></code></span>
                </div>

                <div class="mt-4 d-flex justify-content-center gap-2 eduqr-steps">
                    <i class="done"></i>
                    <i class="on"></i>
                    <i></i>
                    <i></i>
                </div>
                <p class="text-muted small mt-2"><?= htmlspecialchars(t('student.waiting.step_explain'), ENT_QUOTES, 'UTF-8') ?></p>
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

async function pollActiveQuestion() {
    try {
        const res  = await fetch('/api/v1/sessions/' + SHORT_CODE + '/active-question');
        const data = await res.json();
        const question = extractActiveQuestion(data);
        if (question) {
            window.location.href = '/play/' + SHORT_CODE;
        }
    } catch {
        // keep polling
    }
}

pollActiveQuestion();
setInterval(pollActiveQuestion, 3000);
</script>
<?php
$content   = ob_get_clean();
$pageTitle = htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8') . ' — ' . t('app.name');
include __DIR__ . '/../layouts/public.php';
