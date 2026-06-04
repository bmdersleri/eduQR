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

if ($session['status'] === 'closed') {
    http_response_code(410);
    ob_start(); ?>
<div class="row justify-content-center" style="margin-top:clamp(2rem,8vh,4rem)">
    <div class="col-12 col-sm-8 col-md-6 col-lg-4 text-center">
        <div class="eduqr-surface" style="padding:clamp(2rem,4vw,3rem)">
            <div class="eduqr-icon-badge mx-auto mb-3" style="width:3.2rem;height:3.2rem;border-radius:1.2rem"><?= eduqr_icon('clock') ?></div>
            <p class="fs-5 mb-0"><?= htmlspecialchars(t('error.session_closed'), ENT_QUOTES, 'UTF-8') ?></p>
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
    ob_start(); ?>
<div class="row justify-content-center" style="margin-top:clamp(2rem,8vh,4rem)">
    <div class="col-12 col-sm-8 col-md-6 col-lg-4 text-center">
        <div class="eduqr-surface" style="padding:clamp(2rem,4vw,3rem)">
            <div class="eduqr-icon-badge mx-auto mb-3" style="width:3.2rem;height:3.2rem;border-radius:1.2rem"><?= eduqr_icon('clock') ?></div>
            <p class="fs-5 mb-0"><?= htmlspecialchars(t('session.paused_message'), ENT_QUOTES, 'UTF-8') ?></p>
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
        header('Location: ' . eduqr_path('/play/' . rawurlencode($shortCode)));
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
    header('Location: ' . eduqr_path('/play/' . rawurlencode($shortCode)));
    exit;
}

ob_start();
?>
<div class="eduqr-student-screen">
  <div class="row justify-content-center w-100 g-0">
    <div class="col-12 col-sm-10 col-md-7 col-lg-5 col-xl-4">

      <?php $courseTitle = trim((string) ($session['course_title'] ?? '')); ?>
      <div class="eduqr-join-hero mb-0">
        <div class="eduqr-join-avatar mx-auto mb-3">
          <?= htmlspecialchars($session['short_code'][0] ?? '?', ENT_QUOTES, 'UTF-8') ?>
        </div>
        <h1 class="h3 fw-bold mb-1"><?= htmlspecialchars($courseTitle !== '' ? $courseTitle : $session['title'], ENT_QUOTES, 'UTF-8') ?></h1>
        <?php if ($courseTitle !== ''): ?>
          <p class="text-muted mb-2"><?= htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <div class="d-flex justify-content-center">
          <span class="eduqr-chip"><?= eduqr_icon('qr') ?> <code><?= htmlspecialchars($session['short_code'], ENT_QUOTES, 'UTF-8') ?></code></span>
        </div>
      </div>

      <div class="eduqr-join-panel">
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
            <div class="invalid-feedback d-block mt-1" id="nickname-feedback" style="display:none"></div>
          </div>

          <button type="submit" id="join-btn" class="btn btn-primary btn-lg w-100">
            <?= htmlspecialchars(t('student.join.submit'), ENT_QUOTES, 'UTF-8') ?>
          </button>
        </form>

        <div class="mt-3 text-center">
          <small class="text-muted">
            <?= htmlspecialchars(t('student.join.return_desc'), ENT_QUOTES, 'UTF-8') ?>
          </small>
        </div>
      </div>

      <div class="mt-3 text-center">
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
        const res  = await fetch(eduqrPath('/api/v1/sessions/' + SHORT_CODE + '/join'), {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ nickname }),
        });
        const data = await res.json();

        if (data.success) {
            joinBtn.innerHTML = <?= json_encode(t('student.join.joining')) ?>;
            await new Promise(r => setTimeout(r, 400));
            window.location.href = eduqrPath('/join/' + SHORT_CODE + '/wait');
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
