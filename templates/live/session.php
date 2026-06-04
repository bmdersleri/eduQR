<?php

use EduQR\Repositories\CourseRepository;
use EduQR\Repositories\SessionRepository;
use EduQR\Services\SessionService;

$shortCode = strtoupper(trim($p['short_code'] ?? ''));

$service = new SessionService(new SessionRepository(), new CourseRepository());

try {
    $sessionData = $service->resolveByShortCode($shortCode);
} catch (\RuntimeException) {
    http_response_code(404);
    include __DIR__ . '/../../templates/errors/404.php';
    exit;
}

$joinUrl = eduqr_url('/join/' . $sessionData['short_code']);

// Need session id for the QR endpoint — look up directly for the QR url
$repo      = new SessionRepository();
$rawSession = $repo->findByShortCode($shortCode);
$sessionId  = $rawSession ? (int) $rawSession['id'] : 0;
$qrUrl      = eduqr_path('/api/v1/sessions/' . $sessionId . '/qr.png?size=600');

ob_start();
?>
<div class="row align-items-center" style="min-height:90vh">
    <div class="col-md-6 text-center mb-4 mb-md-0">
        <?php if ($sessionId > 0): ?>
        <img src="<?= htmlspecialchars($qrUrl, ENT_QUOTES, 'UTF-8') ?>"
             alt="QR Code"
             class="img-fluid"
             style="max-width:500px;background:#fff;padding:1rem;border-radius:1.5rem;box-shadow:0 24px 60px rgba(0,0,0,.24)">
        <?php endif; ?>
    </div>

    <div class="col-md-6 ps-md-5">
        <div class="eduqr-projector-surface p-4 p-lg-5">
            <div class="eduqr-kicker mb-3">
                <span class="eduqr-icon-badge"><?= eduqr_icon('qr') ?></span>
                <span><?= htmlspecialchars($sessionData['course_title'], ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <h1 class="display-4 fw-bold mb-4" style="font-size:clamp(1.8rem,4vw,3.5rem)">
                <?= htmlspecialchars($sessionData['title'], ENT_QUOTES, 'UTF-8') ?>
            </h1>

            <div class="mb-4">
                <p class="text-white-50 mb-1" style="font-size:clamp(0.9rem,1.5vw,1.1rem)">
                    <?= htmlspecialchars(t('session.short_code.label'), ENT_QUOTES, 'UTF-8') ?>
                </p>
                <p class="fw-bold text-warning mb-0" style="font-size:clamp(3rem,8vw,6rem);letter-spacing:0.3em;font-family:monospace">
                    <?= htmlspecialchars($sessionData['short_code'], ENT_QUOTES, 'UTF-8') ?>
                </p>
            </div>

            <div>
                <p class="text-white-50 mb-1" style="font-size:clamp(0.9rem,1.5vw,1.1rem)">
                    <?= htmlspecialchars(t('session.join_url.label'), ENT_QUOTES, 'UTF-8') ?>
                </p>
                <p class="fw-semibold mb-0" style="font-size:clamp(1rem,2vw,1.4rem);word-break:break-all">
                    <?= htmlspecialchars($joinUrl, ENT_QUOTES, 'UTF-8') ?>
                </p>
            </div>
        </div>

        <?php if ($sessionData['status'] === 'paused'): ?>
        <div class="alert alert-warning mt-4">
            <?= htmlspecialchars(t('session.paused_message'), ENT_QUOTES, 'UTF-8') ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php
$content   = ob_get_clean();
$pageTitle = htmlspecialchars($sessionData['title'], ENT_QUOTES, 'UTF-8') . ' — ' . t('app.name');
include __DIR__ . '/../../templates/layouts/projector.php';
