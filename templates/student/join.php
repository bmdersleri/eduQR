<?php

use EduQR\Repositories\SessionRepository;
use EduQR\Repositories\ParticipantRepository;
use EduQR\Services\ParticipantService;

$shortCode = $p['short_code'] ?? '';

$sessionRepo = new SessionRepository();
$participantRepo = new ParticipantRepository();
$participantService = new ParticipantService($participantRepo, $sessionRepo);
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
        <div class="eduqr-surface eduqr-empty-state">
            <span class="eduqr-icon-badge"><?= eduqr_icon('clock') ?></span>
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
        <div class="eduqr-surface eduqr-empty-state">
            <span class="eduqr-icon-badge"><?= eduqr_icon('clock') ?></span>
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

$setParticipantCookie = static function (int $participantId): void {
    setcookie('eduqr_participant', (string) $participantId, [
        'expires' => 0,
        'path' => '/',
        'secure' => (bool) ($_SERVER['HTTPS'] ?? false),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
};

$participantId = (int) ($_COOKIE['eduqr_participant'] ?? 0);
if ($participantId > 0) {
    $participant = $participantRepo->findById($participantId);
    if ($participant !== null && (int) $participant['session_id'] === (int) $session['id']) {
        $setParticipantCookie($participantId);
        http_response_code(302);
        header('Location: /play/' . rawurlencode($shortCode));
        exit;
    }
}

$deviceCookieId = $_COOKIE['eduqr_device'] ?? '';
$returningParticipant = $participantService->restore(
    $shortCode,
    is_string($deviceCookieId) && $deviceCookieId !== '' ? $deviceCookieId : null,
    $_SERVER['HTTP_USER_AGENT'] ?? '',
);

if ($returningParticipant !== null) {
    $setParticipantCookie((int) $returningParticipant['id']);
    http_response_code(302);
    header('Location: /play/' . rawurlencode($shortCode));
    exit;
}

ob_start();
?>
<div class="row justify-content-center align-items-stretch g-4 py-4 py-lg-5 eduqr-stagger">
    <div class="col-12 col-lg-5 d-none d-lg-block">
        <div class="eduqr-hero h-100" style="animation:float 8s ease-in-out infinite">
            <div class="eduqr-kicker">
                <span class="eduqr-icon-badge"><?= eduqr_icon('qr') ?></span>
                <span><?= htmlspecialchars(t('student.join.title'), ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <h1 class="display-5 fw-bold mb-3"><?= htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="lead text-muted mb-4">
                <?= htmlspecialchars(t('student.waiting.message'), ENT_QUOTES, 'UTF-8') ?>
            </p>
            <div class="eduqr-panel-grid">
                <div class="eduqr-statcard">
                    <div class="d-flex align-items-center gap-2 mb-2"><?= eduqr_icon('user') ?><strong><?= htmlspecialchars(t('student.join.nickname_title'), ENT_QUOTES, 'UTF-8') ?></strong></div>
                    <p class="text-muted mb-0"><?= htmlspecialchars(t('student.join.nickname.placeholder'), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div class="eduqr-statcard">
                    <div class="d-flex align-items-center gap-2 mb-2"><?= eduqr_icon('clock') ?><strong><?= htmlspecialchars(t('student.join.once_only_title'), ENT_QUOTES, 'UTF-8') ?></strong></div>
                    <p class="text-muted mb-0"><?= htmlspecialchars(t('student.join.return_desc'), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-8 col-md-6 col-lg-4">
        <div class="eduqr-surface h-100">
            <div class="p-4 p-lg-5">
                <div class="text-center mb-4">
                    <span class="eduqr-icon-badge mx-auto mb-3 eduqr-breathe"><?= eduqr_icon('user') ?></span>
                    <h2 class="h4 mb-1"><?= htmlspecialchars(t('student.join.title'), ENT_QUOTES, 'UTF-8') ?></h2>
                    <p class="text-muted small mb-0">
                        <code><?= htmlspecialchars($session['short_code'], ENT_QUOTES, 'UTF-8') ?></code>
                        &mdash; <?= htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8') ?>
                    </p>
                </div>

                <div id="join-error" class="alert alert-danger d-none" role="alert"></div>

                <form id="join-form" novalidate>
                    <div class="mb-3">
                        <label for="nickname" class="form-label fw-semibold">
                            <?= htmlspecialchars(t('student.join.nickname.label'), ENT_QUOTES, 'UTF-8') ?>
                        </label>
                        <div class="position-relative">
                            <input
                                type="text"
                                id="nickname"
                                name="nickname"
                                class="form-control form-control-lg eduqr-glow-focus"
                                placeholder="<?= htmlspecialchars(t('student.join.nickname.placeholder'), ENT_QUOTES, 'UTF-8') ?>"
                                maxlength="24"
                                autocomplete="nickname"
                                required
                                autofocus
                            >
                            <div class="form-text text-end" id="nick-char" style="margin-top:.25rem">0 / 24</div>
                        </div>
                        <div class="invalid-feedback" id="nickname-feedback"></div>
                    </div>

                    <button type="submit" id="join-btn" class="btn btn-primary btn-lg w-100 eduqr-ripple">
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
const joinBtn     = document.getElementById('join-btn');
const nickChar    = document.getElementById('nick-char');

function showError(msg) {
    errorBox.textContent = msg;
    errorBox.classList.remove('d-none');
    errorBox.style.animation = 'slide-down .35s ease-out';
}

function clearErrors() {
    errorBox.classList.add('d-none');
    nickField.classList.remove('is-invalid');
    nickFeedback.textContent = '';
}

// ── Character counter ─────────────────────────────────────────────
nickField.addEventListener('input', function () {
    nickChar.textContent = this.value.length + ' / 24';
});

// ── Enter key triggers submit ─────────────────────────────────────
nickField.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') form.dispatchEvent(new Event('submit'));
});

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

    joinBtn.disabled = true;
    joinBtn.innerHTML = '<span class="eduqr-spinner me-2"></span>' + <?= json_encode(t('common.loading')) ?>;

    try {
        const res  = await fetch('/api/v1/sessions/' + SHORT_CODE + '/join', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ nickname }),
        });
        const data = await res.json();

        if (data.success) {
            joinBtn.innerHTML = <?= json_encode(t('student.join.joining')) ?>;
            await new Promise(r => setTimeout(r, 400));
            window.location.href = '/join/' + SHORT_CODE + '/wait';
        } else {
            const err = data.error ?? {};
            if (err.field === 'nickname') {
                nickField.classList.add('is-invalid');
                nickFeedback.textContent = err.message || MSG_SERVER;
            } else {
                showError(err.message || MSG_SERVER);
            }
            joinBtn.disabled = false;
            joinBtn.innerHTML = <?= json_encode(t('student.join.submit')) ?>;
        }
    } catch {
        showError(MSG_SERVER);
        joinBtn.disabled = false;
        joinBtn.innerHTML = <?= json_encode(t('student.join.submit')) ?>;
    }
});
</script>
<?php
$content   = ob_get_clean();
$pageTitle = htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8') . ' — ' . t('app.name');
include __DIR__ . '/../layouts/public.php';
