<!DOCTYPE html>
<html lang="<?= function_exists('t') ? htmlspecialchars(\EduQR\I18n\I18nService::getLocale(), ENT_QUOTES, 'UTF-8') : 'en' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error — eduQR</title>
    <style>
        body { font-family: system-ui, sans-serif; display: flex; align-items: center;
               justify-content: center; min-height: 100vh; margin: 0; background: #f8f9fa; }
        .box { text-align: center; padding: 2rem; max-width: 400px; }
        h1 { font-size: 1.5rem; color: #dc3545; }
        p  { color: #6c757d; }
    </style>
</head>
<body>
<div class="box">
    <h1><?= function_exists('t') ? htmlspecialchars(t('error.server_error'), ENT_QUOTES, 'UTF-8') : 'Something went wrong' ?></h1>
    <p><a href="/"><?= function_exists('t') ? htmlspecialchars(t('common.back'), ENT_QUOTES, 'UTF-8') : 'Go to home page' ?></a></p>
</div>
</body>
</html>
