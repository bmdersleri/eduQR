<?php use EduQR\I18n\I18nService; ?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18nService::getLocale(), ENT_QUOTES, 'UTF-8') ?>" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex,nofollow">
    <title><?= htmlspecialchars($pageTitle ?? t('app.name'), ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="preload" href="/assets/fonts/plus-jakarta-sans-800.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="eduqr-public">

<div class="eduqr-orbs" aria-hidden="true">
    <span class="o1"></span>
    <span class="o2"></span>
    <span class="o3"></span>
</div>

<nav class="navbar navbar-expand-lg eduqr-topbar px-3 px-lg-4">
    <a class="navbar-brand fw-bold" href="/">
        <span class="eduqr-icon-badge"><?= eduqr_icon('qr') ?></span>
        <span class="eduqr-brand-copy">
            <span><?= htmlspecialchars(t('app.name'), ENT_QUOTES, 'UTF-8') ?></span>
            <small><?= htmlspecialchars(t('app.tagline'), ENT_QUOTES, 'UTF-8') ?></small>
        </span>
    </a>
    <div class="d-flex align-items-center gap-2 ms-auto flex-wrap justify-content-end">
        <?php include __DIR__ . '/../partials/theme-toggle.php'; ?>
        <?php include __DIR__ . '/../partials/language-switcher.php'; ?>
    </div>
</nav>

<main class="container-fluid py-4 py-lg-5 eduqr-shell">
    <?= $content ?? '' ?>
</main>

<footer class="border-top mt-auto py-3 text-center text-muted small">
    <?php include __DIR__ . '/../partials/privacy-notice.php'; ?>
</footer>

<div id="eduqr-toasts" class="eduqr-toasts" aria-live="polite" aria-atomic="true"></div>

<script src="/assets/js/bootstrap.bundle.min.js"></script>
<script type="module" src="/assets/js/app.js"></script>
<script>
// Global toast helper
function eduqrToast(message, type) {
    const container = document.getElementById('eduqr-toasts');
    const el = document.createElement('div');
    el.className = 'eduqr-toast eduqr-toast--' + (type || 'ok');
    el.textContent = message;
    container.appendChild(el);
    setTimeout(() => { el.style.opacity = '0'; el.style.transform = 'scale(.9)'; el.style.transition = '.25s ease'; setTimeout(() => el.remove(), 300); }, 3500);
}
</script>
</body>
</html>
