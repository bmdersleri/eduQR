<?php
use EduQR\Middleware\CsrfMiddleware;

ob_start();
$csrfToken = CsrfMiddleware::getToken();
?>
<div class="row justify-content-center">
    <div class="col-sm-10 col-md-6 col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h1 class="h4 mb-4 text-primary"><?= htmlspecialchars(t('auth.login.title'), ENT_QUOTES, 'UTF-8') ?></h1>

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
            const res = await fetch('/api/v1/auth/login', {
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
                window.location.href = '/admin/dashboard';
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
