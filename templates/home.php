<div class="row align-items-center justify-content-center g-4 g-lg-5 py-4 py-lg-5">
    <div class="col-12 col-lg-6">
        <div class="eduqr-hero h-100">
            <div class="eduqr-kicker">
                <span class="eduqr-icon-badge"><?= eduqr_icon('spark') ?></span>
                <span><?= htmlspecialchars(t('home.status_ready'), ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <h1 class="display-4 fw-bold mb-3"><?= htmlspecialchars(t('app.name'), ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="lead text-muted mb-4" style="max-width:32rem">
                <?= htmlspecialchars(t('app.tagline'), ENT_QUOTES, 'UTF-8') ?>
            </p>
            <div class="eduqr-feature-grid mb-4">
                <div class="eduqr-feature">
                    <span class="eduqr-icon-badge"><?= eduqr_icon('qr') ?></span>
                    <div>
                        <strong><?= htmlspecialchars(t('home.feature.qr_title'), ENT_QUOTES, 'UTF-8') ?></strong>
                        <span><?= htmlspecialchars(t('home.feature.qr_desc'), ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                </div>
                <div class="eduqr-feature">
                    <span class="eduqr-icon-badge"><?= eduqr_icon('chart') ?></span>
                    <div>
                        <strong><?= htmlspecialchars(t('home.feature.analytics_title'), ENT_QUOTES, 'UTF-8') ?></strong>
                        <span><?= htmlspecialchars(t('home.feature.analytics_desc'), ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                </div>
            </div>
            <a href="<?= htmlspecialchars(eduqr_path('/login'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-primary btn-lg px-4">
                <?= htmlspecialchars(t('auth.login.submit'), ENT_QUOTES, 'UTF-8') ?>
                <span class="ms-1"><?= eduqr_icon('spark') ?></span>
            </a>
        </div>
    </div>
    <div class="col-12 col-lg-4">
        <div class="eduqr-surface p-4 p-lg-5 h-100">
            <div class="d-flex align-items-center gap-3 mb-3">
                <span class="eduqr-icon-badge" style="width:3.2rem;height:3.2rem"><?= eduqr_icon('qr') ?></span>
                <div>
                    <h2 class="h4 mb-1"><?= htmlspecialchars(t('home.workflow.title'), ENT_QUOTES, 'UTF-8') ?></h2>
                    <p class="text-muted mb-0"><?= htmlspecialchars(t('home.workflow.desc'), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </div>
            <ul class="feature-list">
                <li>
                    <span class="eduqr-icon-badge"><?= eduqr_icon('user') ?></span>
                    <div>
                        <strong><?= htmlspecialchars(t('home.workflow.nickname_title'), ENT_QUOTES, 'UTF-8') ?></strong>
                        <span><?= htmlspecialchars(t('home.workflow.nickname_desc'), ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                </li>
                <li>
                    <span class="eduqr-icon-badge"><?= eduqr_icon('check') ?></span>
                    <div>
                        <strong><?= htmlspecialchars(t('home.workflow.fast_title'), ENT_QUOTES, 'UTF-8') ?></strong>
                        <span><?= htmlspecialchars(t('home.workflow.fast_desc'), ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                </li>
                <li>
                    <span class="eduqr-icon-badge"><?= eduqr_icon('shield') ?></span>
                    <div>
                        <strong><?= htmlspecialchars(t('home.workflow.private_title'), ENT_QUOTES, 'UTF-8') ?></strong>
                        <span><?= htmlspecialchars(t('home.workflow.private_desc'), ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                </li>
            </ul>
        </div>
    </div>
</div>
