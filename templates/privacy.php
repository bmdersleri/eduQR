<?php
// Full privacy notice — public page linked from every student-facing screen [FR-75, NFR-31]
ob_start();
?>
<div class="row justify-content-center">
    <div class="col-12 col-md-10 col-lg-8 col-xl-7">
        <div class="eduqr-surface" style="padding:clamp(1.5rem,4vw,3rem)">

            <div class="d-flex align-items-center gap-3 mb-3">
                <span class="eduqr-icon-badge" style="width:3.2rem;height:3.2rem;border-radius:1.2rem"><?= eduqr_icon('shield') ?></span>
                <h1 class="h3 mb-0"><?= htmlspecialchars(t('privacy.page.title'), ENT_QUOTES, 'UTF-8') ?></h1>
            </div>

            <p class="text-muted"><?= htmlspecialchars(t('privacy.notice.body'), ENT_QUOTES, 'UTF-8') ?></p>

            <h2 class="h5 mt-4"><?= htmlspecialchars(t('privacy.page.collected.title'), ENT_QUOTES, 'UTF-8') ?></h2>
            <ul class="mb-0">
                <li><?= htmlspecialchars(t('privacy.page.collected.nickname'), ENT_QUOTES, 'UTF-8') ?></li>
                <li><?= htmlspecialchars(t('privacy.page.collected.answers'), ENT_QUOTES, 'UTF-8') ?></li>
                <li><?= htmlspecialchars(t('privacy.page.collected.reactions'), ENT_QUOTES, 'UTF-8') ?></li>
                <li><?= htmlspecialchars(t('privacy.page.collected.device'), ENT_QUOTES, 'UTF-8') ?></li>
                <li><?= htmlspecialchars(t('privacy.page.collected.cookies'), ENT_QUOTES, 'UTF-8') ?></li>
            </ul>

            <h2 class="h5 mt-4"><?= htmlspecialchars(t('privacy.page.not_collected.title'), ENT_QUOTES, 'UTF-8') ?></h2>
            <ul class="mb-0">
                <li><?= htmlspecialchars(t('privacy.page.not_collected.account'), ENT_QUOTES, 'UTF-8') ?></li>
                <li><?= htmlspecialchars(t('privacy.page.not_collected.identity'), ENT_QUOTES, 'UTF-8') ?></li>
                <li><?= htmlspecialchars(t('privacy.page.not_collected.ip'), ENT_QUOTES, 'UTF-8') ?></li>
            </ul>

            <h2 class="h5 mt-4"><?= htmlspecialchars(t('privacy.page.who.title'), ENT_QUOTES, 'UTF-8') ?></h2>
            <ul class="mb-0">
                <li><?= htmlspecialchars(t('privacy.page.who.instructor'), ENT_QUOTES, 'UTF-8') ?></li>
                <li><?= htmlspecialchars(t('privacy.page.who.reports'), ENT_QUOTES, 'UTF-8') ?></li>
                <li><?= htmlspecialchars(t('privacy.page.who.reactions'), ENT_QUOTES, 'UTF-8') ?></li>
                <li><?= htmlspecialchars(t('privacy.page.who.class'), ENT_QUOTES, 'UTF-8') ?></li>
                <li><?= htmlspecialchars(t('privacy.page.who.never'), ENT_QUOTES, 'UTF-8') ?></li>
            </ul>

            <h2 class="h5 mt-4"><?= htmlspecialchars(t('privacy.page.retention.title'), ENT_QUOTES, 'UTF-8') ?></h2>
            <ul class="mb-0">
                <li><?= htmlspecialchars(t('privacy.page.retention.active'), ENT_QUOTES, 'UTF-8') ?></li>
                <li><?= htmlspecialchars(t('privacy.page.retention.auto'), ENT_QUOTES, 'UTF-8') ?></li>
                <li><?= htmlspecialchars(t('privacy.page.retention.anonymize'), ENT_QUOTES, 'UTF-8') ?></li>
                <li><?= htmlspecialchars(t('privacy.page.retention.instructor'), ENT_QUOTES, 'UTF-8') ?></li>
            </ul>

            <h2 class="h5 mt-4"><?= htmlspecialchars(t('privacy.page.options.title'), ENT_QUOTES, 'UTF-8') ?></h2>
            <ul class="mb-0">
                <li><?= htmlspecialchars(t('privacy.page.options.nickname'), ENT_QUOTES, 'UTF-8') ?></li>
                <li><?= htmlspecialchars(t('privacy.page.options.contact'), ENT_QUOTES, 'UTF-8') ?></li>
            </ul>

            <div class="alert alert-warning mt-4 mb-0" role="note">
                <strong><?= htmlspecialchars(t('privacy.page.review.title'), ENT_QUOTES, 'UTF-8') ?></strong>
                <p class="mb-0 mt-1"><?= htmlspecialchars(t('privacy.page.review.body'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>

        </div>
    </div>
</div>
<?php
$content   = ob_get_clean();
$pageTitle = t('privacy.page.title') . ' — ' . t('app.name');
include __DIR__ . '/layouts/public.php';
