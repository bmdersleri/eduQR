<button type="button"
        class="theme-toggle"
        data-theme-toggle
        data-theme-label
        data-label-light="<?= htmlspecialchars(t('theme.switch_to_light'), ENT_QUOTES, 'UTF-8') ?>"
        data-label-dark="<?= htmlspecialchars(t('theme.switch_to_dark'), ENT_QUOTES, 'UTF-8') ?>">
    <?= eduqr_icon('spark') ?>
    <span><?= htmlspecialchars(t('theme.switch_to_light'), ENT_QUOTES, 'UTF-8') ?></span>
</button>
