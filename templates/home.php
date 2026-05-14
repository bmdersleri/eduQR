<?php
ob_start();
?>
<div class="d-flex flex-column align-items-center justify-content-center py-5">
    <h1 class="display-5 fw-bold text-primary"><?= htmlspecialchars(t('app.name'), ENT_QUOTES, 'UTF-8') ?></h1>
    <p class="lead text-muted"><?= htmlspecialchars(t('app.tagline'), ENT_QUOTES, 'UTF-8') ?></p>
    <span class="badge bg-success-subtle text-success mt-2">Phase 1 — i18n running</span>
    <p class="mt-4">
        <a href="/login" class="btn btn-primary"><?= htmlspecialchars(t('auth.login.submit'), ENT_QUOTES, 'UTF-8') ?> →</a>
    </p>
</div>
<?php
$content   = ob_get_clean();
$pageTitle = t('app.name') . ' — ' . t('app.subtitle');
include __DIR__ . '/layouts/public.php';
