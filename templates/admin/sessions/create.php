<?php

use EduQR\Middleware\AuthMiddleware;
use EduQR\Middleware\CsrfMiddleware;
use EduQR\Repositories\CourseRepository;
use EduQR\Services\CourseService;

$instructor = AuthMiddleware::require();
$csrfToken  = CsrfMiddleware::getToken();
$courseId   = (int) ($p['id'] ?? 0);

$courseService = new CourseService(new CourseRepository());

try {
    $course = $courseService->getCourse($courseId, (int) $instructor['id']);
} catch (\RuntimeException $e) {
    $status = $e->getMessage() === 'course_not_found' ? 404 : 403;
    http_response_code($status);
    include __DIR__ . '/../../../templates/errors/' . $status . '.php';
    exit;
}

ob_start();
?>
<div class="mb-4">
    <a href="/admin/courses/<?= (int) $course['id'] ?>" class="btn btn-outline-secondary btn-sm">
        &larr; <?= htmlspecialchars($course['title'], ENT_QUOTES, 'UTF-8') ?>
    </a>
</div>

<div class="row justify-content-center">
<div class="col-md-8 col-lg-6">
    <h2 class="mb-4"><?= htmlspecialchars(t('session.new.title'), ENT_QUOTES, 'UTF-8') ?></h2>

    <div id="session-error" class="alert alert-danger d-none" role="alert"></div>

    <form id="session-form" novalidate>
        <div class="mb-3">
            <label for="title" class="form-label">
                <?= htmlspecialchars(t('session.new.field.title'), ENT_QUOTES, 'UTF-8') ?>
                <span class="text-danger" aria-hidden="true">*</span>
            </label>
            <input type="text" id="title" name="title" class="form-control"
                   required maxlength="200"
                   placeholder="<?= htmlspecialchars($course['title'], ENT_QUOTES, 'UTF-8') ?>">
        </div>

        <div class="mb-4">
            <label for="language" class="form-label">
                <?= htmlspecialchars(t('session.new.field.language'), ENT_QUOTES, 'UTF-8') ?>
            </label>
            <select id="language" name="language" class="form-select">
                <option value="en" <?= $course['default_language'] === 'en' ? 'selected' : '' ?>>English</option>
                <option value="tr" <?= $course['default_language'] === 'tr' ? 'selected' : '' ?>>Türkçe</option>
            </select>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">
                <?= htmlspecialchars(t('session.new.submit'), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <a href="/admin/courses/<?= (int) $course['id'] ?>" class="btn btn-outline-secondary">
                <?= htmlspecialchars(t('common.cancel'), ENT_QUOTES, 'UTF-8') ?>
            </a>
        </div>
    </form>
</div>
</div>

<script>
const COURSE_ID  = <?= (int) $course['id'] ?>;
const CSRF_TOKEN = <?= json_encode($csrfToken) ?>;
const MSG_ERROR  = <?= json_encode(t('common.error')) ?>;
const MSG_SERVER = <?= json_encode(t('error.server_error')) ?>;

document.getElementById('session-form').addEventListener('submit', async function (e) {
    e.preventDefault();

    const errorEl = document.getElementById('session-error');
    errorEl.classList.add('d-none');

    const btn = this.querySelector('[type=submit]');
    btn.disabled = true;

    const body = {
        title:    this.title.value.trim(),
        language: this.language.value,
    };

    try {
        const res  = await fetch('/api/v1/courses/' + COURSE_ID + '/sessions', {
            method:  'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': CSRF_TOKEN,
            },
            body: JSON.stringify(body),
        });
        const data = await res.json();

        if (data.success) {
            window.location.href = '/admin/sessions/' + data.data.id;
        } else {
            errorEl.textContent = data.error?.message || MSG_ERROR;
            errorEl.classList.remove('d-none');
            btn.disabled = false;
        }
    } catch {
        errorEl.textContent = MSG_SERVER;
        errorEl.classList.remove('d-none');
        btn.disabled = false;
    }
});
</script>
<?php
$content   = ob_get_clean();
$pageTitle = t('session.new.title') . ' — ' . t('app.name');
include __DIR__ . '/../../layouts/admin.php';
