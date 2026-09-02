<?php

use EduQR\Middleware\AuthMiddleware;
use EduQR\Middleware\CsrfMiddleware;
use EduQR\Repositories\CourseRepository;
use EduQR\Repositories\UserRepository;
use EduQR\Services\CourseService;

$instructor = AuthMiddleware::require();
$csrfToken  = CsrfMiddleware::getToken();
$courseId   = (int) ($p['id'] ?? 0);

$service = new CourseService(new CourseRepository(), new UserRepository());

try {
    $course = $service->getCourse($courseId, (int) $instructor['id']);
} catch (\RuntimeException $e) {
    $status = $e->getMessage() === 'course_not_found' ? 404 : 403;
    http_response_code($status);
    include __DIR__ . '/../../../templates/errors/' . $status . '.php';
    exit;
}

// Course instructors (FR-97). The mutating controls are rendered only for the
// owner; the API enforces the same rule regardless of what is rendered here.
$courseInstructors = $service->listInstructors($courseId, (int) $instructor['id']);
$isCourseOwner     = false;

foreach ($courseInstructors as $courseInstructor) {
    if ($courseInstructor['user_id'] === (int) $instructor['id']
        && $courseInstructor['role'] === CourseService::ROLE_OWNER) {
        $isCourseOwner = true;
        break;
    }
}

