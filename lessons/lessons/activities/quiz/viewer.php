<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
$returnTo = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';

if ($activityId === '' && $unit === '') {
    die('Activity not specified');
}

function resolve_unit_from_activity(PDO $pdo, string $activityId): string
{
    if ($activityId === '') {
        return '';
    }

    $stmt = $pdo->prepare("SELECT unit_id FROM activities WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row && isset($row['unit_id']) ? (string) $row['unit_id'] : '';
}

function normalize_quiz_payload($rawData): array
{
    $default = [
        'title' => 'Unit Quiz',
        'description' => 'Answer and submit your result.',
        'questions' => [],
    ];

    if ($rawData === null || $rawData === '') {
        return $default;
    }

    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;
    if (!is_array($decoded)) {
        return $default;
    }

    $questions = [];
    foreach ((array) ($decoded['questions'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }

        $options = isset($item['options']) && is_array($item['options']) ? $item['options'] : [];
        $normalizedOptions = [];
        for ($i = 0; $i < 4; $i++) {
            $normalizedOptions[] = trim((string) ($options[$i] ?? ''));
        }

        $correct = (int) ($item['correct'] ?? 0);
        if ($correct < 0 || $correct > 3) {
            $correct = 0;
        }

        $questions[] = [
            'question' => trim((string) ($item['question'] ?? '')),
            'options' => $normalizedOptions,
            'correct' => $correct,
            'explanation' => trim((string) ($item['explanation'] ?? '')),
        ];
    }

    return [
        'title' => trim((string) ($decoded['title'] ?? '')) !== '' ? trim((string) $decoded['title']) : $default['title'],
        'description' => trim((string) ($decoded['description'] ?? $default['description'])),
        'questions' => $questions,
    ];
}

function load_quiz_activity(PDO $pdo, string $activityId, string $unit): array
{
    $fallback = [
        'id' => '',
        'title' => 'Unit Quiz',
        'description' => 'Answer and submit your result.',
        'questions' => [],
    ];

    $row = null;

    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE id = :id AND type = 'quiz' LIMIT 1");
        $stmt->execute(['id' => $activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && $unit !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE unit_id = :unit AND type = 'quiz' ORDER BY id ASC LIMIT 1");
        $stmt->execute(['unit' => $unit]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) {
        return $fallback;
    }

    $payload = normalize_quiz_payload($row['data'] ?? null);

    return [
        'id' => (string) ($row['id'] ?? ''),
        'title' => (string) ($payload['title'] ?? 'Unit Quiz'),
        'description' => (string) ($payload['description'] ?? ''),
        'questions' => isset($payload['questions']) && is_array($payload['questions']) ? $payload['questions'] : [],
    ];
}

function table_has_column(PDO $pdo, string $tableName, string $columnName): bool
{
  try {
    $stmt = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = :table_name AND column_name = :column_name LIMIT 1");
    $stmt->execute([
      'table_name' => $tableName,
      'column_name' => $columnName,
    ]);
    return (bool) $stmt->fetchColumn();
  } catch (Throwable $e) {
    return false;
  }
}

function ensure_quiz_attempts_column(PDO $pdo): void
{
  try {
    $pdo->exec("ALTER TABLE student_activity_results ADD COLUMN IF NOT EXISTS attempts_count INTEGER NOT NULL DEFAULT 1");
  } catch (Throwable $e) {
  }
}

function get_quiz_attempt_policy(PDO $pdo, string $studentId, string $assignmentId, string $unitId, string $activityId): array
{
  $state = [
    'attempts_used' => 0,
    'attempts_allowed' => 3,
    'attempted_today' => false,
    'finish_enabled' => true,
    'message' => '',
  ];

  if ($studentId === '' || $assignmentId === '' || $unitId === '' || $activityId === '') {
    return $state;
  }

  try {
    $hasAttemptsColumn = table_has_column($pdo, 'student_activity_results', 'attempts_count');
    $sql = $hasAttemptsColumn
      ? "\n                SELECT attempts_count, total_count, updated_at\n                FROM student_activity_results\n                WHERE student_id = :student_id\n                  AND assignment_id = :assignment_id\n                  AND unit_id = :unit_id\n                  AND activity_id = :activity_id\n                LIMIT 1\n            "
      : "\n                SELECT total_count, updated_at\n                FROM student_activity_results\n                WHERE student_id = :student_id\n                  AND assignment_id = :assignment_id\n                  AND unit_id = :unit_id\n                  AND activity_id = :activity_id\n                LIMIT 1\n            ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
      'student_id' => $studentId,
      'assignment_id' => $assignmentId,
      'unit_id' => $unitId,
      'activity_id' => $activityId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
      return $state;
    }

    $attemptsUsed = 0;
    if ($hasAttemptsColumn) {
      $attemptsUsed = max(0, (int) ($row['attempts_count'] ?? 0));
    } else {
      $totalCount = max(0, (int) ($row['total_count'] ?? 0));
      // Fallback when attempts_count is not available.
      $attemptsUsed = $totalCount > 0 ? 1 : 0;
    }

    $attemptedToday = false;
    $updatedAt = trim((string) ($row['updated_at'] ?? ''));
    if ($updatedAt !== '') {
      try {
        $attemptDate = (new DateTimeImmutable($updatedAt))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d');
        $today = (new DateTimeImmutable('now'))->format('Y-m-d');
        $attemptedToday = ($attemptDate === $today) && $attemptsUsed > 0;
      } catch (Throwable $e) {
        $attemptedToday = false;
      }
    }

    $state['attempts_used'] = $attemptsUsed;
    $state['attempted_today'] = $attemptedToday;

    if ($attemptsUsed >= 3) {
      $state['finish_enabled'] = false;
      $state['message'] = 'You already used 3/3 attempts for this quiz.';
    } elseif ($attemptedToday) {
      $state['finish_enabled'] = false;
      $state['message'] = 'You already attempted this quiz today. Try again tomorrow.';
    }
  } catch (Throwable $e) {
    return $state;
  }

  return $state;
}

if ($unit === '' && $activityId !== '') {
    $unit = resolve_unit_from_activity($pdo, $activityId);
}

if ($returnTo === '') {
  $returnTo = '../../academic/teacher_course.php?assignment=' . urlencode((string) ($_GET['assignment'] ?? '')) . '&unit=' . urlencode($unit) . '&step=9999';
}

$activity = load_quiz_activity($pdo, $activityId, $unit);
$viewerTitle = (string) ($activity['title'] ?? 'Unit Quiz');
$questions = isset($activity['questions']) && is_array($activity['questions']) ? $activity['questions'] : [];
$description = (string) ($activity['description'] ?? '');

ensure_quiz_attempts_column($pdo);

$studentId = trim((string) ($_SESSION['student_id'] ?? ''));
$assignmentId = trim((string) ($_GET['assignment'] ?? ''));
$quizAttemptPolicy = get_quiz_attempt_policy($pdo, $studentId, $assignmentId, $unit, (string) ($activity['id'] ?? $activityId));

ob_start();
?>
<style>
.qz-wrap{max-width:980px;margin:0 auto;padding:8px 0 24px;display:flex;flex-direction:column;gap:14px}
.qz-hero{border:1px solid #dcc4f0;border-radius:18px;padding:18px;background:linear-gradient(145deg,#fff8e6 0%,#fdeaff 55%,#f0e0ff 100%);box-shadow:0 10px 24px rgba(120,40,160,.12)}
.qz-title{margin:0;font-family:'Fredoka','Trebuchet MS',sans-serif;font-size:30px;line-height:1.1;color:#a855c8}
.qz-lead{font-size:15px;color:#b8551f;margin:8px 0 0;line-height:1.5}
.qz-meta{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
.qz-chip{display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;background:#eddeff;color:#a855c8;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.03em}
.qz-progress-head{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:12px}
.qz-progress-label{font-size:13px;font-weight:800;color:#b8551f}
.qz-progress-track{width:100%;height:10px;border-radius:999px;background:#f3e5ff;overflow:hidden;border:1px solid #e8ccff}
.qz-progress-fill{height:100%;width:0%;background:linear-gradient(90deg,#a855c8 0%,#f14902 100%);transition:width .2s ease}
.qz-alert{border:1px solid #f7b4b4;background:#fff2f2;color:#9b1c1c;border-radius:12px;padding:12px 14px;font-weight:700}
.qz-empty{padding:14px;border:1px solid #dcc4f0;border-radius:12px;background:#fff;color:#b8551f}
.qz-list{display:flex;flex-direction:column;gap:12px}
.qz-card{border:1px solid #dcc4f0;border-radius:14px;padding:14px;background:#fff;box-shadow:0 6px 16px rgba(120,40,160,.08)}
.qz-card-unanswered{border-color:#ef4444;background:#fff4f4}
.qz-q{font-weight:800;color:#f14902;margin-bottom:10px;font-size:17px}
.qz-opts{display:grid;grid-template-columns:1fr;gap:8px}
.qz-opt{display:flex;align-items:flex-start;gap:10px;padding:10px;border:1px solid #ead6f8;border-radius:10px;background:#fff9ff;cursor:pointer;transition:border-color .15s,background .15s}
.qz-opt:hover{border-color:#a855c8;background:#f9efff}
.qz-opt input{margin-top:2px}
.qz-actions{display:flex;gap:10px;flex-wrap:wrap;justify-content:center;position:sticky;bottom:12px;padding:12px;border:1px solid #dcc4f0;border-radius:14px;background:rgba(255,255,255,.95);backdrop-filter:blur(3px)}
.qz-btn{border:none;border-radius:10px;padding:12px 16px;font-weight:800;cursor:pointer;color:#fff;background:linear-gradient(180deg,#f14902,#d33d00);box-shadow:0 8px 18px rgba(241,73,2,.22)}
.qz-btn:disabled{opacity:.55;cursor:not-allowed}
.qz-result{padding:12px;border-radius:10px;background:#e9f8ee;color:#166534;font-weight:700;display:none;text-align:center}
.qz-completed-screen{display:none;text-align:center;max-width:600px;margin:0 auto;padding:40px 20px;border:1px solid #dcc4f0;border-radius:16px;background:#fff;box-shadow:0 10px 24px rgba(120,40,160,.12)}
.qz-completed-screen.active{display:block}
.qz-completed-icon{font-size:72px;margin-bottom:14px}
.qz-completed-title{font-family:'Fredoka','Trebuchet MS',sans-serif;font-size:34px;font-weight:700;color:#a855c8;margin:0 0 14px;line-height:1.2}
.qz-completed-score{font-size:22px;font-weight:800;color:#f14902;margin:0 0 8px}
.qz-completed-text{font-size:16px;color:#b8551f;line-height:1.6;margin:0 0 14px}
.qz-completed-note{font-size:14px;font-weight:700;color:#7c3aed}
@media (max-width:760px){.qz-title{font-size:26px}.qz-q{font-size:16px}.qz-actions{position:static}}
</style>

<div class="qz-wrap" id="quizApp">
  <section class="qz-hero">
    <h2 class="qz-title"><?php echo htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8'); ?></h2>
    <?php if ($description !== '') { ?>
      <p class="qz-lead"><?php echo htmlspecialchars($description, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php } else { ?>
      <p class="qz-lead">One-page quiz. Questions are randomized every time you enter.</p>
    <?php } ?>
    <div class="qz-meta">
      <span class="qz-chip">Attempts: <?php echo (int) ($quizAttemptPolicy['attempts_used'] ?? 0); ?>/<?php echo (int) ($quizAttemptPolicy['attempts_allowed'] ?? 3); ?></span>
      <span class="qz-chip">Rule: 1 per day</span>
      <span class="qz-chip">Random questions</span>
      <span class="qz-chip" id="qz-answered-chip">Answered: <span id="qz-answered-count">0</span>/<?php echo count($questions); ?></span>
    </div>
    <div class="qz-progress-head">
      <span class="qz-progress-label">Progress</span>
      <span class="qz-progress-label" id="qz-progress-percent">0%</span>
    </div>
    <div class="qz-progress-track">
      <div class="qz-progress-fill" id="qz-progress-fill"></div>
    </div>
  </section>

  <?php if (!empty($quizAttemptPolicy['message'])) { ?>
    <div class="qz-alert"><?php echo htmlspecialchars((string) $quizAttemptPolicy['message'], ENT_QUOTES, 'UTF-8'); ?></div>
  <?php } ?>

  <?php if (empty($questions)) { ?>
    <div class="qz-empty">This quiz does not have questions yet. Open the editor to configure it.</div>
  <?php } else { ?>
    <div id="qz-questions-wrap">
      <div class="qz-list" id="qz-list"></div>
      <div class="qz-actions">
        <button type="button" class="qz-btn" id="btnCheckQuiz" <?php echo !empty($quizAttemptPolicy['finish_enabled']) ? '' : 'disabled'; ?>>Finish quiz</button>
      </div>
      <div class="qz-result" id="quizResult"></div>
    </div>

    <div id="qz-completed" class="qz-completed-screen">
      <div class="qz-completed-icon">✅</div>
      <h2 class="qz-completed-title"><?php echo htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8'); ?></h2>
      <p class="qz-completed-score" id="qz-score-text"></p>
      <p class="qz-completed-text" id="qz-completed-text">Your percentage was added to the unit score.</p>
      <p class="qz-completed-note" id="qz-attempt-note"></p>
    </div>
  <?php } ?>
</div>

<script>
window.QUIZ_DATA = <?php echo json_encode($questions, JSON_UNESCAPED_UNICODE); ?>;
window.QUIZ_RETURN_TO = <?php echo json_encode($returnTo, JSON_UNESCAPED_UNICODE); ?>;
window.QUIZ_ACTIVITY_ID = <?php echo json_encode((string) ($activity['id'] ?? ''), JSON_UNESCAPED_UNICODE); ?>;
window.QUIZ_POLICY = <?php echo json_encode($quizAttemptPolicy, JSON_UNESCAPED_UNICODE); ?>;
(function(){
  const btn = document.getElementById('btnCheckQuiz');
  const questionsWrap = document.getElementById('qz-questions-wrap');
  const completedScreen = document.getElementById('qz-completed');
  const scoreTextEl = document.getElementById('qz-score-text');
  const resultEl = document.getElementById('quizResult');
  const listEl = document.getElementById('qz-list');
  const attemptNoteEl = document.getElementById('qz-attempt-note');
  const answeredCountEl = document.getElementById('qz-answered-count');
  const progressFillEl = document.getElementById('qz-progress-fill');
  const progressPercentEl = document.getElementById('qz-progress-percent');
  const policy = window.QUIZ_POLICY || {};

  function shuffleArray(items) {
    const cloned = items.slice();
    for (let i = cloned.length - 1; i > 0; i -= 1) {
      const j = Math.floor(Math.random() * (i + 1));
      const tmp = cloned[i];
      cloned[i] = cloned[j];
      cloned[j] = tmp;
    }
    return cloned;
  }

  function buildRandomizedQuiz(rawQuestions) {
    const mapped = rawQuestions.map(function (q) {
      const options = Array.isArray(q.options) ? q.options : [];
      const correctIndex = Number.isInteger(q.correct) ? q.correct : 0;
      const optionObjects = options.map(function (label, idx) {
        return {
          label: String(label || ''),
          isCorrect: idx === correctIndex,
        };
      });
      return {
        question: String(q.question || ''),
        options: shuffleArray(optionObjects),
      };
    });

    return shuffleArray(mapped);
  }

  function persistScoreSilently(targetUrl) {
    if (!targetUrl) return Promise.resolve(false);
    try {
      return fetch(targetUrl, { method: 'GET', credentials: 'same-origin', cache: 'no-store', keepalive: true })
        .then(function (response) { return !!(response && response.ok); })
        .catch(function(){ return false; });
    } catch(e) {
      return Promise.resolve(false);
    }
  }

  function navigateToReturn(targetUrl) {
    if (!targetUrl) return;
    try {
      if (window.top && window.top !== window.self) {
        window.top.location.href = targetUrl;
        return;
      }
    } catch(e) {}
    window.location.href = targetUrl;
  }

  if (!btn || !listEl || !Array.isArray(window.QUIZ_DATA) || window.QUIZ_DATA.length === 0) {
    return;
  }

  const randomizedQuestions = buildRandomizedQuiz(window.QUIZ_DATA);

  function updateAnsweredProgress() {
    const total = randomizedQuestions.length;
    let answered = 0;
    for (let idx = 0; idx < total; idx += 1) {
      const hasAnswer = !!document.querySelector('input[name="q_' + idx + '"]:checked');
      if (hasAnswer) {
        answered += 1;
      }

      const cardNode = listEl.querySelector('.qz-card[data-index="' + idx + '"]');
      if (cardNode) {
        cardNode.classList.toggle('qz-card-unanswered', !hasAnswer);
      }
    }

    const pct = total > 0 ? Math.round((answered / total) * 100) : 0;
    if (answeredCountEl) {
      answeredCountEl.textContent = String(answered);
    }
    if (progressFillEl) {
      progressFillEl.style.width = String(pct) + '%';
    }
    if (progressPercentEl) {
      progressPercentEl.textContent = String(pct) + '%';
    }

    if (btn) {
      const lockedByPolicy = !policy.finish_enabled;
      btn.disabled = lockedByPolicy || answered < total;
    }
  }

  randomizedQuestions.forEach(function (q, idx) {
    const card = document.createElement('div');
    card.className = 'qz-card';
    card.setAttribute('data-index', String(idx));

    const qTitle = document.createElement('div');
    qTitle.className = 'qz-q';
    qTitle.textContent = (idx + 1) + '. ' + q.question;
    card.appendChild(qTitle);

    const opts = document.createElement('div');
    opts.className = 'qz-opts';

    q.options.forEach(function (opt, optIdx) {
      const label = document.createElement('label');
      label.className = 'qz-opt';

      const radio = document.createElement('input');
      radio.type = 'radio';
      radio.name = 'q_' + idx;
      radio.value = String(optIdx);

      const text = document.createElement('span');
      text.textContent = opt.label;

      label.appendChild(radio);
      label.appendChild(text);
      opts.appendChild(label);
    });

    card.appendChild(opts);
    listEl.appendChild(card);
  });

  listEl.addEventListener('change', function (event) {
    const target = event.target;
    if (target && target.matches('input[type="radio"]')) {
      updateAnsweredProgress();
    }
  });

  updateAnsweredProgress();

  btn.addEventListener('click', async function(){
    if (!policy.finish_enabled) {
      if (resultEl) {
        resultEl.style.display = 'block';
        resultEl.textContent = String(policy.message || 'Finish is locked for now.');
      }
      return;
    }

    let correct = 0;
    const total = randomizedQuestions.length;

    randomizedQuestions.forEach(function(q, idx){
      const checked = document.querySelector('input[name="q_' + idx + '"]:checked');
      const selected = checked ? parseInt(checked.value || '-1', 10) : -1;
      if (selected >= 0 && q.options[selected] && q.options[selected].isCorrect) {
        correct += 1;
      }
    });

    const percent = total > 0 ? Math.round((correct / total) * 100) : 0;
    const errors = Math.max(0, total - correct);
    const hasQuery = window.QUIZ_RETURN_TO.indexOf('?') !== -1;
    const joiner = hasQuery ? '&' : '?';
    const saveUrl = window.QUIZ_RETURN_TO
      + joiner + 'activity_percent=' + encodeURIComponent(String(percent))
      + '&activity_errors=' + encodeURIComponent(String(errors))
      + '&activity_total=' + encodeURIComponent(String(total))
      + '&activity_id=' + encodeURIComponent(String(window.QUIZ_ACTIVITY_ID || ''))
      + '&activity_type=quiz';

    const ok = await persistScoreSilently(saveUrl);
    if (!ok) {
      navigateToReturn(saveUrl);
      return;
    }

    if (questionsWrap) questionsWrap.style.display = 'none';
    if (scoreTextEl) scoreTextEl.textContent = 'Score: ' + correct + ' / ' + total + ' (' + percent + '%)';
    if (attemptNoteEl) {
      const used = Math.min(3, Number(policy.attempts_used || 0) + 1);
      attemptNoteEl.textContent = 'Attempt used: ' + used + '/3';
    }
    if (completedScreen) completedScreen.classList.add('active');
  });
})();
</script>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '🧠', $content);
