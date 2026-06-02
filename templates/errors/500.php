<!DOCTYPE html>
<html lang="<?= function_exists('t') ? htmlspecialchars(\EduQR\I18n\I18nService::getLocale(), ENT_QUOTES, 'UTF-8') : 'en' ?>" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= function_exists('t') ? htmlspecialchars(t('error.server_error'), ENT_QUOTES, 'UTF-8') : 'eduQR' ?> — eduQR</title>
    <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="eduqr-public">
<main class="eduqr-error-page">
    <section class="eduqr-surface eduqr-error-card">
        <div class="eduqr-icon-badge mx-auto mb-3"><?= function_exists('eduqr_icon') ? eduqr_icon('spark') : '' ?></div>
        <div class="eduqr-kicker mb-3 justify-content-center">
            <span><?= function_exists('t') ? htmlspecialchars(t('app.name'), ENT_QUOTES, 'UTF-8') : 'eduQR' ?></span>
        </div>
        <h1 class="h3 mb-3"><?= function_exists('t') ? htmlspecialchars(t('error.server_error'), ENT_QUOTES, 'UTF-8') : 'eduQR' ?></h1>
        <p class="mb-4"><?= function_exists('t') ? htmlspecialchars(t('error.server_error'), ENT_QUOTES, 'UTF-8') : 'eduQR' ?></p>
        <a href="/" class="btn btn-primary"><?= function_exists('t') ? htmlspecialchars(t('common.back'), ENT_QUOTES, 'UTF-8') : 'eduQR' ?></a>
    </section>
</main>
</body>
</html>
