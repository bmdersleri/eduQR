<?php
/**
 * Admin session report page — /admin/sessions/{id}/report  (T-905)
 *
 * Loads the full JSON report and renders it server-side.
 * Links to CSV export and printable HTML.
 * Provides anonymize and delete controls (T-906, T-907).
 */

use EduQR\Middleware\AuthMiddleware;
use EduQR\Middleware\CsrfMiddleware;
use EduQR\Repositories\CourseRepository;
use EduQR\Repositories\OptionRepository;
use EduQR\Repositories\QuestionRepository;
use EduQR\Repositories\SessionRepository;
use EduQR\Services\ReportService;

$instructor = AuthMiddleware::require();
$csrfToken  = CsrfMiddleware::getToken();
$sessionId  = (int) ($p['id'] ?? 0);

$reportService = new ReportService(
    new SessionRepository(),
    new QuestionRepository(),
    new OptionRepository(),
    new CourseRepository(),
);

try {
    $report = $reportService->buildReport($sessionId, (int) $instructor['id'], false);
} catch (\RuntimeException $e) {
    $code = $e->getMessage() === 'session_not_found' ? 404 : 403;
    http_response_code($code);
    include __DIR__ . '/../../../templates/errors/' . $code . '.php';
    exit;
}

$session = $report['session'];
$summary = $report['summary'];

ob_start();
?>
<!-- Header row -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <a href="/admin/sessions/<?= $sessionId ?>" class="btn btn-outline-secondary btn-sm">
        &larr; <?= htmlspecialchars(t('common.back'), ENT_QUOTES, 'UTF-8') ?>
    </a>
    <h2 class="mb-0 h4">
        <?= htmlspecialchars(t('report.title'), ENT_QUOTES, 'UTF-8') ?>
        &mdash; <?= htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8') ?>
    </h2>
    <div class="d-flex gap-2 flex-wrap">
        <a href="/api/v1/sessions/<?= $sessionId ?>/report.csv"
           class="btn btn-outline-success btn-sm">
            <?= htmlspecialchars(t('report.export_csv'), ENT_QUOTES, 'UTF-8') ?>
        </a>
        <a href="/api/v1/sessions/<?= $sessionId ?>/report.html" target="_blank"
           class="btn btn-outline-secondary btn-sm">
            <?= htmlspecialchars(t('report.export_html'), ENT_QUOTES, 'UTF-8') ?>
        </a>
    </div>
</div>

