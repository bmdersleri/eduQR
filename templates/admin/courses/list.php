<?php

use EduQR\Middleware\AuthMiddleware;
use EduQR\Middleware\CsrfMiddleware;
use EduQR\Repositories\CourseRepository;
use EduQR\Services\CourseService;

$instructor = AuthMiddleware::require();
$csrfToken  = CsrfMiddleware::getToken();

$service = new CourseService(new CourseRepository());
$page    = max(1, (int) ($_GET['page'] ?? 1));
$result  = $service->listMyCourses((int) $instructor['id'], $page, 20);
$courses = $result['data'];
$meta    = $result['meta'];

ob_start();
?>
<div class="eduqr-admin-hero mb-4">
    <div>
        <div class="eduqr-kicker mb-3">
            <span class="eduqr-icon-badge"><?= eduqr_icon('qr') ?></span>
            <span><?= htmlspecialchars(t('course.list.title'), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <h1 class="h2 mb-2"><?= htmlspecialchars(t('course.list.title'), ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="text-muted mb-0"><?= htmlspecialchars(t('instructor.dashboard.welcome', ['name' => $instructor['display_name']]), ENT_QUOTES, 'UTF-8') ?></p>
    </div>
    <a href="/admin/courses/new" class="btn btn-primary align-self-start">
        <?= eduqr_icon('spark') ?> <?= htmlspecialchars(t('course.action.create'), ENT_QUOTES, 'UTF-8') ?>
    </a>
</div>

<?php if (empty($courses)): ?>
<div class="eduqr-surface eduqr-empty-state text-start">
    <span class="eduqr-icon-badge mb-3"><?= eduqr_icon('qr') ?></span>
    <h2 class="h5 mb-2"><?= htmlspecialchars(t('course.list.empty'), ENT_QUOTES, 'UTF-8') ?></h2>
    <p class="text-muted mb-0"><?= htmlspecialchars(t('course.list.empty'), ENT_QUOTES, 'UTF-8') ?></p>
</div>
<?php else: ?>
<div class="eduqr-card-list">
    <?php foreach ($courses as $course): ?>
    <div class="eduqr-card-row">
        <div>
            <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                <a href="/admin/courses/<?= (int) $course['id'] ?>" class="h5 mb-0 text-decoration-none">
                    <?= htmlspecialchars($course['title'], ENT_QUOTES, 'UTF-8') ?>
                </a>
                <?php if ($course['status'] === 'archived'): ?>
                <span class="badge text-bg-secondary">
                    <?= htmlspecialchars(t('course.archived_badge'), ENT_QUOTES, 'UTF-8') ?>
                </span>
                <?php endif; ?>
            </div>
            <div class="d-flex flex-wrap gap-2 meta">
                <span class="eduqr-chip"><code><?= htmlspecialchars($course['code'] ?? '', ENT_QUOTES, 'UTF-8') ?></code></span>
                <span class="eduqr-chip"><?= htmlspecialchars($course['semester'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                <span class="eduqr-chip"><?= htmlspecialchars($course['status'], ENT_QUOTES, 'UTF-8') ?></span>
                <span class="eduqr-chip"><?= htmlspecialchars(substr($course['created_at'], 0, 10), ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap justify-content-end">
            <a href="/admin/courses/<?= (int) $course['id'] ?>" class="btn btn-outline-secondary btn-sm">
                <?= eduqr_icon('chart') ?> <?= htmlspecialchars(t('common.actions'), ENT_QUOTES, 'UTF-8') ?>
            </a>
            <a href="/admin/courses/<?= (int) $course['id'] ?>/edit" class="btn btn-primary btn-sm">
                <?= eduqr_icon('spark') ?> <?= htmlspecialchars(t('common.edit'), ENT_QUOTES, 'UTF-8') ?>
            </a>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php if ($meta['total'] > $meta['per_page']): ?>
<nav aria-label="Course pagination">
    <ul class="pagination">
        <?php
        $totalPages = (int) ceil($meta['total'] / $meta['per_page']);
        for ($i = 1; $i <= $totalPages; $i++):
        ?>
        <li class="page-item <?= $i === $meta['page'] ? 'active' : '' ?>">
            <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
        </li>
        <?php endfor; ?>
    </ul>
</nav>
<?php endif; ?>
<?php endif; ?>
<?php
$content   = ob_get_clean();
$pageTitle = t('course.list.title') . ' — ' . t('app.name');
include __DIR__ . '/../../layouts/admin.php';
