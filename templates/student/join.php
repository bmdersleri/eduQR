<?php

use EduQR\Repositories\SessionRepository;
use EduQR\Services\SessionService;
use EduQR\Repositories\CourseRepository;

$shortCode = $p['short_code'] ?? '';

$sessionRepo = new SessionRepository();
$session     = $sessionRepo->findByShortCode($shortCode);

if ($session === null) {
    http_response_code(404);
    include __DIR__ . '/../../templates/errors/404.php';
    exit;
}

// If session is closed or paused, show a status page instead of the form
if ($session['status'] === 'closed') {
    http_response_code(410);
    $statusMessage = t('error.session_closed');
    ob_start();
    ?>
<div class="row justify-content-center mt-5">
    <div class="col-12 col-sm-8 col-md-6 col-lg-4">
        <div class="card border-secondary">
            <div class="card-body text-center py-5">
                <p class="fs-5 text-secondary mb-0"><?= htmlspecialchars($statusMessage, ENT_QUOTES, 'UTF-8') ?></p>
            </div>
        </div>
    </div>
</div>
    <?php
    $content   = ob_get_clean();
    $pageTitle = t('app.name');
    include __DIR__ . '/../layouts/public.php';
    exit;
}

if ($session['status'] === 'paused') {
    $statusMessage = t('session.paused_message');
    ob_start();
    ?>
<div class="row justify-content-center mt-5">
    <div class="col-12 col-sm-8 col-md-6 col-lg-4">
        <div class="card border-warning">
            <div class="card-body text-center py-5">
                <p class="fs-5 text-warning-emphasis mb-0"><?= htmlspecialchars($statusMessage, ENT_QUOTES, 'UTF-8') ?></p>
            </div>
        </div>
    </div>
</div>
    <?php
    $content   = ob_get_clean();
    $pageTitle = t('app.name');
    include __DIR__ . '/../layouts/public.php';
    exit;
}

ob_start();
?>
<div class="row justify-content-center mt-4 mt-md-5">
    <div class="col-12 col-sm-8 col-md-6 col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h1 class="h4 text-center mb-1">
                    <?= htmlspecialchars(t('student.join.title'), ENT_QUOTES, 'UTF-8') ?>
                </h1>
                <p class="text-muted text-center small mb-4">
                    <?= htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8') ?>
                </p>

                <div id="join-error" class="alert alert-danger d-none" role="alert"></div>

                <form id="join-form" novalidate>
                    <div class="mb-3">
                        <label for="nickname" class="form-label fw-semibold">
                            <?= htmlspecialchars(t('student.join.nickname.label'), ENT_QUOTES, 'UTF-8') ?>
                        </label>
                        <input
                            type="text"
                            id="nickname"
                            name="nickname"
                            class="form-control form-control-lg"
                            placeholder="<?= htmlspecialchars(t('student.join.nickname.placeholder'), ENT_QUOTES, 'UTF-8') ?>"
                            maxlength="24"
                            autocomplete="nickname"
                            required
                            autofocus
                        >
                        <div class="invalid-feedback" id="nickname-feedback"></div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg w-100">
                        <?= htmlspecialchars(t('student.join.submit'), ENT_QUOTES, 'UTF-8') ?>
                    </button>
                </form>

                <?php include __DIR__ . '/../partials/privacy-notice.php'; ?>
            </div>
        </div>
    </div>
</div>

<script>
const SHORT_CODE = <?= json_encode($shortCode) ?>;
const MSG_SERVER = <?= json_encode(t('error.server_error')) ?>;

const form        = document.getElementById('join-form');
const errorBox    = document.getElementById('join-error');
const nickField   = document.getElementById('nickname');
const nickFeedback = document.getElementById('nickname-feedback');

function showError(msg) {
    errorBox.textContent = msg;
    errorBox.classList.remove('d-none');
}

function clearErrors() {
    errorBox.classList.add('d-none');
    nickField.classList.remove('is-invalid');
    nickFeedback.textContent = '';
}

form.addEventListener('submit', async function (e) {
    e.preventDefault();
    clearErrors();

    const nickname = nickField.value.trim();
    if (!nickname) {
        nickField.classList.add('is-invalid');
        nickFeedback.textContent = <?= json_encode(t('validation.required')) ?>;
        nickField.focus();
        return;
    }

    const btn = form.querySelector('button[type=submit]');
    btn.disabled = true;

    try {
        const res  = await fetch('/api/v1/sessions/' + SHORT_CODE + '/join', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ nickname }),
        });
        const data = await res.json();

        if (data.success) {
            window.location.href = '/join/' + SHORT_CODE + '/wait';
        } else {
            const err = data.error ?? {};
            if (err.field === 'nickname') {
                nickField.classList.add('is-invalid');
                nickFeedback.textContent = err.message || MSG_SERVER;
            } else {
                showError(err.message || MSG_SERVER);
            }
            btn.disabled = false;
        }
    } catch {
        showError(MSG_SERVER);
        btn.disabled = false;
    }
});
</script>
<?php
$content   = ob_get_clean();
$pageTitle = htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8') . ' — ' . t('app.name');
include __DIR__ . '/../layouts/public.php';
