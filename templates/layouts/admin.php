<?php use EduQR\I18n\I18nService; ?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18nService::getLocale(), ENT_QUOTES, 'UTF-8') ?>" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title><?= htmlspecialchars($pageTitle ?? t('app.name'), ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="<?= htmlspecialchars(eduqr_path('/assets/css/bootstrap.min.css'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(eduqr_path('/assets/css/app.css'), ENT_QUOTES, 'UTF-8') ?>">
    <script>
    window.EDUQR_BASE_PATH = <?= json_encode(eduqr_base_path(), JSON_UNESCAPED_SLASHES) ?>;
    window.eduqrPath = function (path) {
        var basePath = window.EDUQR_BASE_PATH || '';
        var value = String(path || '');
        if (value === '') {
            return basePath || '/';
        }
        if (value.indexOf('http://') === 0 || value.indexOf('https://') === 0) {
            return value;
        }
        if (value.charAt(0) !== '/') {
            value = '/' + value;
        }
        return (basePath || '') + value;
    };
    </script>
    <script>
    // No-flash theme init: set data-theme before first paint.
    (function () {
        try {
            var t = localStorage.getItem('eduqr-theme');
            if (t !== 'dark' && t !== 'light') {
                t = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            }
            if (t === 'dark') { document.documentElement.setAttribute('data-theme', 'dark'); }
        } catch (e) {}
    })();
    </script>
</head>
<body class="eduqr-admin">

<nav class="navbar navbar-expand-lg eduqr-topbar px-3 px-lg-4">
    <a class="navbar-brand fw-bold" href="<?= htmlspecialchars(eduqr_path('/admin'), ENT_QUOTES, 'UTF-8') ?>">
        <span class="eduqr-icon-badge"><?= eduqr_icon('spark') ?></span>
        <span class="eduqr-brand-copy">
            <span><?= htmlspecialchars(t('app.name'), ENT_QUOTES, 'UTF-8') ?></span>
            <small><?= htmlspecialchars(t('app.subtitle'), ENT_QUOTES, 'UTF-8') ?></small>
        </span>
    </a>
    <div class="collapse navbar-collapse mx-3">
        <ul class="navbar-nav me-auto gap-1">
            <li class="nav-item">
                <a class="nav-link <?= str_contains($_SERVER['REQUEST_URI'] ?? '', '/admin/courses') ? 'active' : '' ?>"
                   href="<?= htmlspecialchars(eduqr_path('/admin/courses'), ENT_QUOTES, 'UTF-8') ?>">
                    <span class="me-1"><?= eduqr_icon('qr') ?></span>
                    <?= htmlspecialchars(t('nav.courses'), ENT_QUOTES, 'UTF-8') ?>
                </a>
            </li>
            <?php if (($instructor['role'] ?? '') === 'admin'): ?>
            <li class="nav-item">
                <a class="nav-link <?= str_contains($_SERVER['REQUEST_URI'] ?? '', '/admin/audit-logs') ? 'active' : '' ?>"
                   href="<?= htmlspecialchars(eduqr_path('/admin/audit-logs'), ENT_QUOTES, 'UTF-8') ?>">
                    <span class="me-1"><?= eduqr_icon('chart') ?></span>
                    <?= htmlspecialchars(t('nav.audit_logs'), ENT_QUOTES, 'UTF-8') ?>
                </a>
            </li>
            <?php endif; ?>
        </ul>
    </div>
    <div class="d-flex align-items-center gap-2 gap-lg-3 ms-auto flex-wrap justify-content-end">
        <?php include __DIR__ . '/../partials/theme-toggle.php'; ?>
        <?php include __DIR__ . '/../partials/language-switcher.php'; ?>
        <?php if (isset($instructor)): ?>
        <span class="eduqr-chip">
            <?= eduqr_icon('user') ?>
            <?= htmlspecialchars($instructor['display_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>
        </span>
        <form method="post" action="<?= htmlspecialchars(eduqr_path('/api/v1/auth/logout'), ENT_QUOTES, 'UTF-8') ?>" class="d-inline">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="btn btn-sm btn-outline-secondary">
                <?= htmlspecialchars(t('nav.logout'), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </form>
        <?php endif; ?>
    </div>
</nav>

<div class="container-lg py-4 py-lg-5 eduqr-shell">
    <?php if (!empty($flashMessage)): ?>
    <div class="alert alert-<?= htmlspecialchars($flashType ?? 'info', ENT_QUOTES, 'UTF-8') ?> alert-dismissible fade show">
        <?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?= $content ?? '' ?>
</div>

<script src="<?= htmlspecialchars(eduqr_path('/assets/js/bootstrap.bundle.min.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script type="module" src="<?= htmlspecialchars(eduqr_path('/assets/js/app.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>
