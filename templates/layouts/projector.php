<?php use EduQR\I18n\I18nService; ?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(I18nService::getLocale(), ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title><?= htmlspecialchars($pageTitle ?? t('app.name'), ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="<?= htmlspecialchars(eduqr_path('/assets/css/bootstrap.min.css'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(eduqr_path('/assets/css/app.css'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(eduqr_path('/assets/css/projector.css'), ENT_QUOTES, 'UTF-8') ?>">
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
</head>
<body class="eduqr-projector" style="font-size:clamp(1.4rem,3vw,2.5rem)">

<div class="container-fluid px-4 py-3">
    <?= $content ?? '' ?>
</div>

<script type="module" src="<?= htmlspecialchars(eduqr_path('/assets/js/projector.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>
