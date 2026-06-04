<?php
// Privacy notice — shown on every student-facing page [FR-75]
?>
<a href="<?= htmlspecialchars(eduqr_path('/privacy'), ENT_QUOTES, 'UTF-8') ?>" class="text-muted text-decoration-none small">
    <?= htmlspecialchars(t('privacy.notice.link'), ENT_QUOTES, 'UTF-8') ?>
</a>
