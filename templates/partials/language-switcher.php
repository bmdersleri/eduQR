<?php
// Language switcher — reads active locale from I18nService
use EduQR\I18n\I18nService;

$currentLocale = I18nService::getLocale();
$locales = [
    'en' => 'English',
    'tr' => 'Türkçe',
];
// Build base URL without existing ?lang= param
$currentUri  = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
$queryParams = $_GET;
unset($queryParams['lang']);
$baseQuery   = $queryParams ? '?' . http_build_query($queryParams) . '&' : '?';
?>
<div class="eduqr-lang-switch" role="group" aria-label="<?= htmlspecialchars(t('common.language'), ENT_QUOTES, 'UTF-8') ?>">
    <?php foreach ($locales as $code => $label): ?>
        <a class="eduqr-lang-opt<?= $code === $currentLocale ? ' is-active' : '' ?>"
           href="<?= htmlspecialchars($currentUri . $baseQuery . 'lang=' . $code, ENT_QUOTES, 'UTF-8') ?>"
           <?= $code === $currentLocale ? 'aria-current="true"' : '' ?>>
            <?= htmlspecialchars(strtoupper($code), ENT_QUOTES, 'UTF-8') ?>
        </a>
    <?php endforeach; ?>
</div>
