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
<div class="dropdown d-inline-block">
    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button"
            data-bs-toggle="dropdown" aria-expanded="false">
        <?= htmlspecialchars($locales[$currentLocale] ?? strtoupper($currentLocale), ENT_QUOTES, 'UTF-8') ?>
    </button>
    <ul class="dropdown-menu dropdown-menu-end">
        <?php foreach ($locales as $code => $label): ?>
        <li>
            <a class="dropdown-item <?= $code === $currentLocale ? 'active' : '' ?>"
               href="<?= htmlspecialchars($currentUri . $baseQuery . 'lang=' . $code, ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>
</div>
