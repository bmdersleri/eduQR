<?php

use EduQR\Config;
use EduQR\Middleware\AuthMiddleware;
use EduQR\Middleware\CsrfMiddleware;
use EduQR\Repositories\CourseRepository;
use EduQR\Repositories\SessionRepository;
use EduQR\Services\SessionService;

$instructor = AuthMiddleware::require();
$csrfToken  = CsrfMiddleware::getToken();
$sessionId  = (int) ($p['id'] ?? 0);

$service = new SessionService(new SessionRepository(), new CourseRepository());

try {
    $session = $service->getSession($sessionId, (int) $instructor['id']);
} catch (\RuntimeException $e) {
    $status = $e->getMessage() === 'session_not_found' ? 404 : 403;
    http_response_code($status);
    include __DIR__ . '/../../../templates/errors/' . $status . '.php';
    exit;
}

$courseRepo = new CourseRepository();
$course     = $courseRepo->findById((int) $session['course_id']);

$appUrl  = rtrim(Config::get('APP_URL', ''), '/');
$joinUrl = $appUrl . '/join/' . htmlspecialchars($session['short_code'], ENT_QUOTES, 'UTF-8');

$isActive = $session['status'] === 'active';
$isPaused = $session['status'] === 'paused';
$isClosed = $session['status'] === 'closed';

ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <a href="/admin/courses/<?= (int) $session['course_id'] ?>" class="btn btn-outline-secondary btn-sm">
        &larr; <?= htmlspecialchars($course['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>
    </a>
    <?php if (!$isClosed): ?>
    <a href="/live/<?= htmlspecialchars($session['short_code'], ENT_QUOTES, 'UTF-8') ?>"
       target="_blank" class="btn btn-outline-primary btn-sm">
        <?= htmlspecialchars(t('session.action.view_live'), ENT_QUOTES, 'UTF-8') ?>
    </a>
    <?php endif; ?>
</div>

<div class="row g-4">
    <!-- Left: info + controls + question manager -->
    <div class="col-lg-7">
        <div class="d-flex align-items-center gap-3 mb-3">
            <h2 class="mb-0"><?= htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8') ?></h2>
            <span class="badge <?= $isActive ? 'text-bg-success' : ($isPaused ? 'text-bg-warning' : 'text-bg-secondary') ?>">
                <?= htmlspecialchars(t('session.status.' . $session['status']), ENT_QUOTES, 'UTF-8') ?>
            </span>
        </div>

        <dl class="row mb-4">
            <dt class="col-sm-4"><?= htmlspecialchars(t('session.short_code.label'), ENT_QUOTES, 'UTF-8') ?></dt>
            <dd class="col-sm-8">
                <code class="fs-5 fw-bold"><?= htmlspecialchars($session['short_code'], ENT_QUOTES, 'UTF-8') ?></code>
            </dd>

            <dt class="col-sm-4"><?= htmlspecialchars(t('session.join_url.label'), ENT_QUOTES, 'UTF-8') ?></dt>
            <dd class="col-sm-8">
                <a href="<?= htmlspecialchars($joinUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank">
                    <?= htmlspecialchars($joinUrl, ENT_QUOTES, 'UTF-8') ?>
                </a>
            </dd>

            <dt class="col-sm-4"><?= htmlspecialchars(t('session.participants.count'), ENT_QUOTES, 'UTF-8') ?></dt>
            <dd class="col-sm-8">
                <span id="participant-count" class="fw-bold">0</span>
            </dd>
        </dl>

        <?php if (!$isClosed): ?>
        <div class="d-flex gap-2 flex-wrap mb-4">
            <?php if ($isActive): ?>
            <button id="btn-pause" class="btn btn-warning btn-sm">
                <?= htmlspecialchars(t('session.action.pause'), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <?php endif; ?>

            <?php if ($isPaused): ?>
            <button id="btn-resume" class="btn btn-success btn-sm">
                <?= htmlspecialchars(t('session.action.resume'), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <?php endif; ?>

            <button id="btn-close" class="btn btn-outline-danger btn-sm">
                <?= htmlspecialchars(t('session.action.close'), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
        <?php endif; ?>

        <!-- ── show_results + moderation toggles (T-808, T-809) ─────────────── -->
        <div class="d-flex flex-wrap gap-3 mb-3 align-items-center">
            <!-- Live results link -->
            <a href="/admin/sessions/<?= $sessionId ?>/results"
               class="btn btn-sm btn-outline-success">
                📊 <?= htmlspecialchars(t('session.action.view_live'), ENT_QUOTES, 'UTF-8') ?>
            </a>

            <!-- show_results_to_students toggle (T-808) -->
            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" role="switch"
                       id="toggle-show-results"
                       <?= (bool) $session['show_results_to_students'] ? 'checked' : '' ?>>
                <label class="form-check-label" for="toggle-show-results">
                    <?= htmlspecialchars(t('results.show_to_students'), ENT_QUOTES, 'UTF-8') ?>
                </label>
            </div>

            <!-- moderation_mode toggle (T-809) -->
            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" role="switch"
                       id="toggle-moderation"
                       <?= (bool) $session['moderation_mode'] ? 'checked' : '' ?>>
                <label class="form-check-label" for="toggle-moderation">
                    Moderation
                </label>
            </div>
        </div>

        <div id="session-feedback" class="alert d-none" role="alert"></div>

        <hr>

        <!-- ── Question Manager (T-615) ──────────────────────────────────── -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0"><?= htmlspecialchars(t('question.new.title'), ENT_QUOTES, 'UTF-8') ?></h5>
            <?php if (!$isClosed): ?>
            <button class="btn btn-primary btn-sm"
                    data-bs-toggle="collapse"
                    data-bs-target="#create-question-panel"
                    aria-expanded="false">
                + <?= htmlspecialchars(t('question.action.create'), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <?php endif; ?>
        </div>

        <!-- Create question form (collapsed by default) -->
        <?php if (!$isClosed): ?>
        <div class="collapse mb-3" id="create-question-panel">
            <div class="card card-body bg-light border">
                <div id="create-error" class="alert alert-danger d-none" role="alert"></div>

                <div class="mb-3">
                    <label for="q-text" class="form-label fw-semibold">
                        <?= htmlspecialchars(t('question.field.text'), ENT_QUOTES, 'UTF-8') ?>
                    </label>
                    <textarea id="q-text" class="form-control" rows="3" maxlength="500"></textarea>
                </div>

                <div class="mb-3">
                    <label for="q-type" class="form-label fw-semibold">
                        <?= htmlspecialchars(t('question.field.type'), ENT_QUOTES, 'UTF-8') ?>
                    </label>
                    <select id="q-type" class="form-select">
                        <option value="multiple_choice"><?= htmlspecialchars(t('question.type.multiple_choice'), ENT_QUOTES, 'UTF-8') ?></option>
                        <option value="open_text"><?= htmlspecialchars(t('question.type.open_text'), ENT_QUOTES, 'UTF-8') ?></option>
                        <option value="yes_no"><?= htmlspecialchars(t('question.type.yes_no'), ENT_QUOTES, 'UTF-8') ?></option>
                        <option value="likert_5"><?= htmlspecialchars(t('question.type.likert_5'), ENT_QUOTES, 'UTF-8') ?></option>
                    </select>
                </div>

                <!-- Options (shown only for multiple_choice) -->
                <div id="options-section" class="mb-3">
                    <label class="form-label fw-semibold">
                        <?= htmlspecialchars(t('question.field.options'), ENT_QUOTES, 'UTF-8') ?>
                    </label>
                    <div id="options-list"></div>
                    <button type="button" class="btn btn-outline-secondary btn-sm mt-2" id="btn-add-option">
                        + <?= htmlspecialchars(t('question.action.add_option'), ENT_QUOTES, 'UTF-8') ?>
                    </button>
                </div>

                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="q-show-results">
                    <label class="form-check-label" for="q-show-results">
                        <?= htmlspecialchars(t('question.field.show_results'), ENT_QUOTES, 'UTF-8') ?>
                    </label>
                </div>

                <div class="d-flex gap-2">
                    <button type="button" id="btn-create-question" class="btn btn-primary btn-sm">
                        <?= htmlspecialchars(t('question.action.create'), ENT_QUOTES, 'UTF-8') ?>
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm"
                            data-bs-toggle="collapse" data-bs-target="#create-question-panel">
                        <?= htmlspecialchars(t('common.cancel'), ENT_QUOTES, 'UTF-8') ?>
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Question list (populated by JS) -->
        <div id="question-list">
            <p class="text-muted small" id="questions-empty">
                <?= htmlspecialchars(t('question.no_active'), ENT_QUOTES, 'UTF-8') ?>
            </p>
        </div>
    </div>

    <!-- Right: QR code -->
    <?php if (!$isClosed): ?>
    <div class="col-lg-5 text-center">
        <p class="fw-semibold mb-2"><?= htmlspecialchars(t('session.qr.title'), ENT_QUOTES, 'UTF-8') ?></p>
        <img src="/api/v1/sessions/<?= (int) $session['id'] ?>/qr.png?size=400"
             alt="<?= htmlspecialchars(t('session.qr.title'), ENT_QUOTES, 'UTF-8') ?>"
             class="img-fluid border rounded p-2"
             style="max-width:320px">
    </div>
    <?php endif; ?>
</div>

<script>
const SESSION_ID  = <?= (int) $session['id'] ?>;
const CSRF_TOKEN  = <?= json_encode($csrfToken) ?>;
const IS_ACTIVE   = <?= $isActive ? 'true' : 'false' ?>;
const IS_CLOSED   = <?= $isClosed ? 'true' : 'false' ?>;
const MSG_CONFIRM = <?= json_encode(t('common.confirm')) ?>;
const MSG_SERVER  = <?= json_encode(t('error.server_error')) ?>;
const L = {
    draft:       <?= json_encode(t('question.status.draft')) ?>,
    active:      <?= json_encode(t('question.status.active')) ?>,
    closed:      <?= json_encode(t('question.status.closed')) ?>,
    activate:    <?= json_encode(t('question.action.activate')) ?>,
    closeQ:      <?= json_encode(t('question.action.close')) ?>,
    del:         <?= json_encode(t('common.delete')) ?>,
    types: {
        multiple_choice: <?= json_encode(t('question.type.multiple_choice')) ?>,
        open_text:       <?= json_encode(t('question.type.open_text')) ?>,
        yes_no:          <?= json_encode(t('question.type.yes_no')) ?>,
        likert_5:        <?= json_encode(t('question.type.likert_5')) ?>,
    },
};

// ── Session controls ──────────────────────────────────────────────────────────

const feedbackEl = document.getElementById('session-feedback');

function showFeedback(msg, type) {
    feedbackEl.textContent = msg;
    feedbackEl.className   = 'alert alert-' + type;
}

async function sendSessionAction(action) {
    try {
        const res  = await fetch('/api/v1/sessions/' + SESSION_ID + '/' + action, {
            method:  'POST',
            headers: { 'X-CSRF-Token': CSRF_TOKEN },
        });
        const data = await res.json();
        if (data.success) {
            window.location.reload();
        } else {
            showFeedback(data.error?.message || MSG_SERVER, 'danger');
        }
    } catch {
        showFeedback(MSG_SERVER, 'danger');
    }
}

document.getElementById('btn-pause')  ?.addEventListener('click', () => sendSessionAction('pause'));
document.getElementById('btn-resume') ?.addEventListener('click', () => sendSessionAction('resume'));
document.getElementById('btn-close')  ?.addEventListener('click', function () {
    if (!confirm(MSG_CONFIRM)) return;
    sendSessionAction('close');
});

// ── show_results_to_students toggle (T-808) ──────────────────────────────────
const toggleShowResults = document.getElementById('toggle-show-results');
const toggleModeration  = document.getElementById('toggle-moderation');

async function patchSession(fields) {
    try {
        const res  = await fetch('/api/v1/sessions/' + SESSION_ID, {
            method:  'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': CSRF_TOKEN,
            },
            body: JSON.stringify(fields),
        });
        const data = await res.json();
        if (!data.success) {
            showFeedback(data.error?.message || MSG_SERVER, 'danger');
        }
    } catch {
        showFeedback(MSG_SERVER, 'danger');
    }
}

toggleShowResults?.addEventListener('change', function () {
    patchSession({ show_results_to_students: this.checked ? 1 : 0 });
});

toggleModeration?.addEventListener('change', function () {
    patchSession({ moderation_mode: this.checked ? 1 : 0 });
});

// ── Participant count ─────────────────────────────────────────────────────────

async function refreshCount() {
    try {
        const res  = await fetch('/api/v1/sessions/' + SESSION_ID + '/participants/count');
        const data = await res.json();
        if (data.success) {
            document.getElementById('participant-count').textContent = data.data.count;
        }
    } catch {}
}

refreshCount();
<?php if (!$isClosed): ?>
setInterval(refreshCount, 5000);
<?php endif; ?>

// ── Question manager ──────────────────────────────────────────────────────────

let questions = [];
let dragSrcId  = null;

async function loadQuestions() {
    try {
        const res  = await fetch('/api/v1/sessions/' + SESSION_ID + '/questions');
        const data = await res.json();
        if (data.success) {
            questions = data.data;
            renderQuestions();
        }
    } catch {}
}

function renderQuestions() {
    const list  = document.getElementById('question-list');
    const empty = document.getElementById('questions-empty');

    if (questions.length === 0) {
        empty.classList.remove('d-none');
        list.querySelectorAll('.q-row').forEach(el => el.remove());
        return;
    }

    empty.classList.add('d-none');
    list.querySelectorAll('.q-row').forEach(el => el.remove());

    questions.forEach(q => {
        const row = buildQuestionRow(q);
        list.appendChild(row);
    });
}

function buildQuestionRow(q) {
    const statusColors = { draft: 'secondary', active: 'success', closed: 'secondary' };
    const color = statusColors[q.status] || 'secondary';
    const label = L[q.status] || q.status;

    const div = document.createElement('div');
    div.className = 'q-row card mb-2';
    div.dataset.id = q.id;
    div.draggable  = !IS_CLOSED;

    const optCount = q.options.length > 0 ? ` (${q.options.length})` : '';
    const typeLabel = (L.types[q.question_type] || q.question_type) + optCount;
    const textShort = q.question_text.length > 120
        ? q.question_text.slice(0, 120) + '…'
        : q.question_text;

    let actions = '';
    if (!IS_CLOSED) {
        if (q.status === 'draft') {
            actions += `<button class="btn btn-success btn-sm" onclick="activateQuestion(${q.id})">${L.activate}</button> `;
            actions += `<button class="btn btn-outline-danger btn-sm" onclick="deleteQuestion(${q.id})">${L.del}</button>`;
        } else if (q.status === 'active') {
            actions += `<button class="btn btn-warning btn-sm" onclick="closeQuestion(${q.id})">${L.closeQ}</button>`;
        }
    }

    div.innerHTML = `
        <div class="card-body py-2 px-3 d-flex align-items-start gap-3">
            <span class="grip-handle text-muted" style="cursor:grab;user-select:none;font-size:1.1rem;padding-top:2px">&#8801;</span>
            <div class="flex-grow-1">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="badge text-bg-${color}">${label}</span>
                    <span class="badge text-bg-light border text-dark">${escHtml(typeLabel)}</span>
                </div>
                <p class="mb-0 small">${escHtml(textShort)}</p>
            </div>
            <div class="d-flex gap-1 flex-shrink-0">${actions}</div>
        </div>`;

    // Drag-and-drop (reorder) — only when session is active/paused
    if (!IS_CLOSED) {
        div.addEventListener('dragstart', onDragStart);
        div.addEventListener('dragover',  onDragOver);
        div.addEventListener('dragleave', onDragLeave);
        div.addEventListener('drop',      onDrop);
        div.addEventListener('dragend',   onDragEnd);
    }

    return div;
}

function escHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Drag-and-drop ─────────────────────────────────────────────────────────────

function onDragStart(e) {
    dragSrcId = +e.currentTarget.dataset.id;
    e.currentTarget.classList.add('opacity-50');
    e.dataTransfer.effectAllowed = 'move';
}

function onDragOver(e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    e.currentTarget.classList.add('border-primary');
}

function onDragLeave(e) {
    e.currentTarget.classList.remove('border-primary');
}

async function onDrop(e) {
    e.preventDefault();
    const targetId = +e.currentTarget.dataset.id;
    e.currentTarget.classList.remove('border-primary');

    if (dragSrcId === null || dragSrcId === targetId) return;

    const srcIdx = questions.findIndex(q => q.id === dragSrcId);
    const tgtIdx = questions.findIndex(q => q.id === targetId);
    if (srcIdx === -1 || tgtIdx === -1) return;

    // Move in local array
    const [moved] = questions.splice(srcIdx, 1);
    questions.splice(tgtIdx, 0, moved);
    renderQuestions();

    // Persist to server
    try {
        await fetch('/api/v1/sessions/' + SESSION_ID + '/questions/reorder', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
            body:    JSON.stringify({ order: questions.map(q => q.id) }),
        });
    } catch {}
}

function onDragEnd(e) {
    e.currentTarget.classList.remove('opacity-50');
    dragSrcId = null;
}

// ── Question actions ──────────────────────────────────────────────────────────

async function activateQuestion(id) {
    try {
        const res  = await fetch('/api/v1/questions/' + id + '/activate', {
            method: 'POST', headers: { 'X-CSRF-Token': CSRF_TOKEN },
        });
        const data = await res.json();
        if (data.success) {
            await loadQuestions();
        } else {
            showFeedback(data.error?.message || MSG_SERVER, 'danger');
        }
    } catch {
        showFeedback(MSG_SERVER, 'danger');
    }
}

async function closeQuestion(id) {
    try {
        const res  = await fetch('/api/v1/questions/' + id + '/close', {
            method: 'POST', headers: { 'X-CSRF-Token': CSRF_TOKEN },
        });
        const data = await res.json();
        if (data.success) {
            await loadQuestions();
        } else {
            showFeedback(data.error?.message || MSG_SERVER, 'danger');
        }
    } catch {
        showFeedback(MSG_SERVER, 'danger');
    }
}

async function deleteQuestion(id) {
    if (!confirm(MSG_CONFIRM)) return;
    try {
        const res  = await fetch('/api/v1/questions/' + id, {
            method: 'DELETE', headers: { 'X-CSRF-Token': CSRF_TOKEN },
        });
        const data = await res.json();
        if (data.success) {
            await loadQuestions();
        } else {
            showFeedback(data.error?.message || MSG_SERVER, 'danger');
        }
    } catch {
        showFeedback(MSG_SERVER, 'danger');
    }
}

// ── Create question form ───────────────────────────────────────────────────────

let optionCount = 2;

function initOptions() {
    const list = document.getElementById('options-list');
    list.innerHTML = '';
    optionCount = 2;
    for (let i = 0; i < 2; i++) addOptionInput();
}

function addOptionInput() {
    if (optionCount > 8) return;
    const list  = document.getElementById('options-list');
    const index = list.children.length;
    const row   = document.createElement('div');
    row.className = 'input-group input-group-sm mb-1 option-row';
    row.innerHTML = `
        <input type="text" class="form-control option-input" placeholder="Option ${index + 1}" maxlength="200">
        <button type="button" class="btn btn-outline-secondary" onclick="removeOptionInput(this)">×</button>`;
    list.appendChild(row);
}

function removeOptionInput(btn) {
    const list = document.getElementById('options-list');
    if (list.children.length <= 2) return;
    btn.closest('.option-row').remove();
    optionCount--;
}

document.getElementById('btn-add-option')?.addEventListener('click', () => {
    if (optionCount >= 8) return;
    optionCount++;
    addOptionInput();
});

document.getElementById('q-type')?.addEventListener('change', function () {
    const section = document.getElementById('options-section');
    section.style.display = this.value === 'multiple_choice' ? '' : 'none';
});

document.getElementById('btn-create-question')?.addEventListener('click', async function () {
    const btn  = this;
    const type = document.getElementById('q-type').value;
    const text = document.getElementById('q-text').value.trim();
    const showResults = document.getElementById('q-show-results').checked;
    const errEl = document.getElementById('create-error');

    errEl.classList.add('d-none');

    const body = { question_text: text, question_type: type, show_results: showResults };

    if (type === 'multiple_choice') {
        const inputs = document.querySelectorAll('#options-list .option-input');
        body.options = Array.from(inputs).map(i => ({ option_text: i.value.trim() }));
    }

    btn.disabled = true;
    try {
        const res  = await fetch('/api/v1/sessions/' + SESSION_ID + '/questions', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
            body:    JSON.stringify(body),
        });
        const data = await res.json();
        if (data.success) {
            // Reset form and close panel
            document.getElementById('q-text').value = '';
            document.getElementById('q-show-results').checked = false;
            document.getElementById('q-type').value = 'multiple_choice';
            document.getElementById('options-section').style.display = '';
            initOptions();
            bootstrap.Collapse.getOrCreateInstance(
                document.getElementById('create-question-panel')
            ).hide();
            await loadQuestions();
        } else {
            errEl.textContent = data.error?.message || MSG_SERVER;
            errEl.classList.remove('d-none');
        }
    } catch {
        errEl.textContent = MSG_SERVER;
        errEl.classList.remove('d-none');
    } finally {
        btn.disabled = false;
    }
});

// ── Boot ──────────────────────────────────────────────────────────────────────

initOptions();
// Show options section only for multiple_choice on initial load
document.getElementById('q-type')?.dispatchEvent(new Event('change'));

loadQuestions();
</script>
<?php
$content   = ob_get_clean();
$pageTitle = htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8') . ' — ' . t('app.name');
include __DIR__ . '/../../layouts/admin.php';
