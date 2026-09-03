<?php

declare(strict_types=1);

use EduQR\Middleware\AuthMiddleware;
use EduQR\Middleware\CsrfMiddleware;
use EduQR\Repositories\CourseRepository;
use EduQR\Repositories\OptionRepository;
use EduQR\Repositories\QuestionRepository;
use EduQR\Repositories\SessionRepository;
use EduQR\Services\ReportService;

$instructor = AuthMiddleware::require();
$csrfToken = CsrfMiddleware::getToken();
$courseId = (int) ($p['id'] ?? 0);

$reportService = new ReportService(
    new SessionRepository(),
    new QuestionRepository(),
    new OptionRepository(),
    new CourseRepository(),
);

try {
    $analytics = $reportService->buildCourseAnalytics($courseId, (int) $instructor['id']);
} catch (\RuntimeException $e) {
    $status = $e->getMessage() === 'course_not_found' ? 404 : 403;
    http_response_code($status);
    include __DIR__ . '/../../../templates/errors/' . $status . '.php';
    exit;
}

$course = $analytics['course'];
$summary = $analytics['summary'];
$sessions = $analytics['sessions'];
$questionTypeBreakdown = array_filter(
    $analytics['question_type_breakdown'],
    static fn (array $row): bool => (int) $row['count'] > 0
);

// FR-85: locale-aware percent — tr renders "%83,4", en renders "83.4%".
$formatRate = static function (float $rate): string {
    return fmt_percent($rate * 100);
};

