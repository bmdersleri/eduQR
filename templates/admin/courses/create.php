<div class="eduqr-admin-hero mb-4">
    <div>
        <div class="eduqr-kicker mb-3">
            <span class="eduqr-icon-badge"><?= eduqr_icon('spark') ?></span>
            <span><?= htmlspecialchars(t('course.new.title'), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <h1 class="h2 mb-2"><?= htmlspecialchars(t('course.new.title'), ENT_QUOTES, 'UTF-8') ?></h1>
        <p><?= htmlspecialchars(t('home.workflow.desc'), ENT_QUOTES, 'UTF-8') ?></p>
    </div>
    <a href="<?= htmlspecialchars(eduqr_path('/admin/courses'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary btn-sm">
        <?= eduqr_icon('user') ?> <?= htmlspecialchars(t('common.back'), ENT_QUOTES, 'UTF-8') ?>
    </a>
</div>

<div class="row justify-content-center">
<div class="col-xl-8 col-lg-9">
    <div class="eduqr-form-shell">
        <div id="course-error" class="alert alert-danger d-none mb-4" role="alert"></div>

        <form id="course-form" novalidate class="eduqr-form-grid">
            <div class="eduqr-form-grid two-col">
                <div class="eduqr-form-field">
                    <label for="title">
                        <?= htmlspecialchars(t('course.field.title'), ENT_QUOTES, 'UTF-8') ?>
                        <span class="text-danger" aria-hidden="true">*</span>
                    </label>
                    <input type="text" id="title" name="title" class="form-control"
                           required maxlength="200" autocomplete="off">
                </div>

                <div class="eduqr-form-field">
                    <label for="default_language">
                        <?= htmlspecialchars(t('course.field.default_language'), ENT_QUOTES, 'UTF-8') ?>
                    </label>
                    <select id="default_language" name="default_language" class="form-select">
                        <option value="en">English</option>
                        <option value="tr">Türkçe</option>
                    </select>
                </div>

                <div class="eduqr-form-field">
                    <label for="code">
                        <?= htmlspecialchars(t('course.field.code'), ENT_QUOTES, 'UTF-8') ?>
                    </label>
                    <input type="text" id="code" name="code" class="form-control" maxlength="40">
                </div>

                <div class="eduqr-form-field">
                    <label for="semester">
                        <?= htmlspecialchars(t('course.field.semester'), ENT_QUOTES, 'UTF-8') ?>
                    </label>
                    <input type="text" id="semester" name="semester" class="form-control" maxlength="40">
                </div>
            </div>

            <div class="eduqr-form-field">
                <label for="description">
                    <?= htmlspecialchars(t('course.field.description'), ENT_QUOTES, 'UTF-8') ?>
                </label>
                <textarea id="description" name="description" class="form-control" rows="4"></textarea>
            </div>

            <div class="d-flex gap-2 flex-wrap pt-2">
                <button type="submit" class="btn btn-primary">
                    <?= htmlspecialchars(t('course.action.create'), ENT_QUOTES, 'UTF-8') ?>
                </button>
                <a href="<?= htmlspecialchars(eduqr_path('/admin/courses'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary">
                    <?= htmlspecialchars(t('common.cancel'), ENT_QUOTES, 'UTF-8') ?>
                </a>
            </div>
        </form>
    </div>
</div>
</div>

<script>
document.getElementById('course-form').addEventListener('submit', async function (e) {
    e.preventDefault();

    const errorEl = document.getElementById('course-error');
    errorEl.classList.add('d-none');

    const btn = this.querySelector('[type=submit]');
    btn.disabled = true;

    const body = {
        title:            this.title.value.trim(),
        code:             this.code.value.trim()        || null,
        semester:         this.semester.value.trim()    || null,
        description:      this.description.value.trim() || null,
        default_language: this.default_language.value,
    };

    try {
        const res  = await fetch(eduqrPath('/api/v1/courses'), {
            method:  'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': <?= json_encode($csrfToken) ?>,
            },
            body: JSON.stringify(body),
        });
        const data = await res.json();

        if (data.success) {
            window.location.href = eduqrPath('/admin/courses/' + data.data.id);
        } else {
            errorEl.textContent = data.error?.message || <?= json_encode(t('common.error')) ?>;
            errorEl.classList.remove('d-none');
        }
    } catch {
        errorEl.textContent = <?= json_encode(t('error.server_error')) ?>;
        errorEl.classList.remove('d-none');
    } finally {
        btn.disabled = false;
    }
});
</script>
