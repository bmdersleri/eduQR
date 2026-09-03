<div class="row justify-content-center align-items-stretch g-4 py-4 py-lg-5">
    <div class="col-12 col-lg-5 d-none d-lg-block">
        <div class="eduqr-hero h-100">
            <div class="eduqr-kicker">
                <span class="eduqr-icon-badge"><?= eduqr_icon('shield') ?></span>
                <span><?= htmlspecialchars(t('auth.reset.confirm_title'), ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <h1 class="display-5 fw-bold mb-3"><?= htmlspecialchars(t('auth.reset.confirm_title'), ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="lead text-muted mb-0"><?= htmlspecialchars(t('auth.reset.confirm_description'), ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    </div>
    <div class="col-sm-10 col-md-7 col-lg-5">
        <div class="eduqr-surface h-100">
            <div class="p-4 p-lg-5">
                <p class="eduqr-kicker mb-2">
                    <span class="eduqr-icon-badge"><?= eduqr_icon('spark') ?></span>
                    <span><?= htmlspecialchars(t('auth.reset.confirm_title'), ENT_QUOTES, 'UTF-8') ?></span>
                </p>
                <h2 class="h4 mb-3"><?= htmlspecialchars(t('auth.reset.confirm_title'), ENT_QUOTES, 'UTF-8') ?></h2>

                <div id="reset-message" class="alert d-none" role="alert"></div>

                <form id="reset-confirm-form" novalidate>
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">

                    <div class="mb-3">
                        <label for="password" class="form-label">
                            <?= htmlspecialchars(t('auth.reset.new_password'), ENT_QUOTES, 'UTF-8') ?>
                        </label>
                        <input type="password" id="password" name="password" class="form-control" autocomplete="new-password" required>
                    </div>

                    <div class="mb-4">
                        <label for="password_confirmation" class="form-label">
                            <?= htmlspecialchars(t('auth.reset.confirm_password'), ENT_QUOTES, 'UTF-8') ?>
                        </label>
                        <input type="password" id="password_confirmation" name="password_confirmation" class="form-control" autocomplete="new-password" required>
                    </div>

                    <button type="submit" id="reset-btn" class="btn btn-primary w-100">
                        <?= htmlspecialchars(t('auth.reset.submit_confirm'), ENT_QUOTES, 'UTF-8') ?>
                    </button>
                </form>

                <div class="mt-3 text-end">
                    <a href="<?= htmlspecialchars(eduqr_path('/login'), ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars(t('auth.reset.back_to_login'), ENT_QUOTES, 'UTF-8') ?>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const form = document.getElementById('reset-confirm-form');
    const btn = document.getElementById('reset-btn');
    const message = document.getElementById('reset-message');

    function showMessage(text, type) {
        message.textContent = text;
        message.className = 'alert alert-' + type;
        message.classList.remove('d-none');
    }

    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        message.classList.add('d-none');
        btn.disabled = true;

        try {
            const res = await fetch(eduqrPath('/api/v1/auth/password-reset/confirm'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': form.querySelector('[name="_csrf"]').value,
                },
                body: JSON.stringify({
                    token: document.querySelector('[name="token"]').value,
                    password: document.getElementById('password').value,
                    password_confirmation: document.getElementById('password_confirmation').value,
                }),
            });

            const data = await res.json();
            if (res.ok && data.success) {
                showMessage(data.message || <?= json_encode(t('auth.reset.success')) ?>, 'success');
                setTimeout(() => { window.location.href = eduqrPath('/login'); }, 1200);
            } else {
                showMessage(data.error?.message || <?= json_encode(t('common.error')) ?>, 'danger');
                btn.disabled = false;
            }
        } catch (_) {
            showMessage(<?= json_encode(t('common.error')) ?>, 'danger');
            btn.disabled = false;
        }
    });
}());
</script>