ob_start();
?>
<div class="eduqr-admin-hero mb-4">
    <div>
        <div class="eduqr-kicker mb-3">
            <span class="eduqr-icon-badge"><?= eduqr_icon('chart') ?></span>
            <span><?= htmlspecialchars(t('course.analytics.title'), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
            <h1 class="h2 mb-0"><?= htmlspecialchars($course['title'], ENT_QUOTES, 'UTF-8') ?></h1>
            <span class="badge <?= $course['status'] === 'archived' ? 'text-bg-secondary' : 'text-bg-success' ?>">
                <?= htmlspecialchars($course['status'] === 'archived' ? t('course.archived_badge') : t('session.status.active'), ENT_QUOTES, 'UTF-8') ?>
            </span>
        </div>
        <p class="text-muted mb-0"><?= htmlspecialchars(t('course.analytics.subtitle'), ENT_QUOTES, 'UTF-8') ?></p>
    </div>
    <div class="d-flex align-items-start gap-2 flex-wrap">
        <a href="<?= htmlspecialchars(eduqr_path('/admin/courses/' . (int) $course['id']), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary btn-sm">
            <?= eduqr_icon('user') ?> <?= htmlspecialchars(t('common.back'), ENT_QUOTES, 'UTF-8') ?>
        </a>
        <a href="<?= htmlspecialchars(eduqr_path('/api/v1/courses/' . (int) $course['id'] . '/analytics'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-primary btn-sm" target="_blank" rel="noopener">
            <?= eduqr_icon('spark') ?> <?= htmlspecialchars(t('course.analytics.open_json'), ENT_QUOTES, 'UTF-8') ?>
        </a>
    </div>
</div>

<div class="eduqr-admin-grid mb-4">
    <div class="eduqr-data-card">
        <div class="label"><?= htmlspecialchars(t('course.analytics.session_count'), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="value"><?= (int) $summary['session_count'] ?></div>
    </div>
    <div class="eduqr-data-card">
        <div class="label"><?= htmlspecialchars(t('course.analytics.closed_session_count'), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="value"><?= (int) $summary['closed_session_count'] ?></div>
    </div>
    <div class="eduqr-data-card">
        <div class="label"><?= htmlspecialchars(t('report.participant_count'), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="value"><?= (int) $summary['participant_count'] ?></div>
    </div>
    <div class="eduqr-data-card">
        <div class="label"><?= htmlspecialchars(t('report.question_count'), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="value"><?= (int) $summary['question_count'] ?></div>
    </div>
    <div class="eduqr-data-card">
        <div class="label"><?= htmlspecialchars(t('report.answer_count'), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="value"><?= (int) $summary['answer_count'] ?></div>
    </div>
    <div class="eduqr-data-card">
        <div class="label"><?= htmlspecialchars(t('course.analytics.average_participation_rate'), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="value"><?= htmlspecialchars($formatRate((float) $summary['average_participation_rate']), ENT_QUOTES, 'UTF-8') ?></div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-4">
        <div class="eduqr-surface h-100">
            <div class="eduqr-section-head mb-3">
                <h2 class="h5 mb-0"><?= htmlspecialchars(t('course.analytics.last_session_at'), ENT_QUOTES, 'UTF-8') ?></h2>
            </div>
            <div class="display-6 mb-0">
                <?php /* FR-85: locale-aware date, not the raw SQL timestamp. */ ?>
                <?= htmlspecialchars(
                    isset($summary['last_session_at']) ? fmt_date($summary['last_session_at']) : t('course.analytics.no_last_session'),
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="eduqr-surface h-100">
            <div class="eduqr-section-head mb-3">
                <h2 class="h5 mb-0"><?= htmlspecialchars(t('course.analytics.question_types'), ENT_QUOTES, 'UTF-8') ?></h2>
            </div>
            <?php if (empty($questionTypeBreakdown)): ?>
            <p class="text-muted mb-0"><?= htmlspecialchars(t('course.analytics.empty'), ENT_QUOTES, 'UTF-8') ?></p>
            <?php else: ?>
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($questionTypeBreakdown as $row): ?>
                <span class="eduqr-chip">
                    <?= htmlspecialchars(t('question.type.' . $row['type']), ENT_QUOTES, 'UTF-8') ?>
                    <strong><?= (int) $row['count'] ?></strong>
                </span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="eduqr-section-head">
    <h2 class="h4 mb-0"><?= htmlspecialchars(t('course.analytics.all_sessions'), ENT_QUOTES, 'UTF-8') ?></h2>
</div>

<?php if (empty($sessions)): ?>
<div class="eduqr-surface eduqr-empty-state text-start">
    <span class="eduqr-icon-badge mb-3"><?= eduqr_icon('clock') ?></span>
    <h3 class="h5 mb-2"><?= htmlspecialchars(t('course.analytics.empty'), ENT_QUOTES, 'UTF-8') ?></h3>
    <p class="text-muted mb-0"><?= htmlspecialchars(t('course.analytics.empty'), ENT_QUOTES, 'UTF-8') ?></p>
</div>
<?php else: ?>
<div class="eduqr-card-list">
    <?php foreach ($sessions as $session): ?>
        <?php
        $badgeClass = match ($session['status']) {
            'active' => 'text-bg-success',
            'paused' => 'text-bg-warning',
            default => 'text-bg-secondary',
        };
        ?>
    <div class="eduqr-card-row">
        <div>
            <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
                <a href="<?= htmlspecialchars(eduqr_path('/admin/sessions/' . (int) $session['session_id']), ENT_QUOTES, 'UTF-8') ?>" class="h5 mb-0 text-decoration-none">
                    <?= htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8') ?>
                </a>
                <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars(t('session.status.' . $session['status']), ENT_QUOTES, 'UTF-8') ?></span>
                <?php if ($session['anonymized']): ?>
                <span class="badge text-bg-light"><?= htmlspecialchars(t('report.anonymize'), ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
            </div>
            <div class="d-flex flex-wrap gap-2 meta mb-3">
                <span class="eduqr-chip"><code><?= htmlspecialchars($session['short_code'], ENT_QUOTES, 'UTF-8') ?></code></span>
                <span class="eduqr-chip"><?= htmlspecialchars($session['started_at'] ? fmt_date($session['started_at']) : t('course.analytics.no_last_session'), ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="eduqr-admin-grid">
                <div class="eduqr-data-card">
                    <div class="label"><?= htmlspecialchars(t('report.participant_count'), ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="value"><?= (int) $session['participant_count'] ?></div>
                </div>
                <div class="eduqr-data-card">
                    <div class="label"><?= htmlspecialchars(t('report.question_count'), ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="value"><?= (int) $session['question_count'] ?></div>
                </div>
                <div class="eduqr-data-card">
                    <div class="label"><?= htmlspecialchars(t('report.answer_count'), ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="value"><?= (int) $session['answer_count'] ?></div>
                </div>
                <div class="eduqr-data-card">
                    <div class="label"><?= htmlspecialchars(t('course.analytics.average_participation_rate'), ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="value"><?= htmlspecialchars($formatRate((float) $session['participation_rate']), ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap justify-content-end">
            <a href="<?= htmlspecialchars(eduqr_path('/admin/sessions/' . (int) $session['session_id'] . '/report'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary btn-sm">
                <?= eduqr_icon('chart') ?> <?= htmlspecialchars(t('session.action.view_report'), ENT_QUOTES, 'UTF-8') ?>
            </a>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php
$content = ob_get_clean();
$pageTitle = htmlspecialchars(t('course.analytics.title'), ENT_QUOTES, 'UTF-8') . ' — ' . htmlspecialchars($course['title'], ENT_QUOTES, 'UTF-8');
include __DIR__ . '/../../layouts/admin.php';
