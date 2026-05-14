<?php

use EduQR\Middleware\AuthMiddleware;
use EduQR\Middleware\CsrfMiddleware;
use EduQR\Repositories\CourseRepository;
use EduQR\Services\CourseService;

$instructor = AuthMiddleware::require();
$csrfToken  = CsrfMiddleware::getToken();
$courseId   = (int) ($p['id'] ?? 0);

$service = new CourseService(new CourseRepository());

try {
    $course = $service->getCourse($courseId, (int) $instructor['id']);
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
        &larr; <?= htmlspecialchars(t('common.back'), ENT_QUOTES, 'UTF-8') ?>
    </a>
</div>

<div class="row justify-content-center">
<div class="col-md-8 col-lg-6">
    <h2 class="mb-4"><?= htmlspecialchars(t('course.edit.title'), ENT_QUOTES, 'UTF-8') ?></h2>

    <div id="course-error"   class="alert alert-danger  d-none" role="alert"></div>
    <div id="course-success" class="alert alert-success d-none" role="status"></div>

    <form id="course-form" novalidate>
        <div class="mb-3">
            <label for="title" class="form-label">
                <?= htmlspecialchars(t('course.field.title'), ENT_QUOTES, 'UTF-8') ?>
                <span class="text-danger" aria-hidden="true">*</span>
            </label>
            <input type="text" id="title" name="title" class="form-control"
                   required maxlength="200"
                   value="<?= htmlspecialchars($course['title'], ENT_QUOTES, 'UTF-8') ?>">
        </div>

        <div class="mb-3">
            <label for="code" class="form-label">
                <?= htmlspecialchars(t('course.field.code'), ENT_QUOTES, 'UTF-8') ?>
            </label>
            <input type="text" id="code" name="code" class="form-control" maxlength="40"
                   value="<?= htmlspecialchars($course['code'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        </div>

        <div class="mb-3">
            <label for="semester" class="form-label">
                <?= htmlspecialchars(t('course.field.semester'), ENT_QUOTES, 'UTF-8') ?>
            </label>
            <input type="text" id="semester" name="semester" class="form-control" maxlength="40"
                   value="<?= htmlspecialchars($course['semester'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        </div>

        <div class="mb-3">
            <label for="description" class="form-label">
                <?= htmlspecialchars(t('course.field.description'), ENT_QUOTES, 'UTF-8') ?>
            </label>
            <textarea id="description" name="description" class="form-control" rows="3"><?= htmlspecialchars($course['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
        </div>

        <div class="mb-4">
            <label for="default_language" class="form-label">
                <?= htmlspecialchars(t('course.field.default_language'), ENT_QUOTES, 'UTF-8') ?>
            </label>
            <select id="default_language" name="default_language" class="form-select">
                <option value="en" <?= $course['default_language'] === 'en' ? 'selected' : '' ?>>English</option>
                <option value="tr" <?= $course['default_language'] === 'tr' ? 'selected' : '' ?>>Türkçe</option>
            </select>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">
                <?= htmlspecialchars(t('common.save'), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <a href="/admin/courses/<?= (int) $course['id'] ?>" class="btn btn-outline-secondary">
                <?= htmlspecialchars(t('common.cancel'), ENT_QUOTES, 'UTF-8') ?>
            </a>
        </div>
    </form>

    <?php if ($course['status'] === 'active'): ?>
    <hr class="my-4">
    <div class="border border-danger rounded p-3">
        <h6 class="text-danger"><?= htmlspecialchars(t('course.action.archive'), ENT_QUOTES, 'UTF-8') ?></h6>
        <p class="text-muted small mb-2"><?= htmlspecialchars(t('common.confirm'), ENT_QUOTES, 'UTF-8') ?></p>
        <button id="archive-btn" class="btn btn-outline-danger btn-sm">
            <?= htmlspecialchars(t('course.action.archive'), ENT_QUOTES, 'UTF-8') ?>
        </button>
    </div>
    <?php endif; ?>
</div>
</div>

<script>
const COURSE_ID   = <?= (int) $course['id'] ?>;
const CSRF_TOKEN  = <?= json_encode($csrfToken) ?>;
const MSG_ERROR   = <?= json_encode(t('common.error')) ?>;
const MSG_SERVER  = <?= json_encode(t('error.server_error')) ?>;
const MSG_UPDATED = <?= json_encode(t('course.updated')) ?>;

document.getElementById('course-form').addEventListener('submit', async function (e) {
    e.preventDefault();

    const errorEl   = document.getElementById('course-error');
    const successEl = document.getElementById('course-success');
    errorEl.classList.add('d-none');
    successEl.classList.add('d-none');

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
        const res  = await fetch('/api/v1/courses/' + COURSE_ID, {
            method:  'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': CSRF_TOKEN,
            },
            body: JSON.stringify(body),
        });
        const data = await res.json();

        if (data.success) {
            successEl.textContent = data.message || MSG_UPDATED;
            successEl.classList.remove('d-none');
        } else {
            errorEl.textContent = data.error?.message || MSG_ERROR;
            errorEl.classList.remove('d-none');
        }
    } catch {
        errorEl.textContent = MSG_SERVER;
        errorEl.classList.remove('d-none');
    } finally {
        btn.disabled = false;
    }
});

<?php if ($course['status'] === 'active'): ?>
document.getElementById('archive-btn').addEventListener('click', async function () {
    if (!confirm(<?= json_encode(t('common.confirm')) ?>)) return;

    const errorEl = document.getElementById('course-error');
    errorEl.classList.add('d-none');
    this.disabled = true;

    try {
        const res  = await fetch('/api/v1/courses/' + COURSE_ID, {
            method:  'DELETE',
            headers: { 'X-CSRF-Token': CSRF_TOKEN },
        });
        const data = await res.json();

        if (data.success) {
            window.location.href = '/admin/courses';
        } else {
            errorEl.textContent = data.error?.message || MSG_ERROR;
            errorEl.classList.remove('d-none');
            this.disabled = false;
        }
    } catch {
        errorEl.textContent = MSG_SERVER;
        errorEl.classList.remove('d-none');
        this.disabled = false;
    }
});
<?php endif; ?>
</script>
<?php
$content   = ob_get_clean();
$pageTitle = t('course.edit.title') . ' — ' . t('app.name');
include __DIR__ . '/../../layouts/admin.php';
