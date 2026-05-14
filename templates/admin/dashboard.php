<?php
use EduQR\Middleware\AuthMiddleware;
use EduQR\Middleware\CsrfMiddleware;

$instructor = AuthMiddleware::require();
$csrfToken  = CsrfMiddleware::getToken();

ob_start();
?>
<div class="row">
    <div class="col">
        <h2 class="mb-1"><?= htmlspecialchars(t('instructor.dashboard.title'), ENT_QUOTES, 'UTF-8') ?></h2>
        <p class="text-muted">
            <?= htmlspecialchars(t('instructor.dashboard.welcome', ['name' => $instructor['display_name']]), ENT_QUOTES, 'UTF-8') ?>
        </p>
    </div>
</div>

<hr>

<h5 class="mb-3"><?= htmlspecialchars(t('instructor.dashboard.recent_sessions'), ENT_QUOTES, 'UTF-8') ?></h5>
<p class="text-muted"><?= htmlspecialchars(t('instructor.dashboard.no_sessions'), ENT_QUOTES, 'UTF-8') ?></p>
<?php
$content   = ob_get_clean();
$pageTitle = t('instructor.dashboard.title') . ' — ' . t('app.name');
include __DIR__ . '/../layouts/admin.php';
