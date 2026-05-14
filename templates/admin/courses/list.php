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
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><?= htmlspecialchars(t('course.list.title'), ENT_QUOTES, 'UTF-8') ?></h2>
    <a href="/admin/courses/new" class="btn btn-primary">
        + <?= htmlspecialchars(t('course.action.create'), ENT_QUOTES, 'UTF-8') ?>
    </a>
</div>

<?php if (empty($courses)): ?>
<p class="text-muted"><?= htmlspecialchars(t('course.list.empty'), ENT_QUOTES, 'UTF-8') ?></p>
<?php else: ?>
<div class="table-responsive">
    <table class="table table-hover align-middle">
        <thead class="table-light">
            <tr>
                <th><?= htmlspecialchars(t('course.field.title'),    ENT_QUOTES, 'UTF-8') ?></th>
                <th><?= htmlspecialchars(t('course.field.code'),     ENT_QUOTES, 'UTF-8') ?></th>
                <th><?= htmlspecialchars(t('course.field.semester'), ENT_QUOTES, 'UTF-8') ?></th>
                <th><?= htmlspecialchars(t('common.status'),         ENT_QUOTES, 'UTF-8') ?></th>
                <th><?= htmlspecialchars(t('common.created_at'),     ENT_QUOTES, 'UTF-8') ?></th>
                <th><?= htmlspecialchars(t('common.actions'),        ENT_QUOTES, 'UTF-8') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($courses as $course): ?>
            <tr>
                <td>
                    <a href="/admin/courses/<?= (int) $course['id'] ?>" class="fw-medium text-decoration-none">
                        <?= htmlspecialchars($course['title'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                    <?php if ($course['status'] === 'archived'): ?>
                    <span class="badge text-bg-secondary ms-1">
                        <?= htmlspecialchars(t('course.archived_badge'), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                    <?php endif; ?>
                </td>
                <td class="text-muted"><?= htmlspecialchars($course['code'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                <td class="text-muted"><?= htmlspecialchars($course['semester'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($course['status'], ENT_QUOTES, 'UTF-8') ?></td>
                <td class="text-muted small"><?= htmlspecialchars(substr($course['created_at'], 0, 10), ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                    <a href="/admin/courses/<?= (int) $course['id'] ?>/edit"
                       class="btn btn-sm btn-outline-secondary">
                        <?= htmlspecialchars(t('common.edit'), ENT_QUOTES, 'UTF-8') ?>
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
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
