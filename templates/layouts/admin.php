<?php use EduQR\I18n\I18nService; ?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18nService::getLocale(), ENT_QUOTES, 'UTF-8') ?>" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title><?= htmlspecialchars($pageTitle ?? t('app.name'), ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="eduqr-admin">

<nav class="navbar navbar-expand navbar-dark bg-dark px-3">
    <a class="navbar-brand fw-bold" href="/admin"><?= t('app.name') ?></a>
    <div class="collapse navbar-collapse mx-3">
        <ul class="navbar-nav me-auto">
            <li class="nav-item">
                <a class="nav-link <?= str_contains($_SERVER['REQUEST_URI'] ?? '', '/admin/courses') ? 'active' : '' ?>"
                   href="/admin/courses">
                    <?= htmlspecialchars(t('nav.courses'), ENT_QUOTES, 'UTF-8') ?>
                </a>
            </li>
        </ul>
    </div>
    <div class="d-flex align-items-center gap-3">
        <?php include __DIR__ . '/../partials/language-switcher.php'; ?>
        <?php if (isset($instructor)): ?>
        <span class="text-white-50 small"><?= htmlspecialchars($instructor['display_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
        <form method="post" action="/api/v1/auth/logout" class="d-inline">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="btn btn-sm btn-outline-light">
                <?= htmlspecialchars(t('nav.logout'), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </form>
        <?php endif; ?>
    </div>
</nav>

<div class="container-lg py-4">
    <?php if (!empty($flashMessage)): ?>
    <div class="alert alert-<?= htmlspecialchars($flashType ?? 'info', ENT_QUOTES, 'UTF-8') ?> alert-dismissible fade show">
        <?= htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?= $content ?? '' ?>
</div>

<script src="/assets/js/bootstrap.bundle.min.js"></script>
<script type="module" src="/assets/js/app.js"></script>
</body>
</html>
