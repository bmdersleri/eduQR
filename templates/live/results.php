<?php
/**
 * Projector live results view — /live/{short_code}/results  (T-807)
 *
 * Large-type display of current question results for classroom projection.
 * Polls results every 3 seconds and renders a horizontal bar chart with
 * plain Bootstrap markup so it works without external JS assets.
 * No authentication required — uses the public short_code.
 *
 * When session.show_results_to_students = 0, the projector still shows
 * results (instructor-controlled display, not student-facing).
 */

use EduQR\Container;

$shortCode = strtoupper(trim($p['short_code'] ?? ''));

$sessionRepo  = Container::sessionRepository();
$session      = $sessionRepo->findByShortCode($shortCode);

if ($session === null) {
    http_response_code(404);
    include __DIR__ . '/../../templates/errors/404.php';
    exit;
}

$sessionId    = (int) $session['id'];
$questionRepo = Container::questionRepository();
$activeQ      = $questionRepo->findActiveBySessionCode($shortCode);
$activeQId    = $activeQ !== null ? (int) $activeQ['id'] : 0;

ob_start();
?>
<div class="eduqr-live-surface p-3 p-lg-4" style="min-height:90vh">
    <div class="eduqr-projector-header">
        <div class="eduqr-projector-title">
            <span class="eduqr-icon-badge"><?= eduqr_icon('chart') ?></span>
            <div>
                <h1 class="h4 mb-1"><?= htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8') ?></h1>
                <div class="text-white-50" style="font-size:clamp(0.9rem,1.5vw,1.1rem)">
                    <code><?= htmlspecialchars($shortCode, ENT_QUOTES, 'UTF-8') ?></code>
                </div>
            </div>
        </div>
        <div class="eduqr-chip">
            <?= eduqr_icon('clock') ?>
            <?= htmlspecialchars(t('results.title'), ENT_QUOTES, 'UTF-8') ?>
        </div>
    </div>

    <div class="row g-0">
        <!-- Question text -->
        <div class="col-12 text-center mb-4" id="question-text-area">
            <p class="text-white-50 mb-2" id="question-type-label" style="font-size:clamp(0.9rem,1.5vw,1.2rem)"></p>
            <div id="question-image-area" class="mb-3 d-none">
                <img src="" alt="" class="img-fluid rounded" style="max-height:280px">
            </div>
            <h2 id="question-text" class="fw-bold mb-3" style="font-size:clamp(1.6rem,3.5vw,3rem)">
                <?= $activeQ
                    ? htmlspecialchars($activeQ['question_text'], ENT_QUOTES, 'UTF-8')
                    : htmlspecialchars(t('question.no_active'), ENT_QUOTES, 'UTF-8') ?>
            </h2>
            <p class="text-white-50" id="answer-count-label" style="font-size:clamp(1rem,2vw,1.5rem)"></p>
        </div>

        <!-- Chart (option-based) -->
        <div class="col-12 d-none" id="chart-area"></div>

        <!-- Word cloud -->
        <div class="col-12 d-none mb-4" id="word-cloud-area"></div>

        <!-- Open-text list -->
        <div class="col-12 d-none" id="open-text-area">
            <ul id="open-text-list" class="list-unstyled px-4"></ul>
        </div>

        <!-- No results yet -->
        <div class="col-12 text-center text-white-50 d-none" id="waiting-area">
            <div class="spinner-border text-secondary mb-3" role="status">
                <span class="visually-hidden"><?= htmlspecialchars(t('common.loading'), ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <p><?= htmlspecialchars(t('student.waiting.message'), ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    </div>
</div>

<script>
const SESSION_ID      = <?= $sessionId ?>;
const SHORT_CODE      = <?= json_encode($shortCode) ?>;
let   ACTIVE_Q_ID     = <?= $activeQId ?>;

const questionText  = document.getElementById('question-text');
const questionType  = document.getElementById('question-type-label');
const answerCount   = document.getElementById('answer-count-label');
const chartArea     = document.getElementById('chart-area');
const wordCloudArea = document.getElementById('word-cloud-area');
const textArea      = document.getElementById('open-text-area');
const waitingArea   = document.getElementById('waiting-area');

const TYPE_LABELS = <?= json_encode([
    'multiple_choice' => t('question.type.multiple_choice'),
    'yes_no'          => t('question.type.yes_no'),
    'likert_5'        => t('question.type.likert_5'),
    'open_text'       => t('question.type.open_text'),
]) ?>;

const MSG_ANSWER_COUNT = <?= json_encode(t('results.answer_count')) ?>;
const MSG_NO_ACTIVE    = <?= json_encode(t('question.no_active')) ?>;
const MSG_WORD_CLOUD   = <?= json_encode(t('results.word_cloud')) ?>;
const MSG_WORD_EMPTY   = <?= json_encode(t('results.word_cloud.empty')) ?>;

function extractActiveQuestion(payload) {
    if (!payload || !payload.success || !payload.data) {
        return null;
    }

    if (Object.prototype.hasOwnProperty.call(payload.data, 'question')) {
        return payload.data.question;
    }

    return payload.data;
}

async function pollResults() {
    try {
        // Check for an active question change
        const qRes  = await fetch(eduqrPath('/api/v1/sessions/' + SHORT_CODE + '/active-question'));
        const qData = await qRes.json();
        const q = extractActiveQuestion(qData);

        if (!q) {
            // No active question
            ACTIVE_Q_ID = 0;
            questionText.textContent = MSG_NO_ACTIVE;
            questionType.textContent = '';
            answerCount.textContent  = '';
            chartArea.classList.add('d-none');
            wordCloudArea.classList.add('d-none');
            textArea.classList.add('d-none');
            waitingArea.classList.remove('d-none');
            document.getElementById('chart-area').innerHTML = '';
            wordCloudArea.innerHTML = '';
            return;
        }

        ACTIVE_Q_ID = q.id;

        // Update question header
        questionText.textContent = q.text;
        questionType.textContent = TYPE_LABELS[q.type] ?? q.type;
        waitingArea.classList.add('d-none');

        // Question image
        const imgArea = document.getElementById('question-image-area');
        if (q.image_url) {
            imgArea.querySelector('img').src = q.image_url;
            imgArea.classList.remove('d-none');
        } else {
            imgArea.classList.add('d-none');
        }

        // Fetch results for this question
        const rRes  = await fetch(eduqrPath('/api/v1/sessions/' + SESSION_ID + '/results?question_id=' + ACTIVE_Q_ID));
        const rData = await rRes.json();

        if (!rData.success || !rData.data || !rData.data.length) return;
        const result = rData.data[0];

        answerCount.textContent = MSG_ANSWER_COUNT.replace('{count}', result.answer_count ?? 0);

        if (q.type === 'open_text') {
            chartArea.classList.add('d-none');
            wordCloudArea.classList.remove('d-none');
            textArea.classList.remove('d-none');
            renderWordCloud(result.word_cloud ?? []);
            renderOpenText(result.answers ?? []);
            document.getElementById('chart-area').innerHTML = '';
        } else {
            textArea.classList.add('d-none');
            wordCloudArea.classList.add('d-none');
            chartArea.classList.remove('d-none');
            renderBarChart(result.options ?? []);
        }

    } catch {
        // Ignore network errors, keep polling
    }
}

function renderBarChart(options) {
    const chartArea = document.getElementById('chart-area');
    chartArea.classList.remove('d-none');
    chartArea.innerHTML = '';

    const max = Math.max(...options.map((o) => Number(o.count) || 0), 1);

    options.forEach((o, index) => {
        const count = Number(o.count) || 0;
        const percent = Number(o.percent) || 0;
        const row = document.createElement('div');
        row.className = 'eduqr-statcard mb-4';
        row.style.background = 'rgba(255,255,255,.05)';
        row.style.borderColor = 'rgba(255,255,255,.08)';

        const header = document.createElement('div');
        header.className = 'd-flex justify-content-between align-items-end mb-2';
        header.innerHTML = `
            <span class="fw-semibold" style="font-size:clamp(1.2rem,2vw,1.8rem)">${escapeHtml(o.text)}</span>
            <span class="eduqr-chip" style="background:rgba(255,255,255,.06);color:#eef2ff;border-color:rgba(255,255,255,.08)">${count} (${percent}%)</span>
        `;

        const progress = document.createElement('div');
        progress.className = 'progress';
        progress.style.height = '2.4rem';
        progress.style.borderRadius = '999px';
        progress.style.background = 'rgba(255,255,255,.08)';

        const bar = document.createElement('div');
        bar.className = 'progress-bar progress-bar-striped progress-bar-animated';
        bar.style.width = `${Math.max(4, (count / max) * 100)}%`;
        bar.style.background = `linear-gradient(135deg, ${BAR_COLORS[index % BAR_COLORS.length]}, color-mix(in oklab, ${BAR_COLORS[index % BAR_COLORS.length]} 45%, white))`;
        bar.style.transition = 'width .7s cubic-bezier(.22,1,.36,1)';
        bar.style.boxShadow = '0 14px 28px rgba(0,0,0,.18)';
        bar.style.borderRadius = '999px';
        bar.style.fontSize = '1rem';
        bar.textContent = count > 0 ? String(count) : '';

        progress.appendChild(bar);
        row.appendChild(header);
        row.appendChild(progress);
        chartArea.appendChild(row);
    });
}

function escapeHtml(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

const BAR_COLORS = ['#0d6efd', '#198754', '#ffc107', '#dc3545', '#0dcaf0', '#6f42c1', '#fd7e14', '#20c997'];

function renderOpenText(answers) {
    const list = document.getElementById('open-text-list');
    list.innerHTML = '';
    const visible = answers.filter(a => !a.is_hidden).slice(0, 8); // max 8 lines on screen
    visible.forEach(a => {
        const li = document.createElement('li');
        li.className = 'mb-3 eduqr-statcard';
        li.innerHTML = `
            <span class="text-white-50 me-3" style="font-size:clamp(1rem,1.5vw,1.3rem)">
                ${escHtml(a.nickname)}:
            </span>
            <span style="font-size:clamp(1.2rem,2vw,1.8rem)">${escHtml(a.text)}</span>
        `;
        list.appendChild(li);
    });
}

function renderWordCloud(words) {
    const area = document.getElementById('word-cloud-area');
    if (!Array.isArray(words) || words.length === 0) {
        area.innerHTML = `<p class="text-white-50 text-center mb-0">${escHtml(MSG_WORD_EMPTY)}</p>`;
        return;
    }

    const chips = words.map((item) => {
        const term = escHtml(String(item.term ?? ''));
        const count = Number(item.count) || 0;
        const weight = Math.max(0.35, Math.min(1.4, Number(item.weight) || 0));
        const fontSize = (1.0 + (weight * 0.8)).toFixed(2);
        return `<span class="badge rounded-pill text-bg-light text-dark me-2 mb-2" style="font-size:${fontSize}rem">${term} <small>(${count})</small></span>`;
    }).join('');

    area.innerHTML = `
        <div class="eduqr-statcard mb-4" style="background:rgba(255,255,255,.05);border-color:rgba(255,255,255,.08)">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h3 class="h5 mb-0 text-white">${escHtml(MSG_WORD_CLOUD)}</h3>
            </div>
            <div class="d-flex flex-wrap">${chips}</div>
        </div>
    `;
}

function escHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// Poll every 3 seconds (FR-54)
pollResults();
setInterval(pollResults, 3000);
</script>
<?php
$content   = ob_get_clean();
$pageTitle = htmlspecialchars(t('results.title'), ENT_QUOTES, 'UTF-8') . ' — ' . t('app.name');
include __DIR__ . '/../../templates/layouts/projector.php';
