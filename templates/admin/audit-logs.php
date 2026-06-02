<?php

use EduQR\Middleware\AuthMiddleware;
use EduQR\Middleware\CsrfMiddleware;

$user       = AuthMiddleware::requireRole('admin');
$instructor = $user;
$csrfToken  = CsrfMiddleware::getToken();

$filterActor = in_array($_GET['actor_type'] ?? '', ['instructor', 'admin', 'system'], true)
    ? $_GET['actor_type']
    : '';

ob_start();
?>
<div class="eduqr-admin-hero mb-4">
    <div>
        <div class="eduqr-kicker mb-3">
            <span class="eduqr-icon-badge"><?= eduqr_icon('chart') ?></span>
            <span><?= htmlspecialchars(t('audit.title'), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <h1 class="h2 mb-2"><?= htmlspecialchars(t('audit.title'), ENT_QUOTES, 'UTF-8') ?></h1>
        <p><?= htmlspecialchars(t('audit.description'), ENT_QUOTES, 'UTF-8') ?></p>
    </div>
</div>

<div class="eduqr-form-shell mb-4">
<form method="get" action="/admin/audit-logs" class="d-flex gap-2 align-items-end flex-wrap">
    <div class="eduqr-form-field" style="min-width: 240px;">
        <label for="actor_type"><?= htmlspecialchars(t('audit.filter.all'), ENT_QUOTES, 'UTF-8') ?></label>
        <select id="actor_type" name="actor_type" class="form-select form-select-sm">
            <option value="" <?= $filterActor === '' ? 'selected' : '' ?>>
                <?= htmlspecialchars(t('audit.filter.all'), ENT_QUOTES, 'UTF-8') ?>
            </option>
            <option value="instructor" <?= $filterActor === 'instructor' ? 'selected' : '' ?>>
                <?= htmlspecialchars(t('audit.filter.instructor'), ENT_QUOTES, 'UTF-8') ?>
            </option>
            <option value="admin" <?= $filterActor === 'admin' ? 'selected' : '' ?>>
                <?= htmlspecialchars(t('audit.filter.admin'), ENT_QUOTES, 'UTF-8') ?>
            </option>
            <option value="system" <?= $filterActor === 'system' ? 'selected' : '' ?>>
                <?= htmlspecialchars(t('audit.filter.system'), ENT_QUOTES, 'UTF-8') ?>
            </option>
        </select>
    </div>
    <div>
        <button type="submit" class="btn btn-sm btn-secondary">
            <?= htmlspecialchars(t('audit.filter.apply'), ENT_QUOTES, 'UTF-8') ?>
        </button>
    </div>
</form>
</div>

<div class="eduqr-table-wrap table-responsive">
    <table class="table table-sm table-hover">
        <thead>
            <tr>
                <th><?= htmlspecialchars(t('audit.col.time'),     ENT_QUOTES, 'UTF-8') ?></th>
                <th><?= htmlspecialchars(t('audit.col.actor'),    ENT_QUOTES, 'UTF-8') ?></th>
                <th><?= htmlspecialchars(t('audit.col.action'),   ENT_QUOTES, 'UTF-8') ?></th>
                <th><?= htmlspecialchars(t('audit.col.entity'),   ENT_QUOTES, 'UTF-8') ?></th>
                <th><?= htmlspecialchars(t('audit.col.metadata'), ENT_QUOTES, 'UTF-8') ?></th>
            </tr>
        </thead>
        <tbody id="audit-tbody">
            <tr>
                <td colspan="5" class="text-muted text-center">
                    <?= htmlspecialchars(t('audit.loading'), ENT_QUOTES, 'UTF-8') ?>
                </td>
            </tr>
        </tbody>
    </table>
</div>

<div id="audit-pagination" class="d-flex justify-content-between align-items-center mt-2 mb-4"></div>

<script>
(function () {
    var actorType = <?= json_encode($filterActor === '' ? null : $filterActor) ?>;
    var emptyMsg  = <?= json_encode(t('audit.empty')) ?>;
    var totalLbl  = <?= json_encode(t('audit.total')) ?>;

    function esc(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function renderRow(log) {
        var entity = log.entity_type ? (esc(log.entity_type) + ' #' + log.entity_id) : '&mdash;';
        var actor  = esc(log.actor_type) + (log.actor_id ? ' #' + log.actor_id : '');
        var meta   = log.metadata_json ? '<code>' + esc(log.metadata_json) + '</code>' : '&mdash;';
        return '<tr>'
            + '<td class="text-nowrap small">' + esc(log.created_at) + '</td>'
            + '<td class="small">'             + actor               + '</td>'
            + '<td><code>'                     + esc(log.action)     + '</code></td>'
            + '<td class="small">'             + entity              + '</td>'
            + '<td class="small">'             + meta                + '</td>'
            + '</tr>';
    }

    function load(page) {
        var params = 'page=' + page + '&limit=50';
        if (actorType) { params += '&actor_type=' + encodeURIComponent(actorType); }

        fetch('/api/v1/audit-logs?' + params, { headers: { Accept: 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success) { return; }

                var tbody = document.getElementById('audit-tbody');
                tbody.innerHTML = res.data.logs.length
                    ? res.data.logs.map(renderRow).join('')
                    : '<tr><td colspan="5" class="text-muted text-center">' + esc(emptyMsg) + '</td></tr>';

                var pag  = document.getElementById('audit-pagination');
                var prev = page > 1
                    ? '<button class="btn btn-sm btn-outline-secondary" onclick="window.__auditLoad(' + (page - 1) + ')">&#8249;</button>'
                    : '<button class="btn btn-sm btn-outline-secondary" disabled>&#8249;</button>';
                var next = page < res.data.pages
                    ? '<button class="btn btn-sm btn-outline-secondary" onclick="window.__auditLoad(' + (page + 1) + ')">&#8250;</button>'
                    : '<button class="btn btn-sm btn-outline-secondary" disabled>&#8250;</button>';
                pag.innerHTML = '<small class="text-muted">' + esc(totalLbl) + ': ' + res.data.total + '</small>'
                    + '<div class="d-flex align-items-center gap-2">'
                    + prev
                    + '<span class="small">' + page + ' / ' + res.data.pages + '</span>'
                    + next
                    + '</div>';
            });
    }

    window.__auditLoad = load;
    load(1);
}());
</script>
<?php
$content   = ob_get_clean();
$pageTitle = t('audit.title') . ' — ' . t('app.name');
include __DIR__ . '/../layouts/admin.php';
