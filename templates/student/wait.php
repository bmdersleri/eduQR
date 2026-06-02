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
<div class="row justify-content-center mt-4 mt-md-5">
    <div class="col-12 col-sm-8 col-md-6 col-lg-5 text-center">
        <div class="eduqr-surface eduqr-empty-state">
            <span class="eduqr-icon-badge"><?= eduqr_icon('clock') ?></span>

            <div class="spinner-border text-primary mb-4" role="status" style="width: 3rem; height: 3rem;">
                <span class="visually-hidden"><?= htmlspecialchars(t('common.loading'), ENT_QUOTES, 'UTF-8') ?></span>
            </div>

            <h1 class="h4 mb-2">
                <?= htmlspecialchars(t('student.waiting.title'), ENT_QUOTES, 'UTF-8') ?>
            </h1>
            <p class="text-muted">
                <?= htmlspecialchars(t('student.waiting.message'), ENT_QUOTES, 'UTF-8') ?>
            </p>

            <p class="mt-3 text-muted small mb-0">
                <code><?= htmlspecialchars($session['short_code'], ENT_QUOTES, 'UTF-8') ?></code>
                &mdash;
                <?= htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8') ?>
            </p>
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
