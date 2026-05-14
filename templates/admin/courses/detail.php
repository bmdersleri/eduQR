<?php

use EduQR\Middleware\AuthMiddleware;
use EduQR\Middleware\CsrfMiddleware;
use EduQR\Repositories\CourseRepository;
use EduQR\Repositories\SessionRepository;
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

$sessionRepo = new SessionRepository();
$sessions    = $sessionRepo->listByCourse($courseId);

ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <a href="/admin/courses" class="btn btn-outline-secondary btn-sm">
        &larr; <?= htmlspecialchars(t('common.back'), ENT_QUOTES, 'UTF-8') ?>
    </a>
    <a href="/admin/courses/<?= (int) $course['id'] ?>/edit" class="btn btn-outline-secondary btn-sm">
        <?= htmlspecialchars(t('common.edit'), ENT_QUOTES, 'UTF-8') ?>
    </a>
</div>

<h2>
    <?= htmlspecialchars($course['title'], ENT_QUOTES, 'UTF-8') ?>
    <?php if ($course['status'] === 'archived'): ?>
    <span class="badge text-bg-secondary ms-2 fs-6">
        <?= htmlspecialchars(t('course.archived_badge'), ENT_QUOTES, 'UTF-8') ?>
    </span>
    <?php endif; ?>
</h2>

<dl class="row mt-3 mb-4">
    <?php if ($course['code']): ?>
    <dt class="col-sm-3"><?= htmlspecialchars(t('course.field.code'),     ENT_QUOTES, 'UTF-8') ?></dt>
    <dd class="col-sm-9"><?= htmlspecialchars($course['code'],             ENT_QUOTES, 'UTF-8') ?></dd>
    <?php endif; ?>

    <?php if ($course['semester']): ?>
    <dt class="col-sm-3"><?= htmlspecialchars(t('course.field.semester'),  ENT_QUOTES, 'UTF-8') ?></dt>
    <dd class="col-sm-9"><?= htmlspecialchars($course['semester'],         ENT_QUOTES, 'UTF-8') ?></dd>
    <?php endif; ?>

    <?php if ($course['description']): ?>
    <dt class="col-sm-3"><?= htmlspecialchars(t('course.field.description'), ENT_QUOTES, 'UTF-8') ?></dt>
    <dd class="col-sm-9"><?= htmlspecialchars($course['description'],        ENT_QUOTES, 'UTF-8') ?></dd>
    <?php endif; ?>

    <dt class="col-sm-3"><?= htmlspecialchars(t('course.field.default_language'), ENT_QUOTES, 'UTF-8') ?></dt>
    <dd class="col-sm-9"><?= htmlspecialchars(strtoupper($course['default_language']), ENT_QUOTES, 'UTF-8') ?></dd>

    <dt class="col-sm-3"><?= htmlspecialchars(t('common.created_at'), ENT_QUOTES, 'UTF-8') ?></dt>
    <dd class="col-sm-9 text-muted"><?= htmlspecialchars(substr($course['created_at'], 0, 10), ENT_QUOTES, 'UTF-8') ?></dd>
</dl>

<hr>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><?= htmlspecialchars(t('nav.sessions'), ENT_QUOTES, 'UTF-8') ?></h5>
    <?php if ($course['status'] === 'active'): ?>
    <a href="/admin/courses/<?= (int) $course['id'] ?>/sessions/new" class="btn btn-primary btn-sm">
        + <?= htmlspecialchars(t('session.new.submit'), ENT_QUOTES, 'UTF-8') ?>
    </a>
    <?php endif; ?>
</div>

<?php if (empty($sessions)): ?>
<p class="text-muted"><?= htmlspecialchars(t('instructor.dashboard.no_sessions'), ENT_QUOTES, 'UTF-8') ?></p>
<?php else: ?>
<div class="table-responsive">
<table class="table table-hover align-middle">
    <thead class="table-light">
        <tr>
            <th><?= htmlspecialchars(t('session.new.field.title'), ENT_QUOTES, 'UTF-8') ?></th>
            <th><?= htmlspecialchars(t('session.short_code.label'), ENT_QUOTES, 'UTF-8') ?></th>
            <th><?= htmlspecialchars(t('common.status'), ENT_QUOTES, 'UTF-8') ?></th>
            <th><?= htmlspecialchars(t('common.created_at'), ENT_QUOTES, 'UTF-8') ?></th>
            <th></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($sessions as $s): ?>
        <?php
        $badgeClass = match ($s['status']) {
            'active' => 'text-bg-success',
            'paused' => 'text-bg-warning',
            default  => 'text-bg-secondary',
        };
        ?>
        <tr>
            <td><?= htmlspecialchars($s['title'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><code><?= htmlspecialchars($s['short_code'], ENT_QUOTES, 'UTF-8') ?></code></td>
            <td><span class="badge <?= $badgeClass ?>"><?= htmlspecialchars(t('session.status.' . $s['status']), ENT_QUOTES, 'UTF-8') ?></span></td>
            <td class="text-muted small"><?= htmlspecialchars(substr($s['created_at'], 0, 10), ENT_QUOTES, 'UTF-8') ?></td>
            <td class="text-end">
                <a href="/admin/sessions/<?= (int) $s['id'] ?>" class="btn btn-sm btn-outline-secondary">
                    <?= htmlspecialchars(t('common.actions'), ENT_QUOTES, 'UTF-8') ?>
                </a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
<?php
$content   = ob_get_clean();
$pageTitle = htmlspecialchars($course['title'], ENT_QUOTES, 'UTF-8') . ' — ' . t('app.name');
include __DIR__ . '/../../layouts/admin.php';
