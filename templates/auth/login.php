<?php
use EduQR\Middleware\CsrfMiddleware;

ob_start();
$csrfToken = CsrfMiddleware::getToken();
?>
<div class="row justify-content-center align-items-stretch g-4 py-4 py-lg-5">
    <div class="col-12 col-lg-5 d-none d-lg-block">
        <div class="eduqr-hero h-100">
            <div class="eduqr-kicker">
                <span class="eduqr-icon-badge"><?= eduqr_icon('shield') ?></span>
                <span><?= htmlspecialchars(t('auth.login.title'), ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <h1 class="display-5 fw-bold mb-3"><?= htmlspecialchars(t('app.name'), ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="lead text-muted mb-4">
                <?= htmlspecialchars(t('app.tagline'), ENT_QUOTES, 'UTF-8') ?>
            </p>
            <div class="eduqr-panel-grid">
                <div class="eduqr-statcard">
                    <div class="d-flex align-items-center gap-2 mb-2"><?= eduqr_icon('qr') ?><strong><?= htmlspecialchars(t('auth.login.title'), ENT_QUOTES, 'UTF-8') ?></strong></div>
                    <p class="text-muted mb-0"><?= htmlspecialchars(t('home.status_ready'), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div class="eduqr-statcard">
                    <div class="d-flex align-items-center gap-2 mb-2"><?= eduqr_icon('chart') ?><strong><?= htmlspecialchars(t('results.title'), ENT_QUOTES, 'UTF-8') ?></strong></div>
                    <p class="text-muted mb-0"><?= htmlspecialchars(t('auth.login.analytics_desc'), ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-10 col-md-6 col-lg-4">
        <div class="eduqr-surface h-100">
            <div class="p-4 p-lg-5">
                <div class="d-flex align-items-center justify-content-between mb-4">
                    <div>
                        <p class="eduqr-kicker mb-2">
                            <span class="eduqr-icon-badge"><?= eduqr_icon('spark') ?></span>
                            <span><?= htmlspecialchars(t('auth.login.title'), ENT_QUOTES, 'UTF-8') ?></span>
                        </p>
                        <h2 class="h4 mb-0"><?= htmlspecialchars(t('auth.login.title'), ENT_QUOTES, 'UTF-8') ?></h2>
                    </div>
                    <span class="eduqr-chip"><?= eduqr_icon('shield') ?> <?= htmlspecialchars(t('auth.login.secure_badge'), ENT_QUOTES, 'UTF-8') ?></span>
                </div>

                <div id="login-error" class="alert alert-danger d-none" role="alert"></div>

                <form id="login-form" novalidate>
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                    <div class="mb-3">
                        <label for="email" class="form-label">
                            <?= htmlspecialchars(t('auth.login.email'), ENT_QUOTES, 'UTF-8') ?>
                        </label>
                        <input type="email" id="email" name="email"
                               class="form-control" autocomplete="email"
                               inputmode="email" required autofocus>
                    </div>

                    <div class="mb-4">
                        <label for="password" class="form-label">
                            <?= htmlspecialchars(t('auth.login.password'), ENT_QUOTES, 'UTF-8') ?>
                        </label>
                        <input type="password" id="password" name="password"
                               class="form-control" autocomplete="current-password" required>
                    </div>

                    <div class="mb-3 text-end">
                        <a href="<?= htmlspecialchars(eduqr_path('/forgot-password'), ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars(t('auth.reset.forgot_link'), ENT_QUOTES, 'UTF-8') ?>
                        </a>
                    </div>

                    <button type="submit" id="login-btn" class="btn btn-primary w-100">
                        <?= htmlspecialchars(t('auth.login.submit'), ENT_QUOTES, 'UTF-8') ?>
                    </button>
                </form>

                <div class="mt-3 text-end">
                    <?php include __DIR__ . '/../partials/language-switcher.php'; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    const form  = document.getElementById('login-form');
    const btn   = document.getElementById('login-btn');
    const error = document.getElementById('login-error');

    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        error.classList.add('d-none');
        btn.disabled = true;

        const csrf = form.querySelector('[name="_csrf"]').value;

        try {
            const res = await fetch(eduqrPath('/api/v1/auth/login'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrf,
                },
                body: JSON.stringify({
                    email:    document.getElementById('email').value.trim(),
                    password: document.getElementById('password').value,
                }),
            });

            const data = await res.json();

            if (res.ok && data.success) {
                window.location.href = eduqrPath('/admin/dashboard');
            } else {
                error.textContent = data.error?.message ?? <?= json_encode(t('common.error')) ?>;
                error.classList.remove('d-none');
                btn.disabled = false;
            }
        } catch (_) {
            error.textContent = <?= json_encode(t('common.error')) ?>;
            error.classList.remove('d-none');
            btn.disabled = false;
        }
    });
}());
</script>
<?php
$content   = ob_get_clean();
$pageTitle = t('auth.login.title') . ' — ' . t('app.name');
include __DIR__ . '/../layouts/public.php';
