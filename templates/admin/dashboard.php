<?php
use EduQR\Container;
use EduQR\Middleware\AuthMiddleware;
use EduQR\Middleware\CsrfMiddleware;

$instructor = AuthMiddleware::require();
$csrfToken  = CsrfMiddleware::getToken();

$courseService = Container::courseService();
$coursesData   = $courseService->listMyCourses((int) $instructor['id'], 1, 3);
$recentCourses = $coursesData['data'] ?? [];

ob_start();
?>
<div class="eduqr-admin-hero mb-4">
    <div>
        <div class="eduqr-kicker mb-3">
            <span class="eduqr-icon-badge"><?= eduqr_icon('spark') ?></span>
            <span><?= htmlspecialchars(t('instructor.dashboard.title'), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <h1 class="h2 mb-2"><?= htmlspecialchars(t('instructor.dashboard.welcome', ['name' => $instructor['display_name']]), ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="text-muted mb-0"><?= htmlspecialchars(t('app.tagline'), ENT_QUOTES, 'UTF-8') ?></p>
    </div>
    <a href="<?= htmlspecialchars(eduqr_path('/admin/courses/new'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-primary align-self-start">
        <?= eduqr_icon('spark') ?> <?= htmlspecialchars(t('course.action.create'), ENT_QUOTES, 'UTF-8') ?>
    </a>
</div>

<div class="eduqr-admin-grid mb-4">
    <div class="eduqr-data-card">
        <div class="label"><?= htmlspecialchars(t('instructor.dashboard.recent_sessions'), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="value"><?= count($recentCourses) ?></div>
        <div class="hint"><?= htmlspecialchars(t('course.list.title'), ENT_QUOTES, 'UTF-8') ?></div>
    </div>
    <div class="eduqr-data-card">
        <div class="label"><?= htmlspecialchars(t('session.action.view_live'), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="value">QR</div>
        <div class="hint"><?= htmlspecialchars(t('session.qr.title'), ENT_QUOTES, 'UTF-8') ?></div>
    </div>
    <div class="eduqr-data-card">
        <div class="label"><?= htmlspecialchars(t('results.title'), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="value"><?= htmlspecialchars(t('instructor.dashboard.live_badge'), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="hint"><?= htmlspecialchars(t('instructor.dashboard.results_hint'), ENT_QUOTES, 'UTF-8') ?></div>
    </div>
</div>

<div class="eduqr-section-head">
    <h2 class="h4 mb-0"><?= htmlspecialchars(t('instructor.dashboard.recent_sessions'), ENT_QUOTES, 'UTF-8') ?></h2>
</div>

<?php if (empty($recentCourses)): ?>
<div class="eduqr-surface eduqr-empty-state text-start">
    <span class="eduqr-icon-badge mb-3"><?= eduqr_icon('clock') ?></span>
    <p class="text-muted mb-0"><?= htmlspecialchars(t('instructor.dashboard.no_sessions'), ENT_QUOTES, 'UTF-8') ?></p>
</div>
<?php else: ?>
<div class="eduqr-card-list">
    <?php foreach ($recentCourses as $course): ?>
    <div class="eduqr-card-row">
        <div>
            <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                <a href="<?= htmlspecialchars(eduqr_path('/admin/courses/' . (int) $course['id']), ENT_QUOTES, 'UTF-8') ?>" class="h5 mb-0 text-decoration-none">
                    <?= htmlspecialchars($course['title'], ENT_QUOTES, 'UTF-8') ?>
                </a>
                <?php if ($course['status'] === 'archived'): ?>
                <span class="badge text-bg-secondary"><?= htmlspecialchars(t('course.archived_badge'), ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
            </div>
            <div class="d-flex flex-wrap gap-2 meta">
                <span class="eduqr-chip"><code><?= htmlspecialchars($course['code'] ?? '', ENT_QUOTES, 'UTF-8') ?></code></span>
                <span class="eduqr-chip"><?= htmlspecialchars(t('course.status.' . $course['status']), ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= htmlspecialchars(eduqr_path('/admin/courses/' . (int) $course['id']), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary btn-sm">
                <?= eduqr_icon('chart') ?> <?= htmlspecialchars(t('common.actions'), ENT_QUOTES, 'UTF-8') ?>
            </a>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php
$content   = ob_get_clean();
$pageTitle = t('instructor.dashboard.title') . ' — ' . t('app.name');
include __DIR__ . '/../layouts/admin.php';
