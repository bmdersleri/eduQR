<?php
/**
 * Student answer page — /play/{short_code}
 * T-709: renders active question and submits answer (JS path)
 * T-710: no-JS fallback via plain HTML form POST
 */

use EduQR\Repositories\AnswerRepository;
use EduQR\Repositories\OptionRepository;
use EduQR\Repositories\ParticipantRepository;
use EduQR\Repositories\QuestionRepository;
use EduQR\Repositories\SessionRepository;
use EduQR\Services\AnswerService;
use EduQR\Services\DuplicateAnswerException;

$shortCode = $p['short_code'] ?? '';

// ── 1. Resolve session ────────────────────────────────────────────────────────
$sessionRepo = new SessionRepository();
$session     = $sessionRepo->findByShortCode($shortCode);

if ($session === null) {
    http_response_code(404);
    include __DIR__ . '/../../templates/errors/404.php';
    exit;
}

// ── 2. Gate: closed / paused sessions ────────────────────────────────────────
if ($session['status'] === 'closed') {
    ob_start(); ?>
<div class="row justify-content-center py-4 py-lg-5">
    <div class="col-12 col-lg-6 col-xl-5">
        <div class="eduqr-student-stage eduqr-student-stage--center">
            <span class="eduqr-icon-badge mx-auto mb-3"><?= eduqr_icon('clock') ?></span>
            <h1 class="h4 mb-2"><?= htmlspecialchars(t('error.session_closed'), ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="text-muted mb-0"><?= htmlspecialchars(t('error.session_closed'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
        </div>
    </div>
</div>
<?php
    $content   = ob_get_clean();
    $pageTitle = t('app.name');
    include __DIR__ . '/../layouts/public.php';
    exit;
}

if ($session['status'] === 'paused') {
    ob_start(); ?>
<div class="row justify-content-center py-4 py-lg-5">
    <div class="col-12 col-lg-6 col-xl-5">
        <div class="eduqr-student-stage eduqr-student-stage--center eduqr-student-stage--quiet">
            <div class="eduqr-hourglass" aria-hidden="true">
                <div class="eduqr-hourglass-frame"></div>
                <div class="eduqr-hourglass-sand top"></div>
                <div class="eduqr-hourglass-stream"></div>
                <div class="eduqr-hourglass-sand bottom"></div>
            </div>
            <h1 class="h4 mb-2"><?= htmlspecialchars(t('session.paused_message'), ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="text-muted mb-0"><?= htmlspecialchars(t('session.paused_message'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
        </div>
    </div>
</div>
<?php
    $content   = ob_get_clean();
    $pageTitle = t('app.name');
    include __DIR__ . '/../layouts/public.php';
    exit;
}

// ── 3. Resolve participant from cookie ────────────────────────────────────────
$participantId = (int) ($_COOKIE['eduqr_participant'] ?? 0);
if ($participantId <= 0) {
    // Not joined — redirect to join page
    http_response_code(302);
    header('Location: /join/' . rawurlencode($shortCode));
    exit;
}

// ── 4. Load active question ───────────────────────────────────────────────────
$questionRepo = new QuestionRepository();
$question     = $questionRepo->findActiveBySessionCode($shortCode);

if ($question === null) {
    // No active question right now — go to wait screen
    http_response_code(302);
    header('Location: /join/' . rawurlencode($shortCode) . '/wait');
    exit;
}

// ── 5. Load options ───────────────────────────────────────────────────────────
$optionRepo = new OptionRepository();
$options    = $optionRepo->findByQuestion((int) $question['id']);

// ── 6. Check if already answered ─────────────────────────────────────────────
$answerRepo  = new AnswerRepository();
$alreadyDone = $answerRepo->existsByParticipantAndQuestion($participantId, (int) $question['id']);

if ($alreadyDone) {
    // Already answered — show confirmation screen
    http_response_code(302);
    header('Location: /play/' . rawurlencode($shortCode) . '/answered?answered_q=' . (int) $question['id']);
    exit;
}

// ── 7. No-JS form POST handler (T-710) ───────────────────────────────────────
$noJsError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $service = new AnswerService(
        new AnswerRepository(),
        $questionRepo,
        $sessionRepo,
        new ParticipantRepository(),
        $optionRepo,
    );

    $body = [
        'question_id'        => (int) $question['id'],
        'selected_option_id' => isset($_POST['selected_option_id']) && $_POST['selected_option_id'] !== ''
            ? (int) $_POST['selected_option_id']
            : null,
        'answer_text'        => $_POST['answer_text'] ?? null,
    ];

    try {
        $service->submit($participantId, $body);
        // Redirect to confirmation (PRG pattern)
        http_response_code(303);
        header('Location: /play/' . rawurlencode($shortCode) . '/answered?answered_q=' . (int) $question['id']);
        exit;
    } catch (DuplicateAnswerException) {
        http_response_code(303);
        header('Location: /play/' . rawurlencode($shortCode) . '/answered?answered_q=' . (int) $question['id']);
        exit;
    } catch (\InvalidArgumentException $e) {
        $noJsError = t('error.invalid_answer_shape');
    } catch (\RuntimeException $e) {
        $noJsError = match ($e->getMessage()) {
            'question_not_active' => t('error.question_closed'),
            'session_paused'      => t('error.session_paused'),
            'session_closed'      => t('error.session_closed'),
            'invalid_option'      => t('error.invalid_answer_shape'),
            default               => t('error.server_error'),
        };
    }
}

// ── 8. Render question form ────────────────────────────────────────────────────
$qType = $question['question_type'];
$qText = htmlspecialchars($question['question_text'], ENT_QUOTES, 'UTF-8');
$optionCount = count($options);

ob_start();
?>
<div class="eduqr-student-screen">
<div class="row justify-content-center w-100 g-0">
    <div class="col-12 col-xl-8 col-lg-9">
        <div class="eduqr-student-shell">
            <div class="eduqr-student-hero">
                <?php $courseTitle = trim((string) ($session['course_title'] ?? '')); ?>
                <div class="eduqr-question-meta mb-3">
                    <span class="eduqr-chip"><span class="eduqr-badge-dot"></span><?= htmlspecialchars(t('student.answer.live_question'), ENT_QUOTES, 'UTF-8') ?></span>
                    <?php if ($courseTitle !== ''): ?>
                        <span class="eduqr-chip"><?= eduqr_icon('chart') ?> <?= htmlspecialchars($courseTitle, ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endif; ?>
                    <span class="eduqr-chip"><?= eduqr_icon('spark') ?> <?= htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="eduqr-chip"><?= eduqr_icon('qr') ?> <code><?= htmlspecialchars($shortCode, ENT_QUOTES, 'UTF-8') ?></code></span>
                </div>
                <h1 class="display-6 fw-bold mb-2"><?= $qText ?></h1>
                <p class="text-muted mb-0">
                    <?= htmlspecialchars(t('question.type.' . $qType), ENT_QUOTES, 'UTF-8') ?>
                    <?php if ($optionCount > 0): ?>
                        &middot; <?= htmlspecialchars(tn('student.answer.option_count', $optionCount, ['count' => (string) $optionCount]), ENT_QUOTES, 'UTF-8') ?>
                    <?php endif; ?>
                </p>
            </div>

            <div class="eduqr-student-stage eduqr-fade-scale">
                <?php if (! empty($question['image_path'])): ?>
                <div class="text-center mb-4">
                    <div class="eduqr-image-frame d-inline-block" style="max-width:100%">
                        <img src="/<?= htmlspecialchars($question['image_path'], ENT_QUOTES, 'UTF-8') ?>"
                             alt="<?= htmlspecialchars(t('question.image.preview_alt'), ENT_QUOTES, 'UTF-8') ?>"
                             class="img-fluid rounded"
                             style="max-height:320px;object-fit:contain">
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($noJsError !== ''): ?>
                <div class="alert alert-danger" role="alert">
                    <?= htmlspecialchars($noJsError, ENT_QUOTES, 'UTF-8') ?>
                </div>
                <?php endif; ?>

                <div id="answer-error" class="alert alert-danger d-none" role="alert"></div>

                <!-- No-JS fallback (T-710): plain form POST -->
                <form
                    id="answer-form"
                    method="POST"
                    action="/play/<?= rawurlencode($shortCode) ?>"
                    novalidate
                >
                <?php if ($qType === 'open_text'): ?>
                    <!-- Open text (T-604) -->
                    <div class="mb-3">
                        <textarea
                        id="answer_text"
                        name="answer_text"
                        class="form-control eduqr-glow-focus"
                        rows="5"
                        maxlength="2000"
                        placeholder="<?= htmlspecialchars(t('student.answer.text_placeholder'), ENT_QUOTES, 'UTF-8') ?>"
                        required
                    ></textarea>
                        <div class="form-text text-end">
                            <span id="char-count">0</span> / 2000
                        </div>
                    </div>

                <?php else: ?>
                    <!-- Option-based (multiple_choice, yes_no, likert_5) -->
                    <input type="hidden" name="selected_option_id" id="selected_option_id" value="">

                    <div class="d-grid gap-2" id="option-buttons">
                    <?php foreach ($options as $opt): ?>
                        <button
                            type="button"
                            class="eduqr-answer-option eduqr-opt-in"
                            data-option-id="<?= (int) $opt['id'] ?>"
                        >
                            <span class="eduqr-icon-badge"><?= eduqr_icon('check') ?></span>
                            <span><?= htmlspecialchars($opt['option_text'], ENT_QUOTES, 'UTF-8') ?></span>
                        </button>
                    <?php endforeach; ?>
                    </div>

                    <!-- No-JS: radio buttons shown only when JS disabled -->
                    <noscript>
                    <div class="mt-3">
                    <?php foreach ($options as $opt): ?>
                        <div class="form-check mb-2">
                            <input
                                class="form-check-input"
                                type="radio"
                                name="selected_option_id"
                                id="opt-<?= (int) $opt['id'] ?>"
                                value="<?= (int) $opt['id'] ?>"
                                required
                            >
                            <label class="form-check-label" for="opt-<?= (int) $opt['id'] ?>">
                                <?= htmlspecialchars($opt['option_text'], ENT_QUOTES, 'UTF-8') ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                    </div>
                    </noscript>
                <?php endif; ?>

                    <div class="eduqr-sticky-cta">
                    <button
                        type="submit"
                        id="submit-btn"
                        class="btn btn-primary btn-lg w-100 eduqr-ripple"
                        <?= $qType !== 'open_text' ? 'disabled' : '' ?>
                    >
                        <?= htmlspecialchars(t('student.answer.submit'), ENT_QUOTES, 'UTF-8') ?>
                    </button>

                    <a href="/play/<?= rawurlencode($shortCode) ?>/batch"
                       class="btn btn-outline-secondary w-100">
                        <?= htmlspecialchars(t('student.batch.open'), ENT_QUOTES, 'UTF-8') ?>
                    </a>
                    </div>
                </form>

            </div>
        </div>
    </div>
</div>
</div>

<script>
const SHORT_CODE    = <?= json_encode($shortCode) ?>;
const QUESTION_ID   = <?= (int) $question['id'] ?>;
const QUESTION_TYPE = <?= json_encode($qType) ?>;
const MSG_SERVER    = <?= json_encode(t('error.server_error')) ?>;
const MSG_REQUIRED  = <?= json_encode(t('validation.required')) ?>;

const form      = document.getElementById('answer-form');
const errorBox  = document.getElementById('answer-error');
const submitBtn = document.getElementById('submit-btn');

function showError(msg) {
    errorBox.textContent = msg;
    errorBox.classList.remove('d-none');
    errorBox.style.animation = 'slide-down .35s ease-out';
}

function clearError() {
    errorBox.classList.add('d-none');
}

// ── Option-button selection ──────────────────────────────────────────────────
if (QUESTION_TYPE !== 'open_text') {
    const hiddenInput = document.getElementById('selected_option_id');
    const buttons     = document.querySelectorAll('.eduqr-answer-option');

    buttons.forEach(btn => {
        btn.addEventListener('click', function () {
            buttons.forEach(b => {
                b.classList.remove('is-selected', 'eduqr-opt-bounce');
            });
            this.classList.add('is-selected', 'eduqr-opt-bounce');
            hiddenInput.value = this.dataset.optionId;
            submitBtn.disabled = false;
            submitBtn.classList.add('eduqr-btn-glow');
        });
    });
}

// ── Character counter for open_text ─────────────────────────────────────────
if (QUESTION_TYPE === 'open_text') {
    const textarea  = document.getElementById('answer_text');
    const counter   = document.getElementById('char-count');
    textarea.addEventListener('input', function () {
        counter.textContent = textarea.value.length;
    });
    textarea.addEventListener('input', function enableBtn() {
        submitBtn.disabled = !this.value.trim();
        if (!submitBtn.disabled) submitBtn.classList.add('eduqr-btn-glow');
        else submitBtn.classList.remove('eduqr-btn-glow');
    });
}

// ── Intercept form submit for JS path ─────────────────────────────────────────
form.addEventListener('submit', async function (e) {
    e.preventDefault();
    clearError();

    let body = { question_id: QUESTION_ID };

    if (QUESTION_TYPE === 'open_text') {
        const text = document.getElementById('answer_text').value.trim();
        if (!text) {
            showError(MSG_REQUIRED);
            return;
        }
        body.answer_text = text;
    } else {
        const optId = document.getElementById('selected_option_id').value;
        if (!optId) {
            showError(MSG_REQUIRED);
            return;
        }
        body.selected_option_id = parseInt(optId, 10);
    }

    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="eduqr-spinner me-2"></span>' + <?= json_encode(t('common.loading')) ?>;

    try {
        const res  = await fetch('/api/v1/answers', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(body),
        });
        const data = await res.json();

        if (data.success || res.status === 409) {
            submitBtn.innerHTML = '✓ ' + <?= json_encode(t('student.answer.submitted')) ?>;
            await new Promise(r => setTimeout(r, 350));
            window.location.href = '/play/' + SHORT_CODE + '/answered?answered_q=' + QUESTION_ID;
        } else {
            showError((data.error && data.error.message) ? data.error.message : MSG_SERVER);
            submitBtn.disabled = false;
            submitBtn.innerHTML = <?= json_encode(t('student.answer.submit')) ?>;
            submitBtn.classList.remove('eduqr-btn-glow');
        }
    } catch {
        showError(MSG_SERVER);
        submitBtn.disabled = false;
        submitBtn.innerHTML = <?= json_encode(t('student.answer.submit')) ?>;
        submitBtn.classList.remove('eduqr-btn-glow');
    }
});
</script>
<?php
$content   = ob_get_clean();
$pageTitle = htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8') . ' — ' . t('app.name');
include __DIR__ . '/../layouts/public.php';
