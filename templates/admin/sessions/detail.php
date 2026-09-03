<?php

use EduQR\Config;
use EduQR\Container;
use EduQR\Middleware\AuthMiddleware;
use EduQR\Middleware\CsrfMiddleware;

$instructor = AuthMiddleware::require();
$csrfToken  = CsrfMiddleware::getToken();
$sessionId  = (int) ($p['id'] ?? 0);

$service = Container::sessionService();

try {
    $session = $service->getSession($sessionId, (int) $instructor['id']);
} catch (\RuntimeException $e) {
    $status = $e->getMessage() === 'session_not_found' ? 404 : 403;
    http_response_code($status);
    include __DIR__ . '/../../../templates/errors/' . $status . '.php';
    exit;
}

$courseRepo = Container::courseRepository();
$course     = $courseRepo->findById((int) $session['course_id']);

$appUrl  = rtrim(Config::get('APP_URL', ''), '/');
$joinUrl = $appUrl . '/join/' . htmlspecialchars($session['short_code'], ENT_QUOTES, 'UTF-8');

$isActive = $session['status'] === 'active';
$isPaused = $session['status'] === 'paused';
$isClosed = $session['status'] === 'closed';

ob_start();
?>
<div class="eduqr-admin-hero mb-4">
    <div>
        <div class="eduqr-kicker mb-3">
            <span class="eduqr-icon-badge"><?= eduqr_icon('qr') ?></span>
            <span><?= htmlspecialchars($course['title'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
            <h1 class="h2 mb-0"><?= htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8') ?></h1>
            <span class="badge <?= $isActive ? 'text-bg-success' : ($isPaused ? 'text-bg-warning' : 'text-bg-secondary') ?>">
                <?= htmlspecialchars(t('session.status.' . $session['status']), ENT_QUOTES, 'UTF-8') ?>
            </span>
        </div>
        <p class="text-muted mb-0">
            <code><?= htmlspecialchars($session['short_code'], ENT_QUOTES, 'UTF-8') ?></code>
            &middot; <?= htmlspecialchars($course['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2 align-self-start">
        <a href="<?= htmlspecialchars(eduqr_path('/admin/courses/' . (int) $session['course_id']), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-secondary btn-sm">
            <?= eduqr_icon('user') ?> <?= htmlspecialchars(t('common.back'), ENT_QUOTES, 'UTF-8') ?>
        </a>
        <?php if (!$isClosed): ?>
        <a href="<?= htmlspecialchars(eduqr_path('/live/' . $session['short_code']), ENT_QUOTES, 'UTF-8') ?>" target="_blank" class="btn btn-primary btn-sm">
            <?= eduqr_icon('chart') ?> <?= htmlspecialchars(t('session.action.view_live'), ENT_QUOTES, 'UTF-8') ?>
        </a>
        <?php endif; ?>
    </div>
</div>

<div class="eduqr-admin-grid mb-4">
    <div class="eduqr-data-card">
        <div class="label"><?= htmlspecialchars(t('session.short_code.label'), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="value"><code><?= htmlspecialchars($session['short_code'], ENT_QUOTES, 'UTF-8') ?></code></div>
    </div>
    <div class="eduqr-data-card">
        <div class="label"><?= htmlspecialchars(t('session.join_url.label'), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="value" style="font-size:1rem;word-break:break-all"><a href="<?= htmlspecialchars($joinUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank"><?= htmlspecialchars($joinUrl, ENT_QUOTES, 'UTF-8') ?></a></div>
    </div>
    <div class="eduqr-data-card">
        <div class="label"><?= htmlspecialchars(t('session.participants.count'), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="value"><span id="participant-count">0</span></div>
    </div>
    <div class="eduqr-data-card">
        <div class="label"><?= htmlspecialchars(t('common.status'), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="value"><?= htmlspecialchars(t('session.status.' . $session['status']), ENT_QUOTES, 'UTF-8') ?></div>
    </div>
    <!-- Comprehension pulse for the active question (FR-48) — instructor only -->
    <div class="eduqr-data-card">
        <div class="label"><?= htmlspecialchars(t('results.reactions.title'), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="value" style="font-size:1.1rem">
            <span class="me-3">
                <span aria-hidden="true">👍</span>
                <?= htmlspecialchars(t('results.reactions.got_it'), ENT_QUOTES, 'UTF-8') ?>:
                <span id="reaction-got-it">0</span>
            </span>
            <span>
                <span aria-hidden="true">🤔</span>
                <?= htmlspecialchars(t('results.reactions.lost'), ENT_QUOTES, 'UTF-8') ?>:
                <span id="reaction-lost">0</span>
            </span>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="eduqr-surface p-4 p-lg-5 mb-4">
            <div class="eduqr-question-board">
            <div class="eduqr-question-toolbar mb-1">
                <?php if (!$isClosed): ?>
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
                <?php endif; ?>
                <a href="<?= htmlspecialchars(eduqr_path('/admin/sessions/' . $sessionId . '/results'), ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-success btn-sm">
                    <?= htmlspecialchars(t('session.action.view_live'), ENT_QUOTES, 'UTF-8') ?>
                </a>
            </div>

            <div class="eduqr-switch-grid mb-1">
                <label class="eduqr-feature justify-content-start gap-3 mb-0">
                    <input class="form-check-input m-0" type="checkbox" role="switch" id="toggle-show-results" <?= (bool) $session['show_results_to_students'] ? 'checked' : '' ?>>
                    <span>
                        <strong><?= htmlspecialchars(t('results.show_to_students'), ENT_QUOTES, 'UTF-8') ?></strong>
                        <span><?= htmlspecialchars(t('results.show_to_students'), ENT_QUOTES, 'UTF-8') ?></span>
                    </span>
                </label>
                <label class="eduqr-feature justify-content-start gap-3 mb-0">
                    <input class="form-check-input m-0" type="checkbox" role="switch" id="toggle-moderation" <?= (bool) $session['moderation_mode'] ? 'checked' : '' ?>>
                    <span>
                        <strong><?= htmlspecialchars(t('session.moderation_mode'), ENT_QUOTES, 'UTF-8') ?></strong>
                        <span><?= htmlspecialchars(t('session.moderation_mode'), ENT_QUOTES, 'UTF-8') ?></span>
                    </span>
                </label>
                <label class="eduqr-feature justify-content-start gap-3 mb-0">
                    <input class="form-check-input m-0" type="checkbox" role="switch" id="toggle-quiz" <?= (bool) $session['is_quiz'] ? 'checked' : '' ?>>
                    <span>
                        <strong><?= htmlspecialchars(t('session.quiz_mode'), ENT_QUOTES, 'UTF-8') ?></strong>
                        <span><?= htmlspecialchars(t('session.quiz_mode'), ENT_QUOTES, 'UTF-8') ?></span>
                    </span>
                </label>
                <label class="eduqr-feature justify-content-start gap-3 mb-0">
                    <input class="form-check-input m-0" type="checkbox" role="switch" id="toggle-exam-mode" <?= (bool) $session['exam_mode'] ? 'checked' : '' ?>>
                    <span>
                        <strong><?= htmlspecialchars(t('session.exam_mode'), ENT_QUOTES, 'UTF-8') ?></strong>
                        <span><?= htmlspecialchars(t('session.exam_mode'), ENT_QUOTES, 'UTF-8') ?></span>
                    </span>
                </label>
            </div>

            <div id="session-feedback" class="alert d-none" role="alert"></div>

            <?php if (!$isClosed): ?>
            <div class="row g-3 mb-4">
                <div class="col-lg-7">
                    <div class="eduqr-question-panel h-100">
                        <div class="eduqr-section-head mb-2">
                            <h2 class="h5 mb-0"><?= htmlspecialchars(t('question.import.title'), ENT_QUOTES, 'UTF-8') ?></h2>
                        </div>
                        <p class="text-muted small mb-3"><?= htmlspecialchars(t('question.import.help'), ENT_QUOTES, 'UTF-8') ?></p>
                        <div id="import-feedback" class="alert d-none" role="alert"></div>
                        <div class="eduqr-form-field mb-3">
                            <label for="import-json"><?= htmlspecialchars(t('question.import.label'), ENT_QUOTES, 'UTF-8') ?></label>
                            <textarea id="import-json" class="form-control" rows="8" placeholder="<?= htmlspecialchars(t('question.import.placeholder'), ENT_QUOTES, 'UTF-8') ?>"></textarea>
                        </div>
                        <button type="button" id="btn-import-questions" class="btn btn-primary btn-sm"><?= htmlspecialchars(t('question.import.submit'), ENT_QUOTES, 'UTF-8') ?></button>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="eduqr-question-panel h-100">
                        <div class="eduqr-section-head mb-2">
                            <h2 class="h5 mb-0"><?= htmlspecialchars(t('question.launch.title'), ENT_QUOTES, 'UTF-8') ?></h2>
                        </div>
                        <p class="text-muted small mb-3"><?= htmlspecialchars(t('question.launch.help'), ENT_QUOTES, 'UTF-8') ?></p>
                        <div id="launch-feedback" class="alert d-none" role="alert"></div>
                        <div class="eduqr-form-field mb-3">
                            <label for="draft-question-select"><?= htmlspecialchars(t('question.launch.select_label'), ENT_QUOTES, 'UTF-8') ?></label>
                            <select id="draft-question-select" class="form-select"></select>
                        </div>
                        <button type="button" id="btn-activate-selected" class="btn btn-success btn-sm"><?= htmlspecialchars(t('question.launch.submit'), ENT_QUOTES, 'UTF-8') ?></button>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="eduqr-section-head">
                <h2 class="h4 mb-0"><?= htmlspecialchars(t('question.new.title'), ENT_QUOTES, 'UTF-8') ?></h2>
                <?php if (!$isClosed): ?>
                <button class="btn btn-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#create-question-panel" aria-expanded="false">
                    <?= eduqr_icon('spark') ?> <?= htmlspecialchars(t('question.action.create'), ENT_QUOTES, 'UTF-8') ?>
                </button>
                <?php endif; ?>
            </div>

            <?php if (!$isClosed): ?>
            <div class="collapse mb-4" id="create-question-panel">
                <div class="eduqr-question-panel">
                    <div id="create-error" class="alert alert-danger d-none" role="alert"></div>
                    <div class="eduqr-form-grid">
                    <div class="eduqr-form-field">
                        <label for="q-text"><?= htmlspecialchars(t('question.field.text'), ENT_QUOTES, 'UTF-8') ?></label>
                        <textarea id="q-text" class="form-control" rows="3" maxlength="500"></textarea>
                    </div>
                    <div class="eduqr-form-field">
                        <label for="q-type"><?= htmlspecialchars(t('question.field.type'), ENT_QUOTES, 'UTF-8') ?></label>
                        <select id="q-type" class="form-select">
                            <option value="multiple_choice"><?= htmlspecialchars(t('question.type.multiple_choice'), ENT_QUOTES, 'UTF-8') ?></option>
                            <option value="open_text"><?= htmlspecialchars(t('question.type.open_text'), ENT_QUOTES, 'UTF-8') ?></option>
                            <option value="yes_no"><?= htmlspecialchars(t('question.type.yes_no'), ENT_QUOTES, 'UTF-8') ?></option>
                            <option value="likert_5"><?= htmlspecialchars(t('question.type.likert_5'), ENT_QUOTES, 'UTF-8') ?></option>
                            <option value="fill_in_the_blank"><?= htmlspecialchars(t('question.type.fill_in_the_blank'), ENT_QUOTES, 'UTF-8') ?></option>
                        </select>
                    </div>
                    <div id="options-section" class="eduqr-form-field">
                        <label><?= htmlspecialchars(t('question.field.options'), ENT_QUOTES, 'UTF-8') ?></label>
                        <div id="options-list" class="eduqr-option-stack"></div>
                        <button type="button" class="btn btn-outline-secondary btn-sm mt-2" id="btn-add-option">
                            <?= eduqr_icon('check') ?> <?= htmlspecialchars(t('question.action.add_option'), ENT_QUOTES, 'UTF-8') ?>
                        </button>
                    </div>
                    <div id="correct-answer-section" class="eduqr-form-field" style="display:none">
                        <label for="q-correct-answer"><?= htmlspecialchars(t('question.field.correct_answer'), ENT_QUOTES, 'UTF-8') ?></label>
                        <input type="text" id="q-correct-answer" class="form-control" maxlength="200">
                    </div>
                    <div class="eduqr-form-field">
                        <label for="q-image"><?= htmlspecialchars(t('question.field.image'), ENT_QUOTES, 'UTF-8') ?></label>
                        <input type="file" id="q-image" class="form-control form-control-sm" accept="image/jpeg,image/png">
                        <div id="q-image-preview" class="mt-2 d-none">
                            <div class="eduqr-image-frame d-inline-block">
                                <img src="" alt="<?= htmlspecialchars(t('question.image.preview_alt'), ENT_QUOTES, 'UTF-8') ?>" class="img-thumbnail" style="max-height:120px">
                            </div>
                        </div>
                    </div>
                    <div class="form-check mb-1">
                        <input class="form-check-input" type="checkbox" id="q-show-results">
                        <label class="form-check-label" for="q-show-results"><?= htmlspecialchars(t('question.field.show_results'), ENT_QUOTES, 'UTF-8') ?></label>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <button type="button" id="btn-create-question" class="btn btn-primary btn-sm"><?= htmlspecialchars(t('question.action.create'), ENT_QUOTES, 'UTF-8') ?></button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="collapse" data-bs-target="#create-question-panel"><?= htmlspecialchars(t('common.cancel'), ENT_QUOTES, 'UTF-8') ?></button>
                    </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div id="question-list" class="eduqr-question-list">
                <p class="text-muted small" id="questions-empty"><?= htmlspecialchars(t('question.no_active'), ENT_QUOTES, 'UTF-8') ?></p>
            </div>

            <div class="eduqr-section-head mt-4">
                <h2 class="h4 mb-0"><?= htmlspecialchars(t('question.bank.title'), ENT_QUOTES, 'UTF-8') ?></h2>
            </div>
            <p class="text-muted small mb-3"><?= htmlspecialchars(t('question.bank.help'), ENT_QUOTES, 'UTF-8') ?></p>
            <div id="bank-feedback" class="alert d-none" role="alert"></div>
            <div class="row g-3">
                <div class="col-lg-6">
                    <div class="eduqr-question-panel h-100">
                        <div class="eduqr-form-field mb-3">
                            <label for="bank-source-title"><?= htmlspecialchars(t('question.bank.source_title.label'), ENT_QUOTES, 'UTF-8') ?></label>
                            <input id="bank-source-title" class="form-control" type="text" maxlength="200" value="<?= htmlspecialchars($session['topic_name'] ?: $session['title'], ENT_QUOTES, 'UTF-8') ?>" placeholder="<?= htmlspecialchars(t('question.bank.source_title.placeholder'), ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="eduqr-form-field mb-3">
                            <label for="bank-notes"><?= htmlspecialchars(t('question.bank.notes.label'), ENT_QUOTES, 'UTF-8') ?></label>
                            <textarea id="bank-notes" class="form-control" rows="8" placeholder="<?= htmlspecialchars(t('question.bank.notes.placeholder'), ENT_QUOTES, 'UTF-8') ?>"></textarea>
                            <div class="form-text"><?= htmlspecialchars(t('question.bank.notes.help'), ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <button type="button" id="btn-generate-bank" class="btn btn-primary btn-sm">
                            <?= htmlspecialchars(t('question.bank.generate'), ENT_QUOTES, 'UTF-8') ?>
                        </button>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="eduqr-question-panel h-100">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                            <h3 class="h5 mb-0"><?= htmlspecialchars(t('question.bank.title'), ENT_QUOTES, 'UTF-8') ?></h3>
                            <button type="button" id="btn-copy-bank" class="btn btn-success btn-sm" <?= $isClosed ? 'disabled' : '' ?>>
                                <?= htmlspecialchars(t('question.bank.copy_selected'), ENT_QUOTES, 'UTF-8') ?>
                            </button>
                        </div>
                        <div id="bank-list" class="eduqr-question-list">
                            <p class="text-muted small" id="bank-empty"><?= htmlspecialchars(t('question.bank.no_items'), ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                    </div>
                </div>
            </div>
            </div>
        </div>
    </div>

    <?php if (!$isClosed): ?>
    <div class="col-lg-5">
        <div class="eduqr-surface p-4 p-lg-5 text-center">
            <p class="fw-semibold mb-2"><?= htmlspecialchars(t('session.qr.title'), ENT_QUOTES, 'UTF-8') ?></p>
            <div class="eduqr-image-frame d-inline-block">
                <img src="<?= htmlspecialchars(eduqr_path('/api/v1/sessions/' . (int) $session['id'] . '/qr.png?size=400'), ENT_QUOTES, 'UTF-8') ?>"
                     alt="<?= htmlspecialchars(t('session.qr.title'), ENT_QUOTES, 'UTF-8') ?>"
                     class="img-fluid"
                     style="max-width:320px">
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
const SESSION_ID  = <?= (int) $session['id'] ?>;
const COURSE_ID   = <?= (int) $session['course_id'] ?>;
const SESSION_TOPIC_NAME = <?= json_encode($session['topic_name'] ?? '') ?>;
const CSRF_TOKEN  = <?= json_encode($csrfToken) ?>;
const IS_ACTIVE   = <?= $isActive ? 'true' : 'false' ?>;
const IS_CLOSED   = <?= $isClosed ? 'true' : 'false' ?>;
const IS_QUIZ     = <?= (bool) $session['is_quiz'] ? 'true' : 'false' ?>;
const MSG_CONFIRM = <?= json_encode(t('common.confirm')) ?>;
const MSG_SERVER  = <?= json_encode(t('error.server_error')) ?>;
const L = {
    draft:       <?= json_encode(t('question.status.draft')) ?>,
    active:      <?= json_encode(t('question.status.active')) ?>,
    closed:      <?= json_encode(t('question.status.closed')) ?>,
    activate:    <?= json_encode(t('question.action.activate')) ?>,
    closeQ:      <?= json_encode(t('question.action.close')) ?>,
    del:         <?= json_encode(t('common.delete')) ?>,
    correctAnswer: <?= json_encode(t('question.correct_answer')) ?>,
    imgUpload:   <?= json_encode(t('question.image.upload')) ?>,
    imgRemove:   <?= json_encode(t('question.image.remove')) ?>,
    imgAlt:      <?= json_encode(t('question.image.preview_alt')) ?>,
    optionPlaceholder: <?= json_encode(t('question.option.placeholder')) ?>,
    importInvalidJson: <?= json_encode(t('question.import.invalid_json')) ?>,
    launchNone: <?= json_encode(t('question.launch.none')) ?>,
    launchPlaceholder: <?= json_encode(t('question.launch.placeholder')) ?>,
    launchSelectRequired: <?= json_encode(t('question.launch.select_required')) ?>,
    imported:    <?= json_encode(t('question.imported')) ?>,
    activated:   <?= json_encode(t('question.activated')) ?>,
    bank: {
        title: <?= json_encode(t('question.bank.title')) ?>,
        help: <?= json_encode(t('question.bank.help')) ?>,
        notesLabel: <?= json_encode(t('question.bank.notes.label')) ?>,
        notesHelp: <?= json_encode(t('question.bank.notes.help')) ?>,
        notesRequired: <?= json_encode(t('question.bank.notes.required')) ?>,
        notesPlaceholder: <?= json_encode(t('question.bank.notes.placeholder')) ?>,
        sourceTitleLabel: <?= json_encode(t('question.bank.source_title.label')) ?>,
        sourceTitlePlaceholder: <?= json_encode(t('question.bank.source_title.placeholder')) ?>,
        generate: <?= json_encode(t('question.bank.generate')) ?>,
        copySelected: <?= json_encode(t('question.bank.copy_selected')) ?>,
        saveToBank: <?= json_encode(t('question.bank.save_to_bank')) ?>,
        noItems: <?= json_encode(t('question.bank.no_items')) ?>,
        selectRequired: <?= json_encode(t('question.bank.select_required')) ?>,
        generated: <?= json_encode(t('question.bank.generated')) ?>,
        saved: <?= json_encode(t('question.bank.saved')) ?>,
        copied: <?= json_encode(t('question.bank.copied')) ?>,
        sourceLectureNotes: <?= json_encode(t('question.bank.source_kind.lecture_notes')) ?>,
        sourceSessionQuestion: <?= json_encode(t('question.bank.source_kind.session_question')) ?>,
    },
    stages: {
        opening: <?= json_encode(t('question.stage.opening')) ?>,
        middle: <?= json_encode(t('question.stage.middle')) ?>,
        closing: <?= json_encode(t('question.stage.closing')) ?>,
    },
    types: {
        multiple_choice: <?= json_encode(t('question.type.multiple_choice')) ?>,
        open_text:       <?= json_encode(t('question.type.open_text')) ?>,
        yes_no:          <?= json_encode(t('question.type.yes_no')) ?>,
        likert_5:        <?= json_encode(t('question.type.likert_5')) ?>,
        fill_in_the_blank: <?= json_encode(t('question.type.fill_in_the_blank')) ?>,
    },
};

// ── Session controls ──────────────────────────────────────────────────────────

const feedbackEl = document.getElementById('session-feedback');
const importFeedbackEl = document.getElementById('import-feedback');
const launchFeedbackEl = document.getElementById('launch-feedback');
const bankFeedbackEl = document.getElementById('bank-feedback');
const importJsonEl = document.getElementById('import-json');
const draftQuestionSelect = document.getElementById('draft-question-select');
const activateSelectedBtn = document.getElementById('btn-activate-selected');
const bankNotesEl = document.getElementById('bank-notes');
const bankSourceTitleEl = document.getElementById('bank-source-title');
const bankListEl = document.getElementById('bank-list');
const bankEmptyEl = document.getElementById('bank-empty');
const bankGenerateBtn = document.getElementById('btn-generate-bank');
const bankCopyBtn = document.getElementById('btn-copy-bank');

let bankItems = [];

function showFeedback(msg, type) {
    feedbackEl.textContent = msg;
    feedbackEl.className   = 'alert alert-' + type;
    feedbackEl.classList.remove('d-none');
}

function showPanelFeedback(el, msg, type) {
    if (!el) {
        return;
    }

    el.textContent = msg;
    el.className = 'alert alert-' + type;
    el.classList.remove('d-none');
}

function hidePanelFeedback(el) {
    if (!el) {
        return;
    }

    el.textContent = '';
    el.className = 'alert d-none';
}

async function sendSessionAction(action) {
    try {
        const res  = await fetch(eduqrPath('/api/v1/sessions/' + SESSION_ID + '/' + action), {
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
        const res  = await fetch(eduqrPath('/api/v1/sessions/' + SESSION_ID), {
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

const toggleQuiz = document.getElementById('toggle-quiz');
toggleQuiz?.addEventListener('change', function () {
    patchSession({ is_quiz: this.checked ? 1 : 0 }).then(() => {
        window.location.reload();
    });
});

const toggleExamMode = document.getElementById('toggle-exam-mode');
toggleExamMode?.addEventListener('change', function () {
    patchSession({ exam_mode: this.checked ? 1 : 0 });
});

// ── Participant count ─────────────────────────────────────────────────────────

async function refreshCount() {
    try {
        const res  = await fetch(eduqrPath('/api/v1/sessions/' + SESSION_ID + '/participants/count'));
        const data = await res.json();
        if (data.success) {
            document.getElementById('participant-count').textContent = data.data.count;
        }
    } catch {}
}

// ── Comprehension pulse (FR-48) ───────────────────────────────────────────────
// Rides the participant-count polling cycle below — no second timer.

let activeQuestionId = null;

async function refreshReactions() {
    const gotItBox = document.getElementById('reaction-got-it');
    const lostBox  = document.getElementById('reaction-lost');
    if (!gotItBox || !lostBox) {
        return;
    }

    if (!activeQuestionId) {
        gotItBox.textContent = '0';
        lostBox.textContent  = '0';

        return;
    }

    try {
        const res  = await fetch(eduqrPath('/api/v1/sessions/' + SESSION_ID + '/reactions'));
        const data = await res.json();
        if (data.success) {
            const row = data.data.find(r => r.question_id === activeQuestionId);
            gotItBox.textContent = row ? row.got_it : '0';
            lostBox.textContent  = row ? row.lost   : '0';
        }
    } catch {}
}

async function refreshLive() {
    await refreshCount();
    await refreshReactions();
}

refreshLive();
<?php if (!$isClosed): ?>
setInterval(refreshLive, 5000);
<?php endif; ?>

// ── Question manager ──────────────────────────────────────────────────────────

let questions = [];
let dragSrcId  = null;

async function loadQuestions() {
    try {
        const res  = await fetch(eduqrPath('/api/v1/sessions/' + SESSION_ID + '/questions'));
        const data = await res.json();
        if (data.success) {
            questions = data.data;
            renderQuestions();
            renderDraftSelector();
        }
    } catch {}
}

function renderQuestions() {
    const list  = document.getElementById('question-list');
    const empty = document.getElementById('questions-empty');

    // Track the open question so the comprehension pulse (FR-48) knows what to show
    const active = questions.find(q => q.status === 'active');
    activeQuestionId = active ? Number(active.id) : null;

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

function renderDraftSelector() {
    if (!draftQuestionSelect || !activateSelectedBtn) {
        return;
    }

    const drafts = questions.filter(q => q.status === 'draft');
    draftQuestionSelect.innerHTML = '';

    if (drafts.length === 0) {
        const option = document.createElement('option');
        option.value = '';
        option.textContent = L.launchNone;
        option.disabled = true;
        option.selected = true;
        draftQuestionSelect.appendChild(option);
        activateSelectedBtn.disabled = true;

        return;
    }

    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = L.launchPlaceholder;
    placeholder.disabled = true;
    placeholder.selected = true;
    draftQuestionSelect.appendChild(placeholder);

    drafts.forEach(q => {
        const option = document.createElement('option');
        option.value = String(q.id);

        const textShort = q.question_text.length > 80
            ? q.question_text.slice(0, 80) + '…'
            : q.question_text;
        const typeLabel = L.types[q.question_type] || q.question_type;
        option.textContent = `${textShort} · ${typeLabel}`;

        draftQuestionSelect.appendChild(option);
    });

    syncLaunchButtonState();
}

function syncLaunchButtonState() {
    if (!draftQuestionSelect || !activateSelectedBtn) {
        return;
    }

    activateSelectedBtn.disabled = draftQuestionSelect.value === '';
}

async function loadBank() {
    try {
        const res = await fetch(eduqrPath('/api/v1/courses/' + COURSE_ID + '/question-bank'));
        const data = await res.json();
        if (data.success) {
            bankItems = data.data;
            renderBank();
        }
    } catch {}
}

function renderBank() {
    if (!bankListEl || !bankEmptyEl) {
        return;
    }

    bankListEl.querySelectorAll('.bank-row').forEach(el => el.remove());

    if (bankItems.length === 0) {
        bankEmptyEl.classList.remove('d-none');
        syncBankCopyButtonState();

        return;
    }

    bankEmptyEl.classList.add('d-none');

    bankItems.forEach(item => {
        bankListEl.appendChild(buildBankRow(item));
    });

    syncBankCopyButtonState();
}

function buildBankRow(item) {
    const q = item.question || {};
    const selectable = !IS_CLOSED;
    const sourceLabel = item.source_kind === 'lecture_notes'
        ? L.bank.sourceLectureNotes
        : L.bank.sourceSessionQuestion;

    const optCount = Array.isArray(q.options) && q.options.length > 0 ? ` (${q.options.length})` : '';
    const typeLabel = (L.types[q.question_type] || q.question_type || '') + optCount;
    const stageLabel = L.stages[q.stage] || q.stage || '';
    const textShort = (q.question_text || '').length > 120
        ? (q.question_text || '').slice(0, 120) + '…'
        : (q.question_text || '');

    const div = document.createElement('div');
    div.className = 'bank-row eduqr-question-row';
    div.dataset.id = item.id;

    div.innerHTML = `
        <div class="form-check flex-shrink-0 mt-1">
            <input class="form-check-input bank-select" type="checkbox" value="${item.id}" ${selectable ? '' : 'disabled'}>
        </div>
        <div class="flex-grow-1">
            <div class="eduqr-question-meta mb-1">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="badge text-bg-light border text-dark">${escHtml(sourceLabel)}</span>
                    <span class="badge text-bg-secondary">${escHtml(stageLabel)}</span>
                    <span class="badge text-bg-light border text-dark">${escHtml(typeLabel)}</span>
                </div>
            </div>
            <p class="mb-1 small">${escHtml(textShort)}</p>
            ${item.source_title ? `<div class="text-muted small">${escHtml(item.source_title)}</div>` : ''}
        </div>`;

    return div;
}

function syncBankCopyButtonState() {
    if (!bankCopyBtn) {
        return;
    }

    if (IS_CLOSED) {
        bankCopyBtn.disabled = true;

        return;
    }

    const selected = bankListEl ? bankListEl.querySelectorAll('.bank-select:checked').length : 0;
    bankCopyBtn.disabled = selected === 0;
}

async function generateBankQuestions() {
    hidePanelFeedback(bankFeedbackEl);

    const lectureNotes = bankNotesEl?.value.trim() || '';
    if (lectureNotes === '') {
        showPanelFeedback(bankFeedbackEl, L.bank.notesRequired, 'danger');

        return;
    }

    try {
        const res = await fetch(eduqrPath('/api/v1/courses/' + COURSE_ID + '/question-bank/generate'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': CSRF_TOKEN,
            },
            body: JSON.stringify({
                source_title: bankSourceTitleEl?.value.trim() || '',
                topic_name: SESSION_TOPIC_NAME,
                lecture_notes: lectureNotes,
            }),
        });
        const data = await res.json();
        if (data.success) {
            if (bankNotesEl) {
                bankNotesEl.value = '';
            }
            await loadBank();
            showPanelFeedback(bankFeedbackEl, data.message || L.bank.generated, 'success');
        } else {
            showPanelFeedback(bankFeedbackEl, data.error?.message || MSG_SERVER, 'danger');
        }
    } catch {
        showPanelFeedback(bankFeedbackEl, MSG_SERVER, 'danger');
    }
}

async function copySelectedBankQuestions() {
    hidePanelFeedback(bankFeedbackEl);

    if (IS_CLOSED) {
        return;
    }

    const ids = bankListEl
        ? Array.from(bankListEl.querySelectorAll('.bank-select:checked')).map(input => Number(input.value)).filter(Boolean)
        : [];

    if (ids.length === 0) {
        showPanelFeedback(bankFeedbackEl, L.bank.selectRequired, 'danger');

        return;
    }

    try {
        const res = await fetch(eduqrPath('/api/v1/sessions/' + SESSION_ID + '/questions/from-bank'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': CSRF_TOKEN,
            },
            body: JSON.stringify({ bank_question_ids: ids }),
        });
        const data = await res.json();
        if (data.success) {
            await loadQuestions();
            await loadBank();
            showPanelFeedback(bankFeedbackEl, data.message || L.bank.copied, 'success');
        } else {
            showPanelFeedback(bankFeedbackEl, data.error?.message || MSG_SERVER, 'danger');
        }
    } catch {
        showPanelFeedback(bankFeedbackEl, MSG_SERVER, 'danger');
    }
}

async function saveQuestionToBank(id) {
    try {
        const res = await fetch(eduqrPath('/api/v1/questions/' + id + '/bank'), {
            method: 'POST',
            headers: { 'X-CSRF-Token': CSRF_TOKEN },
        });
        const data = await res.json();
        if (data.success) {
            await loadBank();
            showPanelFeedback(bankFeedbackEl, data.message || L.bank.saved, 'success');
        } else {
            showPanelFeedback(bankFeedbackEl, data.error?.message || MSG_SERVER, 'danger');
        }
    } catch {
        showPanelFeedback(bankFeedbackEl, MSG_SERVER, 'danger');
    }
}

function buildQuestionRow(q) {
    const statusColors = { draft: 'secondary', active: 'success', closed: 'secondary' };
    const color = statusColors[q.status] || 'secondary';
    const label = L[q.status] || q.status;

    const div = document.createElement('div');
    div.className = 'q-row eduqr-question-row';
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
    actions += ` <button class="btn btn-outline-primary btn-sm" onclick="saveQuestionToBank(${q.id})">${L.bank.saveToBank}</button>`;

    const thumbHtml = q.image_url
        ? `<img src="${escHtml(q.image_url)}" alt="${escHtml(L.imgAlt)}" class="rounded border" style="width:48px;height:48px;object-fit:cover;flex-shrink:0">`
        : '';

    div.innerHTML = `
        <span class="eduqr-question-grip">&#8801;</span>
        ${thumbHtml}
        <div class="flex-grow-1">
            <div class="eduqr-question-meta">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="badge text-bg-${color}">${label}</span>
                    <span class="badge text-bg-light border text-dark">${escHtml(typeLabel)}</span>
                </div>
            </div>
            <p class="mb-0 small">${escHtml(textShort)}</p>
        </div>`;

    if (actions) {
        const actionsWrap = document.createElement('div');
        actionsWrap.className = 'eduqr-question-actions flex-shrink-0';
        actionsWrap.innerHTML = actions;
        div.appendChild(actionsWrap);
    }

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
    e.currentTarget.classList.add('is-dragging');
    e.dataTransfer.effectAllowed = 'move';
}

function onDragOver(e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    e.currentTarget.classList.add('is-drop-target');
}

function onDragLeave(e) {
    e.currentTarget.classList.remove('is-drop-target');
}

async function onDrop(e) {
    e.preventDefault();
    const targetId = +e.currentTarget.dataset.id;
    e.currentTarget.classList.remove('is-drop-target');

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
        await fetch(eduqrPath('/api/v1/sessions/' + SESSION_ID + '/questions/reorder'), {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
            body:    JSON.stringify({ order: questions.map(q => q.id) }),
        });
    } catch {}
}

function onDragEnd(e) {
    e.currentTarget.classList.remove('is-dragging');
    dragSrcId = null;
}

// ── Question actions ──────────────────────────────────────────────────────────

async function activateQuestion(id) {
    return activateQuestionWithFeedback(id, null);
}

async function activateQuestionWithFeedback(id, feedbackEl) {
    try {
        const res  = await fetch(eduqrPath('/api/v1/questions/' + id + '/activate'), {
            method: 'POST', headers: { 'X-CSRF-Token': CSRF_TOKEN },
        });
        const data = await res.json();
        if (data.success) {
            await loadQuestions();
            if (feedbackEl) {
                showPanelFeedback(feedbackEl, data.message || L.activated, 'success');
            }
        } else {
            if (feedbackEl) {
                showPanelFeedback(feedbackEl, data.error?.message || MSG_SERVER, 'danger');
            } else {
                showFeedback(data.error?.message || MSG_SERVER, 'danger');
            }
        }
    } catch {
        if (feedbackEl) {
            showPanelFeedback(feedbackEl, MSG_SERVER, 'danger');
        } else {
            showFeedback(MSG_SERVER, 'danger');
        }
    }
}

async function importQuestions() {
    if (!importJsonEl) {
        return;
    }

    hidePanelFeedback(importFeedbackEl);

    let payload;
    try {
        payload = JSON.parse(importJsonEl.value);
    } catch {
        showPanelFeedback(importFeedbackEl, L.importInvalidJson, 'danger');
        return;
    }

    try {
        const res  = await fetch(eduqrPath('/api/v1/sessions/' + SESSION_ID + '/questions/import'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': CSRF_TOKEN,
            },
            body: JSON.stringify(payload),
        });
        const data = await res.json();
        if (data.success) {
            importJsonEl.value = '';
            await loadQuestions();
            showPanelFeedback(importFeedbackEl, data.message || L.imported, 'success');
        } else {
            showPanelFeedback(importFeedbackEl, data.error?.message || MSG_SERVER, 'danger');
        }
    } catch {
        showPanelFeedback(importFeedbackEl, MSG_SERVER, 'danger');
    }
}

async function activateSelectedQuestion() {
    if (!draftQuestionSelect) {
        return;
    }

    hidePanelFeedback(launchFeedbackEl);

    const questionId = draftQuestionSelect.value;
    if (!questionId) {
        showPanelFeedback(launchFeedbackEl, L.launchSelectRequired, 'danger');

        return;
    }

    await activateQuestionWithFeedback(Number(questionId), launchFeedbackEl);
}

document.getElementById('btn-import-questions')?.addEventListener('click', importQuestions);
activateSelectedBtn?.addEventListener('click', activateSelectedQuestion);
draftQuestionSelect?.addEventListener('change', function () {
    hidePanelFeedback(launchFeedbackEl);
    syncLaunchButtonState();
});
bankGenerateBtn?.addEventListener('click', generateBankQuestions);
bankCopyBtn?.addEventListener('click', copySelectedBankQuestions);
bankListEl?.addEventListener('change', syncBankCopyButtonState);
loadQuestions();
loadBank();

async function closeQuestion(id) {
    try {
        const res  = await fetch(eduqrPath('/api/v1/questions/' + id + '/close'), {
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
        const res  = await fetch(eduqrPath('/api/v1/questions/' + id), {
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
    row.className = 'eduqr-option-row option-row' + (IS_QUIZ ? ' is-quiz' : '');
    
    let correctRadio = '';
    if (IS_QUIZ) {
        const checked = index === 0 ? 'checked' : '';
        correctRadio = `
            <div class="input-group-text gap-1">
                <input class="form-check-input mt-0 correct-radio" type="radio" name="correct_option" id="correct_opt_${index}" value="${index}" ${checked}>
                <label class="form-check-label small mb-0" style="font-size:0.75rem; cursor:pointer;" for="correct_opt_${index}">
                    ${L.correctAnswer}
                </label>
            </div>
        `;
    }

    row.innerHTML = `
        ${correctRadio}
        <input type="text" class="form-control option-input" placeholder="${escHtml(optionPlaceholder(index + 1))}" maxlength="200">
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="removeOptionInput(this)">×</button>`;
    list.appendChild(row);
}

function removeOptionInput(btn) {
    const list = document.getElementById('options-list');
    if (list.children.length <= 2) return;
    btn.closest('.option-row').remove();
    optionCount--;

    if (IS_QUIZ) {
        const radios = list.querySelectorAll('.correct-radio');
        const anyChecked = Array.from(radios).some(r => r.checked);
        if (!anyChecked && radios.length > 0) {
            radios[0].checked = true;
        }
        Array.from(list.children).forEach((row, idx) => {
            const radio = row.querySelector('.correct-radio');
            const label = row.querySelector('.form-check-label');
            const input = row.querySelector('.option-input');
            if (radio) {
                radio.id = `correct_opt_${idx}`;
                radio.value = idx;
            }
            if (label) {
                label.setAttribute('for', `correct_opt_${idx}`);
            }
            if (input) {
                input.placeholder = optionPlaceholder(idx + 1);
            }
        });
    }
}

function optionPlaceholder(number) {
    return L.optionPlaceholder.replace('{number}', number);
}

document.getElementById('btn-add-option')?.addEventListener('click', () => {
    if (optionCount >= 8) return;
    optionCount++;
    addOptionInput();
});

document.getElementById('q-type')?.addEventListener('change', function () {
    const section = document.getElementById('options-section');
    section.style.display = this.value === 'multiple_choice' ? '' : 'none';
    const correctAnswerSection = document.getElementById('correct-answer-section');
    correctAnswerSection.style.display = this.value === 'fill_in_the_blank' ? '' : 'none';
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
        if (IS_QUIZ) {
            const radios = document.querySelectorAll('#options-list .correct-radio');
            body.options = Array.from(inputs).map((i, idx) => ({
                option_text: i.value.trim(),
                is_correct: (radios[idx] && radios[idx].checked) ? 1 : 0
            }));
        } else {
            body.options = Array.from(inputs).map(i => ({ option_text: i.value.trim() }));
        }
    }

    if (type === 'fill_in_the_blank') {
        body.correct_answer = document.getElementById('q-correct-answer').value.trim();
    }

    btn.disabled = true;
    try {
        const res  = await fetch(eduqrPath('/api/v1/sessions/' + SESSION_ID + '/questions'), {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
            body:    JSON.stringify(body),
        });
        const data = await res.json();
        if (data.success) {
            const newId = data.data?.id;
            // Upload image if selected
            const imageFile = document.getElementById('q-image')?.files?.[0];
            if (newId && imageFile) {
                const fd = new FormData();
                fd.append('image', imageFile);
                const uploadRes = await fetch(eduqrPath('/api/v1/questions/' + newId + '/image'), {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': CSRF_TOKEN },
                    body: fd,
                });
                const uploadData = await uploadRes.json();
                if (!uploadData.success) {
                    throw new Error(uploadData.error?.message || MSG_SERVER);
                }
            }
            // Reset form and close panel
            document.getElementById('q-text').value = '';
            document.getElementById('q-show-results').checked = false;
            document.getElementById('q-type').value = 'multiple_choice';
            document.getElementById('options-section').style.display = '';
            document.getElementById('q-correct-answer').value = '';
            document.getElementById('correct-answer-section').style.display = 'none';
            document.getElementById('q-image').value = '';
            document.getElementById('q-image-preview').classList.add('d-none');
            initOptions();
            bootstrap.Collapse.getOrCreateInstance(
                document.getElementById('create-question-panel')
            ).hide();
            await loadQuestions();
        } else {
            errEl.textContent = data.error?.message || MSG_SERVER;
            errEl.classList.remove('d-none');
        }
    } catch (e) {
        errEl.textContent = e.message || MSG_SERVER;
        errEl.classList.remove('d-none');
    } finally {
        btn.disabled = false;
    }
});

// ── Image file preview ────────────────────────────────────────────────────────

document.getElementById('q-image')?.addEventListener('change', function () {
    const preview = document.getElementById('q-image-preview');
    if (this.files && this.files[0]) {
        const reader = new FileReader();
        reader.onload = e => {
            preview.querySelector('img').src = e.target.result;
            preview.classList.remove('d-none');
        };
        reader.readAsDataURL(this.files[0]);
    } else {
        preview.classList.add('d-none');
    }
});

// ── Boot ──────────────────────────────────────────────────────────────────────

initOptions();
// Show options section only for multiple_choice on initial load
document.getElementById('q-type')?.dispatchEvent(new Event('change'));
</script>
<?php
$content   = ob_get_clean();
$pageTitle = htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8') . ' — ' . t('app.name');
include __DIR__ . '/../../layouts/admin.php';
