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
                        <img src="<?= htmlspecialchars(eduqr_path($question['image_path']), ENT_QUOTES, 'UTF-8') ?>"
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
                    action="<?= htmlspecialchars(eduqr_path('/play/' . rawurlencode($shortCode)), ENT_QUOTES, 'UTF-8') ?>"
                    novalidate
                >
                <?php if ($qType === 'open_text' || $qType === 'fill_in_the_blank'): ?>
                    <?php if ($qType === 'fill_in_the_blank'): ?>
                    <!-- Fill in the blank (FR-31) -->
                    <div class="mb-3">
                        <input
                            type="text"
                            id="answer_text"
                            name="answer_text"
                            class="form-control eduqr-glow-focus"
                            maxlength="200"
                            placeholder="<?= htmlspecialchars(t('student.answer.fill_in_the_blank_placeholder'), ENT_QUOTES, 'UTF-8') ?>"
                            required
                        >
                    </div>
                    <?php else: ?>
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
                    <?php endif; ?>

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
                        <?= ! in_array($qType, ['open_text', 'fill_in_the_blank'], true) ? 'disabled' : '' ?>
                    >
                        <?= htmlspecialchars(t('student.answer.submit'), ENT_QUOTES, 'UTF-8') ?>
                    </button>

                    <a href="<?= htmlspecialchars(eduqr_path('/play/' . rawurlencode($shortCode) . '/batch'), ENT_QUOTES, 'UTF-8') ?>"
                       class="btn btn-outline-secondary w-100">
                        <?= htmlspecialchars(t('student.batch.open'), ENT_QUOTES, 'UTF-8') ?>
                    </a>
                    </div>
                </form>

                <!-- Comprehension reactions (FR-48) — shown for every question type.
                     No counts are rendered; totals are instructor-only. -->
                <div class="mt-4" id="reaction-block">
                    <p class="text-muted small mb-2">
                        <?= htmlspecialchars(t('student.reaction.prompt'), ENT_QUOTES, 'UTF-8') ?>
                    </p>
                    <div class="d-flex gap-2">
                        <button
                            type="button"
                            class="btn btn-outline-secondary flex-fill eduqr-reaction"
                            data-reaction="got_it"
                        >
                            <span aria-hidden="true">👍</span>
                            <?= htmlspecialchars(t('student.reaction.got_it'), ENT_QUOTES, 'UTF-8') ?>
                        </button>
                        <button
                            type="button"
                            class="btn btn-outline-secondary flex-fill eduqr-reaction"
                            data-reaction="lost"
                        >
                            <span aria-hidden="true">🤔</span>
                            <?= htmlspecialchars(t('student.reaction.lost'), ENT_QUOTES, 'UTF-8') ?>
                        </button>
                    </div>
                    <div id="reaction-status" class="form-text" role="status" aria-live="polite"></div>
                </div>

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
if (QUESTION_TYPE !== 'open_text' && QUESTION_TYPE !== 'fill_in_the_blank') {
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

// ── Enable submit for fill_in_the_blank (no char counter) ────────────────────
if (QUESTION_TYPE === 'fill_in_the_blank') {
    const input = document.getElementById('answer_text');
    input.addEventListener('input', function () {
        submitBtn.disabled = !this.value.trim();
        if (!submitBtn.disabled) submitBtn.classList.add('eduqr-btn-glow');
        else submitBtn.classList.remove('eduqr-btn-glow');
    });
}

// ── Comprehension reactions (FR-48) ──────────────────────────────────────────
// Fire-and-forget: the response carries no counts, so nothing is rendered
// beyond the locally selected state.
const MSG_REACTED       = <?= json_encode(t('student.reaction.recorded')) ?>;
const reactionButtons   = document.querySelectorAll('.eduqr-reaction');
const reactionStatusBox = document.getElementById('reaction-status');

reactionButtons.forEach(btn => {
    btn.addEventListener('click', async function () {
        const reaction = this.dataset.reaction;

        reactionButtons.forEach(b => {
            b.classList.remove('active');
            b.disabled = true;
        });
        this.classList.add('active');

        try {
            const res  = await fetch(eduqrPath('/api/v1/reactions'), {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ question_id: QUESTION_ID, reaction: reaction }),
            });
            const data = await res.json();

            if (data.success) {
                reactionStatusBox.textContent = MSG_REACTED;
            } else {
                this.classList.remove('active');
                reactionStatusBox.textContent = (data.error && data.error.message) ? data.error.message : MSG_SERVER;
            }
        } catch {
            this.classList.remove('active');
            reactionStatusBox.textContent = MSG_SERVER;
        }

        reactionButtons.forEach(b => { b.disabled = false; });
    });
});

// ── Intercept form submit for JS path ─────────────────────────────────────────
form.addEventListener('submit', async function (e) {
    e.preventDefault();
    clearError();

    let body = { question_id: QUESTION_ID };

    if (QUESTION_TYPE === 'open_text' || QUESTION_TYPE === 'fill_in_the_blank') {
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
        const res  = await fetch(eduqrPath('/api/v1/answers'), {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(body),
        });
        const data = await res.json();

        if (data.success || res.status === 409) {
            submitBtn.innerHTML = '✓ ' + <?= json_encode(t('student.answer.submitted')) ?>;
            await new Promise(r => setTimeout(r, 350));
            window.location.href = eduqrPath('/play/' + SHORT_CODE + '/answered?answered_q=' + QUESTION_ID);
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
