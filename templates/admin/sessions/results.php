<?php
/**
 * Admin live results page — /admin/sessions/{id}/results  (T-805)
 *
 * Polls GET /api/v1/sessions/{id}/results?question_id=... every 2 seconds
 * and renders bar charts via Chart.js (CDN via data URI / local asset).
 * Also surfaces hide/unhide controls for open-text in moderation_mode (T-809).
 */

use EduQR\Middleware\AuthMiddleware;
use EduQR\Middleware\CsrfMiddleware;
use EduQR\Repositories\CourseRepository;
use EduQR\Repositories\QuestionRepository;
use EduQR\Repositories\SessionRepository;
use EduQR\Services\SessionService;

$instructor = AuthMiddleware::require();
$csrfToken  = CsrfMiddleware::getToken();
$sessionId  = (int) ($p['id'] ?? 0);

$sessionService = new SessionService(new SessionRepository(), new CourseRepository());

try {
    $session = $sessionService->getSession($sessionId, (int) $instructor['id']);
} catch (\RuntimeException $e) {
    $code = $e->getMessage() === 'session_not_found' ? 404 : 403;
    http_response_code($code);
    include __DIR__ . '/../../../templates/errors/' . $code . '.php';
    exit;
}

// Load all questions for the sidebar list
$questionRepo = new QuestionRepository();
$questions    = $questionRepo->findBySession($sessionId);

ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <a href="/admin/sessions/<?= $sessionId ?>" class="btn btn-outline-secondary btn-sm">
        &larr; <?= htmlspecialchars(t('common.back'), ENT_QUOTES, 'UTF-8') ?>
    </a>
    <h2 class="mb-0 h4">
        <?= htmlspecialchars(t('results.title'), ENT_QUOTES, 'UTF-8') ?>
        &mdash; <?= htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8') ?>
    </h2>
    <a href="/live/<?= htmlspecialchars($session['short_code'], ENT_QUOTES, 'UTF-8') ?>"
       target="_blank" class="btn btn-outline-primary btn-sm">
        <?= htmlspecialchars(t('session.action.view_live'), ENT_QUOTES, 'UTF-8') ?>
    </a>
</div>

<div class="row g-4">

    <!-- Left: question list -->
    <div class="col-lg-3">
        <div class="list-group" id="question-list">
        <?php foreach ($questions as $i => $q): ?>
            <button
                type="button"
                class="list-group-item list-group-item-action <?= $i === 0 ? 'active' : '' ?>"
                data-question-id="<?= (int) $q['id'] ?>"
                data-question-type="<?= htmlspecialchars($q['question_type'], ENT_QUOTES, 'UTF-8') ?>"
            >
                <span class="badge me-2 text-bg-<?= $q['status'] === 'active' ? 'success' : ($q['status'] === 'closed' ? 'secondary' : 'light text-dark') ?>">
                    <?= htmlspecialchars(t('question.status.' . $q['status']), ENT_QUOTES, 'UTF-8') ?>
                </span>
                <span class="text-truncate d-inline-block" style="max-width:180px">
                    <?= htmlspecialchars($q['question_text'], ENT_QUOTES, 'UTF-8') ?>
                </span>
            </button>
        <?php endforeach; ?>
        </div>
    </div>

    <!-- Right: results panel -->
    <div class="col-lg-9">
        <div class="card">
            <div class="card-body">

                <!-- Answer count + refresh indicator -->
                <div class="d-flex align-items-center gap-3 mb-3">
                    <span id="answer-count" class="fs-5 fw-semibold text-muted">—</span>
                    <div class="spinner-border spinner-border-sm text-secondary" id="refresh-spinner" role="status">
                        <span class="visually-hidden"><?= htmlspecialchars(t('common.loading'), ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                </div>

                <!-- Chart area (option-based questions) -->
                <div id="chart-area">
                    <canvas id="results-chart" height="200"></canvas>
                </div>

                <!-- Open-text area -->
                <div id="open-text-area" class="d-none">
                    <ul id="open-text-list" class="list-group list-group-flush"></ul>
                </div>

                <p id="no-answers-msg" class="text-muted text-center mt-4 d-none">
                    <?= htmlspecialchars(t('results.no_answers'), ENT_QUOTES, 'UTF-8') ?>
                </p>

            </div>
        </div>
    </div>

</div>

<!-- Chart.js (loaded from local assets if present, else CDN) -->
<script src="/assets/js/chart.umd.min.js"
        onerror="this.onerror=null;this.src='https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js'">
</script>

<script>
const SESSION_ID       = <?= $sessionId ?>;
const CSRF_TOKEN       = <?= json_encode($csrfToken) ?>;
const MODERATION_MODE  = <?= (int) $session['moderation_mode'] ?>;
const MSG_HIDE         = <?= json_encode(t('results.answer.hide')) ?>;
const MSG_UNHIDE       = <?= json_encode(t('results.answer.unhide')) ?>;
const MSG_HIDDEN_BADGE = <?= json_encode(t('results.answer.hidden_badge')) ?>;
const MSG_ANSWERS      = <?= json_encode(t('results.answer_count', ['{count}' => ''])) ?>;

let chart          = null;
let currentQId     = <?= $questions ? (int) $questions[0]['id'] : 0 ?>;
let currentQType   = <?= $questions ? json_encode($questions[0]['question_type']) : json_encode('') ?>;
let pollTimer      = null;

// ── Question selector ──────────────────────────────────────────────────────────
document.querySelectorAll('#question-list button').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('#question-list button').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        currentQId   = parseInt(btn.dataset.questionId, 10);
        currentQType = btn.dataset.questionType;
        fetchResults();
    });
});

