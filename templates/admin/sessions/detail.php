<?php

use EduQR\Config;
use EduQR\Middleware\AuthMiddleware;
use EduQR\Middleware\CsrfMiddleware;
use EduQR\Repositories\CourseRepository;
use EduQR\Repositories\SessionRepository;
use EduQR\Services\SessionService;

$instructor = AuthMiddleware::require();
$csrfToken  = CsrfMiddleware::getToken();
$sessionId  = (int) ($p['id'] ?? 0);

$service = new SessionService(new SessionRepository(), new CourseRepository());

try {
    $session = $service->getSession($sessionId, (int) $instructor['id']);
} catch (\RuntimeException $e) {
    $status = $e->getMessage() === 'session_not_found' ? 404 : 403;
    http_response_code($status);
    include __DIR__ . '/../../../templates/errors/' . $status . '.php';
    exit;
}

$courseRepo = new CourseRepository();
$course     = $courseRepo->findById((int) $session['course_id']);

$appUrl  = rtrim(Config::get('APP_URL', ''), '/');
$joinUrl = $appUrl . '/join/' . htmlspecialchars($session['short_code'], ENT_QUOTES, 'UTF-8');

$isActive = $session['status'] === 'active';
$isPaused = $session['status'] === 'paused';
$isClosed = $session['status'] === 'closed';

ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <a href="/admin/courses/<?= (int) $session['course_id'] ?>" class="btn btn-outline-secondary btn-sm">
        &larr; <?= htmlspecialchars($course['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>
    </a>
    <?php if (!$isClosed): ?>
    <a href="/live/<?= htmlspecialchars($session['short_code'], ENT_QUOTES, 'UTF-8') ?>"
       target="_blank" class="btn btn-outline-primary btn-sm">
        <?= htmlspecialchars(t('session.action.view_live'), ENT_QUOTES, 'UTF-8') ?>
    </a>
    <?php endif; ?>
</div>

<div class="row g-4">
    <!-- Left: info + controls -->
    <div class="col-lg-7">
        <div class="d-flex align-items-center gap-3 mb-3">
            <h2 class="mb-0"><?= htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8') ?></h2>
            <span class="badge <?= $isActive ? 'text-bg-success' : ($isPaused ? 'text-bg-warning' : 'text-bg-secondary') ?>">
                <?= htmlspecialchars(t('session.status.' . $session['status']), ENT_QUOTES, 'UTF-8') ?>
            </span>
        </div>

        <dl class="row mb-4">
            <dt class="col-sm-4"><?= htmlspecialchars(t('session.short_code.label'), ENT_QUOTES, 'UTF-8') ?></dt>
            <dd class="col-sm-8">
                <code class="fs-5 fw-bold"><?= htmlspecialchars($session['short_code'], ENT_QUOTES, 'UTF-8') ?></code>
            </dd>

            <dt class="col-sm-4"><?= htmlspecialchars(t('session.join_url.label'), ENT_QUOTES, 'UTF-8') ?></dt>
            <dd class="col-sm-8">
                <a href="<?= htmlspecialchars($joinUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank">
                    <?= htmlspecialchars($joinUrl, ENT_QUOTES, 'UTF-8') ?>
                </a>
            </dd>

            <dt class="col-sm-4"><?= htmlspecialchars(t('session.participants.count'), ENT_QUOTES, 'UTF-8') ?></dt>
            <dd class="col-sm-8">
                <span id="participant-count" class="fw-bold">0</span>
            </dd>
        </dl>

        <?php if (!$isClosed): ?>
        <div class="d-flex gap-2 flex-wrap mb-4">
            <?php if ($isActive): ?>
            <button id="btn-pause" class="btn btn-warning btn-sm">
                <?= htmlspecialchars(t('session.action.pause'), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <?php endif; ?>

            <?php if ($isPaused): ?>
            <button id="btn-resume" class="btn btn-success btn-sm">
                <?= htmlspecialchars(t('session.action.resume'), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <?php endif; ?>

            <button id="btn-close" class="btn btn-outline-danger btn-sm">
                <?= htmlspecialchars(t('session.action.close'), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
        <?php endif; ?>

        <div id="session-feedback" class="alert d-none" role="alert"></div>

        <hr>
        <h5><?= htmlspecialchars(t('nav.sessions') . ' / ' . t('question.new.title'), ENT_QUOTES, 'UTF-8') ?></h5>
        <p class="text-muted small"><?= htmlspecialchars(t('question.no_active'), ENT_QUOTES, 'UTF-8') ?></p>
    </div>

    <!-- Right: QR code -->
    <?php if (!$isClosed): ?>
    <div class="col-lg-5 text-center">
        <p class="fw-semibold mb-2"><?= htmlspecialchars(t('session.qr.title'), ENT_QUOTES, 'UTF-8') ?></p>
        <img src="/api/v1/sessions/<?= (int) $session['id'] ?>/qr.png?size=400"
             alt="<?= htmlspecialchars(t('session.qr.title'), ENT_QUOTES, 'UTF-8') ?>"
             class="img-fluid border rounded p-2"
             style="max-width:320px">
    </div>
    <?php endif; ?>
</div>

<script>
const SESSION_ID = <?= (int) $session['id'] ?>;
const CSRF_TOKEN = <?= json_encode($csrfToken) ?>;
const MSG_CONFIRM_CLOSE = <?= json_encode(t('common.confirm')) ?>;
const MSG_SERVER  = <?= json_encode(t('error.server_error')) ?>;

const feedbackEl = document.getElementById('session-feedback');

function showFeedback(msg, type) {
    feedbackEl.textContent = msg;
    feedbackEl.className   = 'alert alert-' + type;
}

async function sendAction(action) {
    try {
        const res  = await fetch('/api/v1/sessions/' + SESSION_ID + '/' + action, {
            method:  'POST',
            headers: { 'X-CSRF-Token': CSRF_TOKEN },
        });
        const data = await res.json();
        if (data.success) {
            window.location.reload();
        } else {
            showFeedback(data.error?.message || MSG_SERVER, 'danger');
        }
    } catch {
        showFeedback(MSG_SERVER, 'danger');
    }
}

document.getElementById('btn-pause')  ?.addEventListener('click', () => sendAction('pause'));
document.getElementById('btn-resume') ?.addEventListener('click', () => sendAction('resume'));
document.getElementById('btn-close')  ?.addEventListener('click', function () {
    if (!confirm(MSG_CONFIRM_CLOSE)) return;
    sendAction('close');
});

// Live participant count — polls every 5 seconds
async function refreshCount() {
    try {
        const res  = await fetch('/api/v1/sessions/' + SESSION_ID + '/participants/count');
        const data = await res.json();
        if (data.success) {
            document.getElementById('participant-count').textContent = data.data.count;
        }
    } catch {}
}

refreshCount();
<?php if (!$isClosed): ?>
setInterval(refreshCount, 5000);
<?php endif; ?>
</script>
<?php
$content   = ob_get_clean();
$pageTitle = htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8') . ' — ' . t('app.name');
include __DIR__ . '/../../layouts/admin.php';
