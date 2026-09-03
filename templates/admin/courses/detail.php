<div class="eduqr-admin-hero mb-4">
    <div>
        <div class="eduqr-kicker mb-3">
            <span class="eduqr-icon-badge"><?= eduqr_icon('qr') ?></span>
            <span><?= htmlspecialchars(t('course.edit.title'), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
            <h1 class="h2 mb-0"><?= htmlspecialchars($course['title'], ENT_QUOTES, 'UTF-8') ?></h1>
            <?php if ($course['status'] === 'archived'): ?>
            <span class="badge text-bg-secondary">
                <?= htmlspecialchars(t('course.archived_badge'), ENT_QUOTES, 'UTF-8') ?>
            </span>
            <?php endif; ?>
        </div>
        <p class="text-muted mb-0"><?= htmlspecialchars($course['description'] ?? t('course.list.empty'), ENT_QUOTES, 'UTF-8') ?></p>
    </div>
    <div class="d-flex align-items-start gap-2 flex-wrap">
        <a href="<?= htmlspecialchars(eduqr_path('/admin/courses'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary btn-sm">
            <?= eduqr_icon('user') ?> <?= htmlspecialchars(t('common.back'), ENT_QUOTES, 'UTF-8') ?>
        </a>
        <a href="<?= htmlspecialchars(eduqr_path('/admin/courses/' . (int) $course['id'] . '/analytics'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary btn-sm">
            <?= eduqr_icon('chart') ?> <?= htmlspecialchars(t('course.analytics.view'), ENT_QUOTES, 'UTF-8') ?>
        </a>
        <?php /* Restoring is owner-only (FR-97); the API enforces it too. */ ?>
        <?php if ($course['status'] === 'archived' && $isCourseOwner): ?>
        <button type="button" id="course-restore-btn" class="btn btn-primary btn-sm">
            <?= eduqr_icon('spark') ?> <?= htmlspecialchars(t('course.action.restore'), ENT_QUOTES, 'UTF-8') ?>
        </button>
        <?php elseif ($course['status'] !== 'archived'): ?>
        <a href="<?= htmlspecialchars(eduqr_path('/admin/courses/' . (int) $course['id'] . '/edit'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-primary btn-sm">
            <?= eduqr_icon('spark') ?> <?= htmlspecialchars(t('common.edit'), ENT_QUOTES, 'UTF-8') ?>
        </a>
        <?php endif; ?>
    </div>
</div>

<div class="eduqr-admin-grid mb-4">
    <?php if ($course['code']): ?>
    <div class="eduqr-data-card">
        <div class="label"><?= htmlspecialchars(t('course.field.code'), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="value"><code><?= htmlspecialchars($course['code'], ENT_QUOTES, 'UTF-8') ?></code></div>
    </div>
    <?php endif; ?>

    <?php if ($course['semester']): ?>
    <div class="eduqr-data-card">
        <div class="label"><?= htmlspecialchars(t('course.field.semester'), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="value"><?= htmlspecialchars($course['semester'], ENT_QUOTES, 'UTF-8') ?></div>
    </div>
    <?php endif; ?>

    <div class="eduqr-data-card">
        <div class="label"><?= htmlspecialchars(t('course.field.default_language'), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="value"><?= htmlspecialchars(strtoupper($course['default_language']), ENT_QUOTES, 'UTF-8') ?></div>
    </div>

    <div class="eduqr-data-card">
        <div class="label"><?= htmlspecialchars(t('common.created_at'), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="value"><?= htmlspecialchars(fmt_date($course['created_at']), ENT_QUOTES, 'UTF-8') /* FR-85 */ ?></div>
    </div>
</div>

<div class="eduqr-section-head">
    <h2 class="h4 mb-0"><?= htmlspecialchars(t('nav.sessions'), ENT_QUOTES, 'UTF-8') ?></h2>
    <?php if ($course['status'] === 'active'): ?>
    <a href="<?= htmlspecialchars(eduqr_path('/admin/courses/' . (int) $course['id'] . '/sessions/new'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-primary btn-sm">
        <?= eduqr_icon('spark') ?> <?= htmlspecialchars(t('session.new.submit'), ENT_QUOTES, 'UTF-8') ?>
    </a>
    <?php endif; ?>
</div>

<?php if (empty($sessions)): ?>
<div class="eduqr-surface eduqr-empty-state text-start">
    <span class="eduqr-icon-badge mb-3"><?= eduqr_icon('clock') ?></span>
    <h3 class="h5 mb-2"><?= htmlspecialchars(t('instructor.dashboard.no_sessions'), ENT_QUOTES, 'UTF-8') ?></h3>
    <p class="text-muted mb-0"><?= htmlspecialchars(t('instructor.dashboard.no_sessions'), ENT_QUOTES, 'UTF-8') ?></p>
</div>
<?php else: ?>
<div class="eduqr-card-list">
    <?php foreach ($sessions as $s): ?>
        <?php
        $badgeClass = match ($s['status']) {
            'active' => 'text-bg-success',
            'paused' => 'text-bg-warning',
            default  => 'text-bg-secondary',
        };
        ?>
        <div class="eduqr-card-row">
            <div>
                <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
                    <a href="<?= htmlspecialchars(eduqr_path('/admin/sessions/' . (int) $s['id']), ENT_QUOTES, 'UTF-8') ?>" class="h5 mb-0 text-decoration-none">
                        <?= htmlspecialchars($s['title'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                    <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars(t('session.status.' . $s['status']), ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <div class="d-flex flex-wrap gap-2 meta">
                    <span class="eduqr-chip"><code><?= htmlspecialchars($s['short_code'], ENT_QUOTES, 'UTF-8') ?></code></span>
                    <span class="eduqr-chip"><?= htmlspecialchars(fmt_date($s['created_at']), ENT_QUOTES, 'UTF-8') /* FR-85 */ ?></span>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap justify-content-end">
                <a href="<?= htmlspecialchars(eduqr_path('/admin/sessions/' . (int) $s['id']), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary btn-sm">
                    <?= eduqr_icon('chart') ?> <?= htmlspecialchars(t('common.actions'), ENT_QUOTES, 'UTF-8') ?>
                </a>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php if ($course['status'] === 'archived' && $isCourseOwner): ?>
<script>
document.getElementById('course-restore-btn').addEventListener('click', async function () {
    this.disabled = true;

    try {
        const res = await fetch(eduqrPath('/api/v1/courses/<?= (int) $course['id'] ?>/restore'), {
            method: 'POST',
            headers: { 'X-CSRF-Token': <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?> },
        });
        const data = await res.json();

        if (data.success) {
            eduqrToast(data.message || <?= json_encode(t('course.restored'), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>, 'ok');
            setTimeout(() => window.location.reload(), 500);
            return;
        }

        eduqrToast(data.error?.message || <?= json_encode(t('common.error'), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>, 'err');
        this.disabled = false;
    } catch {
        eduqrToast(<?= json_encode(t('error.server_error'), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>, 'err');
        this.disabled = false;
    }
});
</script>
<?php endif; ?>
