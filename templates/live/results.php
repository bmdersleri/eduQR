<?php
/**
 * Projector live results view — /live/{short_code}/results  (T-807)
 *
 * Large-type display of current question results for classroom projection.
 * Polls results every 3 seconds and renders a horizontal bar chart.
 * No authentication required — uses the public short_code.
 *
 * When session.show_results_to_students = 0, the projector still shows
 * results (instructor-controlled display, not student-facing).
 */

use EduQR\Repositories\QuestionRepository;
use EduQR\Repositories\SessionRepository;

$shortCode = strtoupper(trim($p['short_code'] ?? ''));

$sessionRepo  = new SessionRepository();
$session      = $sessionRepo->findByShortCode($shortCode);

if ($session === null) {
    http_response_code(404);
    include __DIR__ . '/../../templates/errors/404.php';
    exit;
}

$sessionId    = (int) $session['id'];
$questionRepo = new QuestionRepository();
$activeQ      = $questionRepo->findActiveBySessionCode($shortCode);
$activeQId    = $activeQ !== null ? (int) $activeQ['id'] : 0;

ob_start();
?>
<div class="row g-0" style="min-height:90vh">

    <!-- Session header -->
    <div class="col-12 py-2 text-center border-bottom border-secondary mb-4">
        <span class="text-white-50" style="font-size:clamp(0.9rem,1.5vw,1.3rem)">
            <?= htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8') ?>
            &nbsp;&mdash;&nbsp;
            <code style="letter-spacing:0.2em"><?= htmlspecialchars($shortCode, ENT_QUOTES, 'UTF-8') ?></code>
        </span>
    </div>

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
    <div class="col-12" id="chart-area">
        <canvas id="results-chart"></canvas>
    </div>

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

<!-- Chart.js -->
<script src="/assets/js/chart.umd.min.js"
        onerror="this.onerror=null;this.src='https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js'">
</script>

<script>
const SESSION_ID      = <?= $sessionId ?>;
const SHORT_CODE      = <?= json_encode($shortCode) ?>;
let   ACTIVE_Q_ID     = <?= $activeQId ?>;

let chart = null;

const questionText  = document.getElementById('question-text');
const questionType  = document.getElementById('question-type-label');
const answerCount   = document.getElementById('answer-count-label');
const chartArea     = document.getElementById('chart-area');
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

async function pollResults() {
    try {
        // Check for an active question change
        const qRes  = await fetch('/api/v1/sessions/' + SHORT_CODE + '/active-question');
        const qData = await qRes.json();

        if (!qData.success || !qData.data) {
            // No active question
            ACTIVE_Q_ID = 0;
            questionText.textContent = MSG_NO_ACTIVE;
            questionType.textContent = '';
            answerCount.textContent  = '';
            chartArea.classList.add('d-none');
            textArea.classList.add('d-none');
            waitingArea.classList.remove('d-none');
            if (chart) { chart.destroy(); chart = null; }
            return;
        }

        const q = qData.data;
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
        const rRes  = await fetch('/api/v1/sessions/' + SESSION_ID + '/results?question_id=' + ACTIVE_Q_ID);
        const rData = await rRes.json();

        if (!rData.success || !rData.data || !rData.data.length) return;
        const result = rData.data[0];

        answerCount.textContent = MSG_ANSWER_COUNT.replace('{count}', result.answer_count ?? 0);

        if (q.type === 'open_text') {
            chartArea.classList.add('d-none');
            textArea.classList.remove('d-none');
            renderOpenText(result.answers ?? []);
            if (chart) { chart.destroy(); chart = null; }
        } else {
            textArea.classList.add('d-none');
            chartArea.classList.remove('d-none');
            renderChart(result.options ?? []);
        }

    } catch {
        // Ignore network errors, keep polling
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
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => ` ${ctx.parsed.x}  (${pcts[ctx.dataIndex]}%)`,
                    }
                },
                datalabels: { display: false },
            },
            scales: {
                x: {
                    beginAtZero: true,
                    ticks: { color: '#adb5bd', font: { size: 22 } },
                    grid: { color: '#343a40' },
                },
                y: {
                    ticks: { color: '#fff', font: { size: 24 } },
                    grid: { display: false },
                }
            }
        }
    });
}

function renderOpenText(answers) {
    const list = document.getElementById('open-text-list');
    list.innerHTML = '';
    const visible = answers.filter(a => !a.is_hidden).slice(0, 8); // max 8 lines on screen
    visible.forEach(a => {
        const li = document.createElement('li');
        li.className = 'mb-3';
        li.innerHTML = `
            <span class="text-white-50 me-3" style="font-size:clamp(1rem,1.5vw,1.3rem)">
                ${escHtml(a.nickname)}:
            </span>
            <span style="font-size:clamp(1.2rem,2vw,1.8rem)">${escHtml(a.text)}</span>
        `;
        list.appendChild(li);
    });
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
