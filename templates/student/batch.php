<?php

declare(strict_types=1);

use EduQR\Repositories\OptionRepository;
use EduQR\Repositories\QuestionRepository;
use EduQR\Repositories\SessionRepository;

$shortCode = $p['short_code'] ?? '';
$sessionRepo = new SessionRepository();
$session = $sessionRepo->findByShortCode($shortCode);

if ($session === null || $session['status'] === 'closed') {
    http_response_code(404);
    include __DIR__ . '/../../templates/errors/404.php';
    exit;
}

$participantId = (int) ($_COOKIE['eduqr_participant'] ?? 0);
if ($participantId <= 0) {
    header('Location: /join/' . rawurlencode($shortCode));
    exit;
}

$qRepo = new QuestionRepository();
$oRepo = new OptionRepository();
$all = $qRepo->findBySession((int) $session['id']);
$questions = [];
foreach ($all as $q) {
    if (($q['status'] ?? '') === 'closed') {
        continue;
    }
    $q['options'] = $oRepo->findByQuestion((int) $q['id']);
    $questions[] = $q;
    if (count($questions) >= 4) {
        break;
    }
}

ob_start();
?>
<div class="row justify-content-center mt-4">
  <div class="col-12 col-md-10 col-lg-9">
    <div class="eduqr-admin-hero mb-4">
      <div>
        <div class="eduqr-kicker mb-3">
          <span class="eduqr-icon-badge"><?= eduqr_icon('check') ?></span>
          <span><?= htmlspecialchars(t('student.batch.title'), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <h1 class="h3 mb-2"><?= htmlspecialchars(t('student.batch.title'), ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="text-muted mb-0"><code><?= htmlspecialchars($shortCode, ENT_QUOTES, 'UTF-8') ?></code> — <?= htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8') ?></p>
      </div>
      <span class="eduqr-chip"><?= eduqr_icon('clock') ?> <?= count($questions) ?> questions</span>
    </div>

    <div class="eduqr-surface p-4 p-lg-5">
      <div id="batch-error" class="alert alert-danger d-none" role="alert"></div>

      <?php if (count($questions) === 0): ?>
        <div class="eduqr-empty-state">
          <span class="eduqr-icon-badge mb-3"><?= eduqr_icon('clock') ?></span>
          <p class="text-muted mb-0"><?= htmlspecialchars(t('question.no_active'), ENT_QUOTES, 'UTF-8') ?></p>
        </div>
      <?php else: ?>
      <form id="batch-form" novalidate>
        <div class="eduqr-card-list mb-4">
          <?php foreach ($questions as $index => $q): ?>
            <div class="eduqr-card-row align-items-start">
              <div class="flex-grow-1">
                <div class="d-flex align-items-center gap-2 mb-2">
                  <span class="eduqr-chip"><?= (int) ($index + 1) ?></span>
                  <h2 class="h5 mb-0"><?= htmlspecialchars($q['question_text'], ENT_QUOTES, 'UTF-8') ?></h2>
                </div>
                <?php if ($q['question_type'] === 'open_text'): ?>
                  <textarea class="form-control batch-open-text" rows="3" data-question-id="<?= (int) $q['id'] ?>" maxlength="2000"></textarea>
                <?php else: ?>
                  <div class="d-grid gap-2">
                    <?php foreach ($q['options'] as $opt): ?>
                      <label class="btn btn-outline-secondary text-start option-btn">
                        <input type="radio" name="q_<?= (int) $q['id'] ?>" value="<?= (int) $opt['id'] ?>" class="me-2"> <?= htmlspecialchars($opt['option_text'], ENT_QUOTES, 'UTF-8') ?>
                      </label>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <button type="submit" class="btn btn-primary w-100 btn-lg"><?= htmlspecialchars(t('student.batch.submit_all'), ENT_QUOTES, 'UTF-8') ?></button>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
const SHORT_CODE = <?= json_encode($shortCode) ?>;
const MSG_SERVER = <?= json_encode(t('error.server_error')) ?>;
const form = document.getElementById('batch-form');
const errorBox = document.getElementById('batch-error');

if (form) {
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    errorBox.classList.add('d-none');

    const answers = [];
    const totalQuestions = <?= (int) count($questions) ?>;

    document.querySelectorAll('textarea.batch-open-text').forEach((el) => {
      const text = el.value.trim();
      if (!text) return;
      answers.push({ question_id: parseInt(el.dataset.questionId, 10), answer_text: text });
    });

    document.querySelectorAll('input[type="radio"]:checked').forEach((el) => {
      const qid = parseInt(el.name.replace('q_', ''), 10);
      answers.push({ question_id: qid, selected_option_id: parseInt(el.value, 10) });
    });

    if (answers.length < totalQuestions) {
      errorBox.textContent = <?= json_encode(t('student.batch.error_all_required')) ?>;
      errorBox.classList.remove('d-none');
      return;
    }

    try {
      const res = await fetch('/api/v1/sessions/' + SHORT_CODE + '/answers/bulk', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ answers }),
      });
      const data = await res.json();
      if (res.ok && data.success) {
        window.location.href = '/play/' + SHORT_CODE + '/answered';
      } else {
        errorBox.textContent = data.error?.message || MSG_SERVER;
        errorBox.classList.remove('d-none');
      }
    } catch {
      errorBox.textContent = MSG_SERVER;
      errorBox.classList.remove('d-none');
    }
  });
}
</script>
<?php
$content = ob_get_clean();
$pageTitle = t('student.batch.title') . ' — ' . t('app.name');
include __DIR__ . '/../layouts/public.php';
