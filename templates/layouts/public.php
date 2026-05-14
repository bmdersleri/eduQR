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
<body class="eduqr-public bg-light">

<nav class="navbar navbar-light bg-white border-bottom py-2 px-3">
    <span class="navbar-brand fw-bold"><?= t('app.name') ?></span>
    <?php include __DIR__ . '/../partials/language-switcher.php'; ?>
</nav>

<main class="container-fluid py-4">
    <?= $content ?? '' ?>
</main>

<footer class="border-top mt-auto py-3 text-center text-muted small">
    <?php include __DIR__ . '/../partials/privacy-notice.php'; ?>
</footer>

<script src="/assets/js/bootstrap.bundle.min.js"></script>
<script type="module" src="/assets/js/app.js"></script>
</body>
</html>