<!-- Summary card -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3">
        <div class="card text-center">
            <div class="card-body py-3">
                <div class="fs-2 fw-bold text-primary"><?= $summary['participant_count'] ?></div>
                <div class="text-muted small"><?= htmlspecialchars(t('report.participant_count'), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card text-center">
            <div class="card-body py-3">
                <div class="fs-2 fw-bold text-success"><?= $summary['question_count'] ?></div>
                <div class="text-muted small"><?= htmlspecialchars(t('report.question_count'), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card text-center">
            <div class="card-body py-3">
                <div class="fs-2 fw-bold text-info"><?= $summary['answer_count'] ?></div>
                <div class="text-muted small"><?= htmlspecialchars(t('report.answer_count'), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card text-center">
            <div class="card-body py-3">
                <div class="fs-2 fw-bold text-warning">
                    <?= number_format($summary['participation_rate'] * 100, 1) ?>%
                </div>
                <div class="text-muted small"><?= htmlspecialchars(str_replace('{rate}','', t('report.participation_rate')), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Quiz scores table (T-1104) -->
<?php if ((bool) $session['is_quiz']): ?>
<div class="card mb-4">
    <div class="card-header bg-light">
        <h5 class="mb-0 text-primary fw-bold">
            🏆 <?= htmlspecialchars(t('report.quiz_scores'), ENT_QUOTES, 'UTF-8') ?>
        </h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-striped mb-0">
                <thead>
                    <tr>
                        <th style="width: 100px;"><?= htmlspecialchars(t('report.quiz_rank'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th><?= htmlspecialchars(t('report.csv.header.nickname'), ENT_QUOTES, 'UTF-8') ?></th>
                        <th style="width: 150px;" class="text-end"><?= htmlspecialchars(t('report.quiz_score'), ENT_QUOTES, 'UTF-8') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($report['scores'])): ?>
                        <tr>
                            <td colspan="3" class="text-center text-muted py-3">
                                <em><?= htmlspecialchars(t('results.no_answers'), ENT_QUOTES, 'UTF-8') ?></em>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($report['scores'] as $scoreRow): ?>
                            <tr>
                                <td><strong>#<?= (int) $scoreRow['rank'] ?></strong></td>
                                <td><?= htmlspecialchars($scoreRow['nickname'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="text-end"><strong><?= (int) $scoreRow['score'] ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Per-question breakdown -->
<?php foreach ($report['questions'] as $qi => $q): ?>
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>
            <span class="text-muted me-2"><?= $qi + 1 ?>.</span>
            <strong><?= htmlspecialchars($q['text'], ENT_QUOTES, 'UTF-8') ?></strong>
        </span>
        <span class="badge text-bg-secondary">
            <?= htmlspecialchars(t('question.type.' . $q['type']), ENT_QUOTES, 'UTF-8') ?>
            &nbsp;&middot;&nbsp; <?= $q['answer_count'] ?> <?= htmlspecialchars(t('report.answer_count'), ENT_QUOTES, 'UTF-8') ?>
        </span>
    </div>
    <div class="card-body">
        <?php if ($q['type'] === 'open_text'): ?>
            <?php if (empty($q['answers'])): ?>
                <p class="text-muted mb-0"><em><?= htmlspecialchars(t('results.no_answers'), ENT_QUOTES, 'UTF-8') ?></em></p>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                <?php foreach ($q['answers'] as $a): ?>
                    <li class="list-group-item py-1 <?= $a['is_hidden'] ? 'opacity-50' : '' ?>">
                        <span class="fw-semibold text-muted small me-2">
                            <?= htmlspecialchars($a['nickname'], ENT_QUOTES, 'UTF-8') ?>:
                        </span>
                        <?= htmlspecialchars($a['answer_text'], ENT_QUOTES, 'UTF-8') ?>
                        <?php if ($a['is_hidden']): ?>
                            <span class="badge text-bg-secondary ms-1">
                                <?= htmlspecialchars(t('results.answer.hidden_badge'), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php else: ?>
            <?php foreach ($q['distribution'] as $opt): ?>
            <?php $pct = (float) $opt['percentage']; ?>
            <div class="mb-2">
                <div class="d-flex justify-content-between mb-1">
                    <span class="small"><?= htmlspecialchars($opt['option_text'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="small text-muted"><?= $opt['count'] ?> (<?= number_format($pct, 1) ?>%)</span>
                </div>
                <div class="progress" style="height:20px">
                    <div class="progress-bar" role="progressbar"
                         style="width:<?= min(100, $pct) ?>%"
                         aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100">
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<!-- Session management actions (anonymize + delete) -->
<div class="card mt-4 border-danger">
    <div class="card-header text-danger fw-semibold">
        <?= htmlspecialchars(t('common.actions'), ENT_QUOTES, 'UTF-8') ?>
    </div>
    <div class="card-body d-flex gap-3 flex-wrap">

        <?php if (!$session['anonymized']): ?>
        <!-- Anonymize -->
        <form id="form-anonymize" class="d-inline">
            <button type="button" class="btn btn-outline-warning btn-sm"
                    onclick="doAction('anonymize', this)">
                <?= htmlspecialchars(t('session.action.anonymize'), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </form>
        <?php else: ?>
        <span class="badge text-bg-secondary align-self-center">
            <?= htmlspecialchars(t('report.anonymize'), ENT_QUOTES, 'UTF-8') ?> ✓
        </span>
        <?php endif; ?>

        <!-- Delete -->
        <form id="form-delete" class="d-inline">
            <button type="button" class="btn btn-outline-danger btn-sm"
                    onclick="doAction('delete', this)">
                <?= htmlspecialchars(t('session.action.delete'), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </form>

    </div>
</div>

<script>
const SESSION_ID = <?= $sessionId ?>;
const CSRF_TOKEN = <?= json_encode($csrfToken) ?>;

async function doAction(action, btn) {
    if (!confirm(btn.textContent.trim() + '?')) return;
    btn.disabled = true;

    const method = action === 'delete' ? 'DELETE' : 'POST';
    const url    = action === 'delete'
        ? '/api/v1/sessions/' + SESSION_ID
        : '/api/v1/sessions/' + SESSION_ID + '/' + action;

    try {
        const res  = await fetch(url, {
            method,
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
            body: '{}',
        });
        const data = await res.json();
        if (data.success) {
            location.href = '/admin/sessions/' + SESSION_ID;
        } else {
            alert(data.error?.message ?? <?= json_encode(t('common.error')) ?>);
            btn.disabled = false;
        }
    } catch {
        alert(<?= json_encode(t('common.error')) ?>);
        btn.disabled = false;
    }
}
</script>
<?php
$pageTitle = htmlspecialchars(t('report.title'), ENT_QUOTES, 'UTF-8') . ' — ' . t('app.name');
$content   = ob_get_clean();
include __DIR__ . '/../../layouts/admin.php';