// ── Fetch + render ─────────────────────────────────────────────────────────────
async function fetchResults() {
    if (!currentQId) return;

    try {
        const res  = await fetch('/api/v1/sessions/' + SESSION_ID + '/results?question_id=' + currentQId);
        const data = await res.json();
        if (data.success && data.data && data.data.length > 0) {
            renderResults(data.data[0]);
        }
    } catch {
        // Network error — keep polling
    }
}

function renderResults(result) {
    const answerCount = result.answer_count ?? 0;
    document.getElementById('answer-count').textContent =
        <?= json_encode(t('results.answer_count')) ?>.replace('{count}', answerCount);

    const noMsg      = document.getElementById('no-answers-msg');
    const chartArea  = document.getElementById('chart-area');
    const textArea   = document.getElementById('open-text-area');

    if (result.question_type === 'open_text') {
        chartArea.classList.add('d-none');
        textArea.classList.remove('d-none');
        noMsg.classList.toggle('d-none', answerCount > 0);
        renderOpenText(result.answers ?? []);
    } else {
        textArea.classList.add('d-none');
        chartArea.classList.remove('d-none');
        noMsg.classList.toggle('d-none', answerCount > 0);
        renderChart(result.options ?? []);
    }
}

function renderChart(options) {
    const labels = options.map(o => o.text);
    const counts = options.map(o => o.count);
    const pcts   = options.map(o => o.percent);

    const ctx = document.getElementById('results-chart').getContext('2d');
    if (chart) chart.destroy();
    chart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: '',
                data: counts,
                backgroundColor: [
                    '#0d6efd','#198754','#ffc107','#dc3545','#0dcaf0',
                    '#6f42c1','#fd7e14','#20c997',
                ],
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            plugins: {
                legend:  { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => ` ${ctx.parsed.x}  (${pcts[ctx.dataIndex]}%)`,
                    }
                },
            },
            scales: {
                x: {
                    beginAtZero: true,
                    ticks: { stepSize: 1 },
                }
            }
        }
    });
}

function renderOpenText(answers) {
    const list = document.getElementById('open-text-list');
    list.innerHTML = '';

    answers.forEach(a => {
        const li = document.createElement('li');
        li.className = 'list-group-item d-flex justify-content-between align-items-start py-2';
        li.dataset.answerId = a.id;

        const textDiv = document.createElement('div');
        textDiv.className = 'me-auto';

        const nick = document.createElement('span');
        nick.className = 'fw-semibold me-2 text-muted small';
        nick.textContent = a.nickname;

        const txt = document.createElement('span');
        txt.textContent = a.text;
        if (a.is_hidden) {
            li.classList.add('opacity-50');
            txt.classList.add('text-muted');
        }

        textDiv.appendChild(nick);
        textDiv.appendChild(txt);

        if (MODERATION_MODE) {
            const badge = document.createElement('span');
            badge.className = 'badge text-bg-secondary ms-2 ' + (a.is_hidden ? '' : 'd-none');
            badge.textContent = MSG_HIDDEN_BADGE;

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-sm btn-outline-secondary ms-2';
            btn.textContent = a.is_hidden ? MSG_UNHIDE : MSG_HIDE;
            btn.addEventListener('click', () => toggleHide(a.id, a.is_hidden, li, btn, badge));

            li.appendChild(textDiv);
            li.appendChild(badge);
            li.appendChild(btn);
        } else {
            li.appendChild(textDiv);
        }

        list.appendChild(li);
    });
}

async function toggleHide(answerId, currentlyHidden, li, btn, badge) {
    const action = currentlyHidden ? 'unhide' : 'hide';
    btn.disabled = true;
    try {
        const res = await fetch('/api/v1/answers/' + answerId + '/' + action, {
            method:  'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': CSRF_TOKEN,
            },
            body: '{}',
        });
        const data = await res.json();
        if (data.success) {
            const nowHidden = data.data.is_hidden;
            li.classList.toggle('opacity-50', nowHidden);
            btn.textContent = nowHidden ? MSG_UNHIDE : MSG_HIDE;
            badge.classList.toggle('d-none', !nowHidden);
        }
    } finally {
        btn.disabled = false;
    }
}

// ── Polling every 2 s (FR-50, NFR-02) ──────────────────────────────────────────
function startPolling() {
    fetchResults();
    pollTimer = setInterval(fetchResults, 2000);
}

startPolling();
</script>
<?php
$pageTitle = htmlspecialchars(t('results.title'), ENT_QUOTES, 'UTF-8') . ' — ' . t('app.name');
$content   = ob_get_clean();
include __DIR__ . '/../../layouts/admin.php';