ob_start();
?>
<div class="eduqr-admin-hero mb-4">
    <div>
        <div class="eduqr-kicker mb-3">
            <span class="eduqr-icon-badge"><?= eduqr_icon('spark') ?></span>
            <span><?= htmlspecialchars(t('course.edit.title'), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <h1 class="h2 mb-2"><?= htmlspecialchars($course['title'], ENT_QUOTES, 'UTF-8') ?></h1>
        <p><?= htmlspecialchars($course['description'] ?: t('course.list.empty'), ENT_QUOTES, 'UTF-8') ?></p>
    </div>
    <a href="<?= htmlspecialchars(eduqr_path('/admin/courses/' . (int) $course['id']), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary btn-sm">
        <?= eduqr_icon('user') ?> <?= htmlspecialchars(t('common.back'), ENT_QUOTES, 'UTF-8') ?>
    </a>
</div>

<div class="row g-4 justify-content-center">
<div class="col-xl-8 col-lg-9">
    <div class="eduqr-form-shell">
        <div id="course-error" class="alert alert-danger d-none mb-4" role="alert"></div>
        <div id="course-success" class="alert alert-success d-none mb-4" role="status"></div>

        <form id="course-form" novalidate class="eduqr-form-grid">
            <div class="eduqr-form-grid two-col">
                <div class="eduqr-form-field">
                    <label for="title">
                        <?= htmlspecialchars(t('course.field.title'), ENT_QUOTES, 'UTF-8') ?>
                        <span class="text-danger" aria-hidden="true">*</span>
                    </label>
                    <input type="text" id="title" name="title" class="form-control"
                           required maxlength="200"
                           value="<?= htmlspecialchars($course['title'], ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div class="eduqr-form-field">
                    <label for="default_language">
                        <?= htmlspecialchars(t('course.field.default_language'), ENT_QUOTES, 'UTF-8') ?>
                    </label>
                    <select id="default_language" name="default_language" class="form-select">
                        <option value="en" <?= $course['default_language'] === 'en' ? 'selected' : '' ?>>English</option>
                        <option value="tr" <?= $course['default_language'] === 'tr' ? 'selected' : '' ?>>Türkçe</option>
                    </select>
                </div>

                <div class="eduqr-form-field">
                    <label for="code"><?= htmlspecialchars(t('course.field.code'), ENT_QUOTES, 'UTF-8') ?></label>
                    <input type="text" id="code" name="code" class="form-control" maxlength="40"
                           value="<?= htmlspecialchars($course['code'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div class="eduqr-form-field">
                    <label for="semester"><?= htmlspecialchars(t('course.field.semester'), ENT_QUOTES, 'UTF-8') ?></label>
                    <input type="text" id="semester" name="semester" class="form-control" maxlength="40"
                           value="<?= htmlspecialchars($course['semester'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
            </div>

            <div class="eduqr-form-field">
                <label for="description"><?= htmlspecialchars(t('course.field.description'), ENT_QUOTES, 'UTF-8') ?></label>
                <textarea id="description" name="description" class="form-control" rows="4"><?= htmlspecialchars($course['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>

            <div class="d-flex gap-2 flex-wrap pt-2">
                <button type="submit" class="btn btn-primary">
                    <?= htmlspecialchars(t('common.save'), ENT_QUOTES, 'UTF-8') ?>
                </button>
                <a href="<?= htmlspecialchars(eduqr_path('/admin/courses/' . (int) $course['id']), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary">
                    <?= htmlspecialchars(t('common.cancel'), ENT_QUOTES, 'UTF-8') ?>
                </a>
            </div>
        </form>
    </div>

    <div class="eduqr-form-shell mt-4">
        <div class="eduqr-section-head">
            <h2 class="h4 mb-0"><?= htmlspecialchars(t('course.instructors.title'), ENT_QUOTES, 'UTF-8') ?></h2>
        </div>
        <p class="text-muted"><?= htmlspecialchars(t('course.instructors.subtitle'), ENT_QUOTES, 'UTF-8') ?></p>

        <div id="instructor-error" class="alert alert-danger d-none mb-3" role="alert"></div>
        <div id="instructor-success" class="alert alert-success d-none mb-3" role="status"></div>

        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col"><?= htmlspecialchars(t('course.instructors.column.name'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th scope="col"><?= htmlspecialchars(t('course.instructors.column.role'), ENT_QUOTES, 'UTF-8') ?></th>
                        <?php if ($isCourseOwner): ?>
                        <th scope="col" class="text-end"><?= htmlspecialchars(t('common.actions'), ENT_QUOTES, 'UTF-8') ?></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($courseInstructors as $courseInstructor): ?>
                    <tr>
                        <td>
                            <div><?= htmlspecialchars($courseInstructor['display_name'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="meta"><?= htmlspecialchars($courseInstructor['email'], ENT_QUOTES, 'UTF-8') ?></div>
                        </td>
                        <td>
                            <span class="badge <?= $courseInstructor['role'] === CourseService::ROLE_OWNER ? 'text-bg-primary' : 'text-bg-secondary' ?>">
                                <?= htmlspecialchars(t('course.instructors.role.' . $courseInstructor['role']), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </td>
                        <?php if ($isCourseOwner): ?>
                        <td class="text-end">
                            <?php if ($courseInstructor['role'] !== CourseService::ROLE_OWNER): ?>
                            <button type="button" class="btn btn-outline-danger btn-sm instructor-remove-btn"
                                    data-user-id="<?= (int) $courseInstructor['user_id'] ?>">
                                <?= htmlspecialchars(t('course.instructors.remove'), ENT_QUOTES, 'UTF-8') ?>
                            </button>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if (count($courseInstructors) < 2): ?>
        <p class="text-muted mt-3 mb-0"><?= htmlspecialchars(t('course.instructors.empty'), ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <?php if ($isCourseOwner): ?>
        <form id="instructor-form" novalidate class="eduqr-form-grid mt-4">
            <div class="eduqr-form-field">
                <label for="instructor_email">
                    <?= htmlspecialchars(t('course.instructors.add.email'), ENT_QUOTES, 'UTF-8') ?>
                </label>
                <input type="email" id="instructor_email" name="instructor_email" class="form-control"
                       required maxlength="190" autocomplete="off">
                <small class="text-muted"><?= htmlspecialchars(t('course.instructors.add.hint'), ENT_QUOTES, 'UTF-8') ?></small>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <button type="submit" class="btn btn-primary btn-sm">
                    <?= htmlspecialchars(t('course.instructors.add.submit'), ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <?php if ($course['status'] === 'active' && $isCourseOwner): ?>
    <div class="eduqr-danger-surface mt-4">
        <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
            <div>
                <h2 class="h5 text-danger mb-1"><?= htmlspecialchars(t('course.action.archive'), ENT_QUOTES, 'UTF-8') ?></h2>
                <p class="text-muted mb-0"><?= htmlspecialchars(t('common.confirm'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <button id="archive-btn" class="btn btn-outline-danger btn-sm">
                <?= htmlspecialchars(t('course.action.archive'), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
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
        const res  = await fetch(eduqrPath('/api/v1/courses/' + COURSE_ID), {
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

<?php if ($course['status'] === 'active' && $isCourseOwner): ?>
document.getElementById('archive-btn').addEventListener('click', async function () {
    if (!confirm(<?= json_encode(t('common.confirm')) ?>)) return;

    const errorEl = document.getElementById('course-error');
    errorEl.classList.add('d-none');
    this.disabled = true;

    try {
        const res  = await fetch(eduqrPath('/api/v1/courses/' + COURSE_ID), {
            method:  'DELETE',
            headers: { 'X-CSRF-Token': CSRF_TOKEN },
        });
        const data = await res.json();

        if (data.success) {
            window.location.href = eduqrPath('/admin/courses');
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

<?php if ($isCourseOwner): ?>
// Course instructors (FR-97) — owner-only controls.
const MSG_INSTRUCTOR_ADDED   = <?= json_encode(t('course.instructor.added')) ?>;
const MSG_INSTRUCTOR_REMOVED = <?= json_encode(t('course.instructor.removed')) ?>;
const MSG_REMOVE_CONFIRM     = <?= json_encode(t('course.instructors.remove_confirm')) ?>;

function instructorFeedback(message, ok) {
    const errorEl   = document.getElementById('instructor-error');
    const successEl = document.getElementById('instructor-success');
    errorEl.classList.add('d-none');
    successEl.classList.add('d-none');

    const target = ok ? successEl : errorEl;
    target.textContent = message;
    target.classList.remove('d-none');
}

document.getElementById('instructor-form').addEventListener('submit', async function (e) {
    e.preventDefault();

    const btn = this.querySelector('[type=submit]');
    btn.disabled = true;

    try {
        const res = await fetch(eduqrPath('/api/v1/courses/' + COURSE_ID + '/instructors'), {
            method:  'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': CSRF_TOKEN,
            },
            body: JSON.stringify({ email: this.instructor_email.value.trim() }),
        });
        const data = await res.json();

        if (data.success) {
            instructorFeedback(data.message || MSG_INSTRUCTOR_ADDED, true);
            setTimeout(() => window.location.reload(), 500);
            return;
        }

        instructorFeedback(data.error?.message || MSG_ERROR, false);
    } catch {
        instructorFeedback(MSG_SERVER, false);
    } finally {
        btn.disabled = false;
    }
});

document.querySelectorAll('.instructor-remove-btn').forEach(function (button) {
    button.addEventListener('click', async function () {
        if (!confirm(MSG_REMOVE_CONFIRM)) return;

        this.disabled = true;

        try {
            const url = eduqrPath('/api/v1/courses/' + COURSE_ID + '/instructors/' + this.dataset.userId);
            const res = await fetch(url, {
                method:  'DELETE',
                headers: { 'X-CSRF-Token': CSRF_TOKEN },
            });
            const data = await res.json();

            if (data.success) {
                instructorFeedback(data.message || MSG_INSTRUCTOR_REMOVED, true);
                setTimeout(() => window.location.reload(), 500);
                return;
            }

            instructorFeedback(data.error?.message || MSG_ERROR, false);
            this.disabled = false;
        } catch {
            instructorFeedback(MSG_SERVER, false);
            this.disabled = false;
        }
    });
});
<?php endif; ?>
</script>
<?php
$content   = ob_get_clean();
$pageTitle = t('course.edit.title') . ' — ' . t('app.name');
include __DIR__ . '/../../layouts/admin.php';
