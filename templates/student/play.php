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
<div class="row justify-content-center mt-5">
    <div class="col-12 col-sm-8 col-md-6 col-lg-4">
        <div class="card border-secondary">
            <div class="card-body text-center py-5">
                <p class="fs-5 text-secondary mb-0">
                    <?= htmlspecialchars(t('error.session_closed'), ENT_QUOTES, 'UTF-8') ?>
                </p>
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
<div class="row justify-content-center mt-5">
    <div class="col-12 col-sm-8 col-md-6 col-lg-4">
        <div class="card border-warning">
            <div class="card-body text-center py-5">
                <p class="fs-5 text-warning-emphasis mb-0">
                    <?= htmlspecialchars(t('session.paused_message'), ENT_QUOTES, 'UTF-8') ?>
                </p>
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
    header('Location: /play/' . rawurlencode($shortCode) . '/answered');
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
        header('Location: /play/' . rawurlencode($shortCode) . '/answered');
        exit;
    } catch (DuplicateAnswerException) {
        http_response_code(303);
        header('Location: /play/' . rawurlencode($shortCode) . '/answered');
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

ob_start();
?>
<div class="row justify-content-center mt-4 mt-md-5">
    <div class="col-12 col-sm-10 col-md-8 col-lg-6">
        <div class="card shadow-sm">
            <div class="card-header text-muted small">
                <code><?= htmlspecialchars($shortCode, ENT_QUOTES, 'UTF-8') ?></code>
                &mdash; <?= htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8') ?>
            </div>
            <div class="card-body p-4">

                <p class="text-muted small mb-2">
                    <?= htmlspecialchars(t('question.type.' . $qType), ENT_QUOTES, 'UTF-8') ?>
                </p>
                <?php if (! empty($question['image_path'])): ?>
                <div class="text-center mb-3">
                    <img src="/<?= htmlspecialchars($question['image_path'], ENT_QUOTES, 'UTF-8') ?>"
                         alt="<?= htmlspecialchars(t('question.image.preview_alt'), ENT_QUOTES, 'UTF-8') ?>"
                         class="img-fluid rounded"
                         style="max-height:300px">
                </div>
                <?php endif; ?>
                <h2 class="h5 mb-4"><?= $qText ?></h2>

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
                            class="form-control"
                            rows="4"
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
                            class="btn btn-outline-primary text-start option-btn"
                            data-option-id="<?= (int) $opt['id'] ?>"
                        >
                            <?= htmlspecialchars($opt['option_text'], ENT_QUOTES, 'UTF-8') ?>
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

                    <button
                        type="submit"
                        id="submit-btn"
                        class="btn btn-primary btn-lg w-100 mt-4"
                        <?= $qType !== 'open_text' ? 'disabled' : '' ?>
                    >
                        <?= htmlspecialchars(t('student.answer.submit'), ENT_QUOTES, 'UTF-8') ?>
                    </button>
                </form>

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
}

function clearError() {
    errorBox.classList.add('d-none');
}

// ── Option-button selection ──────────────────────────────────────────────────
if (QUESTION_TYPE !== 'open_text') {
    const hiddenInput = document.getElementById('selected_option_id');
    const buttons     = document.querySelectorAll('.option-btn');

    buttons.forEach(btn => {
        btn.addEventListener('click', function () {
            // Deselect all, select this one
            buttons.forEach(b => {
                b.classList.remove('btn-primary');
                b.classList.add('btn-outline-primary');
            });
            btn.classList.remove('btn-outline-primary');
            btn.classList.add('btn-primary');

            hiddenInput.value = btn.dataset.optionId;
            submitBtn.disabled = false;
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

    try {
        const res  = await fetch('/api/v1/answers', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(body),
        });
        const data = await res.json();

        if (data.success || res.status === 409) {
            // 409 = duplicate: treat as success (already answered)
            window.location.href = '/play/' + SHORT_CODE + '/answered';
        } else {
            showError((data.error && data.error.message) ? data.error.message : MSG_SERVER);
            submitBtn.disabled = false;
        }
    } catch {
        showError(MSG_SERVER);
        submitBtn.disabled = false;
    }
});
</script>
<?php
$content   = ob_get_clean();
$pageTitle = htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8') . ' — ' . t('app.name');
include __DIR__ . '/../layouts/public.php';
