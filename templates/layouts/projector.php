<?php use EduQR\I18n\I18nService; ?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18nService::getLocale(), ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title><?= htmlspecialchars($pageTitle ?? t('app.name'), ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/css/projector.css">
</head>
<body class="eduqr-projector bg-dark text-white" style="font-size:clamp(1.4rem,3vw,2.5rem)">

<div class="container-fluid px-4 py-3">
    <?= $content ?? '' ?>
</div>

<script type="module" src="/assets/js/projector.js"></script>
</body>
</html>
