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
$totalQ = count($questions);
?>
<div class="row justify-content-center py-4 py-lg-5">
  <div class="col-12 col-xl-9 col-lg-10">
    <div class="eduqr-student-shell eduqr-stagger">
    <div class="eduqr-student-hero mb-0">
      <div>
        <div class="eduqr-kicker mb-3">
          <span class="eduqr-icon-badge"><?= eduqr_icon('check') ?></span>
          <span><?= htmlspecialchars(t('student.batch.title'), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <h1 class="h3 mb-2"><?= htmlspecialchars(t('student.batch.title'), ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="text-muted mb-0"><code><?= htmlspecialchars($shortCode, ENT_QUOTES, 'UTF-8') ?></code> — <?= htmlspecialchars($session['title'], ENT_QUOTES, 'UTF-8') ?></p>
      </div>
      <span class="eduqr-chip"><?= eduqr_icon('clock') ?> <?= htmlspecialchars(t('student.batch.question_count', ['count' => (string) $totalQ]), ENT_QUOTES, 'UTF-8') ?></span>
    </div>

    <div class="eduqr-student-stage p-4 p-lg-5">
      <div id="batch-error" class="alert alert-danger d-none" role="alert"></div>

      <?php if ($totalQ === 0): ?>
        <div class="eduqr-empty-state">
          <span class="eduqr-icon-badge mb-3"><?= eduqr_icon('clock') ?></span>
          <p class="text-muted mb-0"><?= htmlspecialchars(t('question.no_active'), ENT_QUOTES, 'UTF-8') ?></p>
        </div>
      <?php else: ?>
      <form id="batch-form" novalidate>
        <div class="d-flex justify-content-center gap-2 mb-4 eduqr-steps">
          <?php for ($i = 0; $i < $totalQ; $i++): ?>
            <i class="<?= $i === 0 ? 'on' : '' ?>" data-step="<?= $i ?>"></i>
          <?php endfor; ?>
        </div>

        <div class="eduqr-card-list mb-4">
          <?php foreach ($questions as $index => $q): ?>
            <div class="eduqr-batch-card">
              <div class="d-flex align-items-center gap-2 mb-3">
                <span class="eduqr-chip"><?= (int) ($index + 1) ?> / <?= $totalQ ?></span>
                <h2 class="h5 mb-0"><?= htmlspecialchars($q['question_text'], ENT_QUOTES, 'UTF-8') ?></h2>
              </div>
              <?php if ($q['question_type'] === 'open_text'): ?>
                <textarea class="form-control eduqr-glow-focus batch-open-text" rows="3" data-question-id="<?= (int) $q['id'] ?>" maxlength="2000" placeholder="<?= htmlspecialchars(t('student.answer.text_placeholder'), ENT_QUOTES, 'UTF-8') ?>"></textarea>
              <?php else: ?>
                <div class="d-grid gap-2">
                  <?php foreach ($q['options'] as $opt): ?>
                    <label class="eduqr-answer-option eduqr-opt-in">
                      <input type="radio" name="q_<?= (int) $q['id'] ?>" value="<?= (int) $opt['id'] ?>" class="me-2"> <?= htmlspecialchars($opt['option_text'], ENT_QUOTES, 'UTF-8') ?>
                    </label>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="d-flex gap-2">
          <button type="submit" id="batch-submit" class="btn btn-primary flex-fill btn-lg eduqr-ripple"><?= htmlspecialchars(t('student.batch.submit_all'), ENT_QUOTES, 'UTF-8') ?></button>
        </div>
      </form>
      <?php endif; ?>
    </div>
    </div>
  </div>
</div>

<script>
const SHORT_CODE = <?= json_encode($shortCode) ?>;
const MSG_SERVER = <?= json_encode(t('error.server_error')) ?>;
const form = document.getElementById('batch-form');
const errorBox = document.getElementById('batch-error');
const totalQ = <?= $totalQ ?>;

if (form) {
  function updateSteps() {
    const answered = new Set();
    document.querySelectorAll('textarea.batch-open-text').forEach(el => {
      if (el.value.trim()) answered.add(parseInt(el.dataset.questionId, 10));
    });
    document.querySelectorAll('input[type="radio"]:checked').forEach(el => {
      const qid = parseInt(el.name.replace('q_', ''), 10);
      answered.add(qid);
    });
    document.querySelectorAll('.eduqr-steps i').forEach((dot, i) => {
      dot.className = answered.size > i ? 'done' : (answered.size === i ? 'on' : '');
    });
  }

  document.querySelectorAll('textarea.batch-open-text').forEach(el => {
    el.addEventListener('input', updateSteps);
  });
  document.querySelectorAll('input[type="radio"]').forEach(el => {
    el.addEventListener('change', updateSteps);
  });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    errorBox.classList.add('d-none');

    const answers = [];

    document.querySelectorAll('textarea.batch-open-text').forEach((el) => {
      const text = el.value.trim();
      if (!text) return;
      answers.push({ question_id: parseInt(el.dataset.questionId, 10), answer_text: text });
    });

    document.querySelectorAll('input[type="radio"]:checked').forEach((el) => {
      const qid = parseInt(el.name.replace('q_', ''), 10);
      answers.push({ question_id: qid, selected_option_id: parseInt(el.value, 10) });
    });

    if (answers.length < totalQ) {
      errorBox.textContent = <?= json_encode(t('student.batch.error_all_required')) ?>;
      errorBox.classList.remove('d-none');
      errorBox.style.animation = 'slide-down .35s ease-out';
      return;
    }

    const btn = document.getElementById('batch-submit');
    btn.disabled = true;
    btn.innerHTML = '<span class="eduqr-spinner me-2"></span>' + <?= json_encode(t('common.loading')) ?>;

    try {
      const res = await fetch('/api/v1/sessions/' + SHORT_CODE + '/answers/bulk', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ answers }),
      });
      const data = await res.json();
      if (res.ok && data.success) {
        btn.innerHTML = '✓ ' + <?= json_encode(t('student.answer.submitted')) ?>;
        await new Promise(r => setTimeout(r, 350));
        window.location.href = '/play/' + SHORT_CODE + '/answered';
      } else {
        errorBox.textContent = data.error?.message || MSG_SERVER;
        errorBox.classList.remove('d-none');
        errorBox.style.animation = 'slide-down .35s ease-out';
        btn.disabled = false;
        btn.innerHTML = <?= json_encode(t('student.batch.submit_all')) ?>;
      }
    } catch {
      errorBox.textContent = MSG_SERVER;
      errorBox.classList.remove('d-none');
      errorBox.style.animation = 'slide-down .35s ease-out';
      btn.disabled = false;
      btn.innerHTML = <?= json_encode(t('student.batch.submit_all')) ?>;
    }
  });
}
</script>
<?php
$content = ob_get_clean();
$pageTitle = t('student.batch.title') . ' — ' . t('app.name');
include __DIR__ . '/../layouts/public.php';
