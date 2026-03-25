<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

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

ob_start();
?>
<style>
.qz-wrap{max-width:900px;margin:0 auto;display:flex;flex-direction:column;gap:14px}
.qz-lead{font-size:14px;color:#475569;margin:0 0 6px}
.qz-card{border:1px solid #dbeafe;border-radius:14px;padding:14px;background:#fff}
.qz-q{font-weight:800;color:#0f172a;margin-bottom:10px}
.qz-opts{display:grid;grid-template-columns:1fr;gap:8px}
.qz-opt{display:flex;align-items:flex-start;gap:8px;padding:10px;border:1px solid #dbeafe;border-radius:10px;background:#f8fbff}
.qz-actions{display:flex;gap:10px;flex-wrap:wrap}
.qz-btn{border:none;border-radius:10px;padding:10px 14px;font-weight:700;cursor:pointer;color:#fff;background:linear-gradient(180deg,#3d73ee,#2563eb)}
.qz-btn.secondary{background:linear-gradient(180deg,#7b8b9e,#66758b);display:none}
.qz-result{padding:12px;border-radius:10px;background:#e9f8ee;color:#166534;font-weight:700;display:none}
.qz-empty{padding:14px;border:1px solid #dbeafe;border-radius:12px;background:#f8fbff;color:#64748b}
</style>

<div class="qz-wrap" id="quizApp">
  <?php if ($description !== '') { ?>
    <p class="qz-lead"><?php echo htmlspecialchars($description, ENT_QUOTES, 'UTF-8'); ?></p>
  <?php } ?>

  <?php if (empty($questions)) { ?>
    <div class="qz-empty">Este quiz aún no tiene preguntas. Abre el editor para configurarlo.</div>
  <?php } else { ?>
    <?php foreach ($questions as $index => $q) { ?>
      <div class="qz-card" data-index="<?php echo (int) $index; ?>">
        <div class="qz-q"><?php echo ($index + 1); ?>. <?php echo htmlspecialchars((string) ($q['question'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="qz-opts">
          <?php foreach ((array) ($q['options'] ?? []) as $optIndex => $optionText) { ?>
            <label class="qz-opt">
              <input type="radio" name="q_<?php echo (int) $index; ?>" value="<?php echo (int) $optIndex; ?>">
              <span><?php echo htmlspecialchars((string) $optionText, ENT_QUOTES, 'UTF-8'); ?></span>
            </label>
          <?php } ?>
        </div>
      </div>
    <?php } ?>

    <div class="qz-actions">
      <button type="button" class="qz-btn" id="btnCheckQuiz">Finalizar quiz</button>
      <button type="button" class="qz-btn secondary" id="btnSaveResult">Guardar resultado y volver</button>
    </div>
    <div class="qz-result" id="quizResult"></div>
  <?php } ?>
</div>

<script>
window.QUIZ_DATA = <?php echo json_encode($questions, JSON_UNESCAPED_UNICODE); ?>;
window.QUIZ_RETURN_TO = <?php echo json_encode($returnTo, JSON_UNESCAPED_UNICODE); ?>;
(function(){
  const btn = document.getElementById('btnCheckQuiz');
  const saveBtn = document.getElementById('btnSaveResult');
  const result = document.getElementById('quizResult');
  if (!btn || !result || !Array.isArray(window.QUIZ_DATA) || window.QUIZ_DATA.length === 0) {
    return;
  }

  let lastPercent = 0;
  let lastErrors = 0;
  let lastTotal = 0;

  btn.addEventListener('click', function(){
    let correct = 0;
    const total = window.QUIZ_DATA.length;

    window.QUIZ_DATA.forEach(function(q, idx){
      const checked = document.querySelector('input[name="q_' + idx + '"]:checked');
      const value = checked ? parseInt(checked.value || '-1', 10) : -1;
      if (value === Number(q.correct || 0)) {
        correct += 1;
      }
    });

    const percent = total > 0 ? Math.round((correct / total) * 100) : 0;
    const errors = Math.max(0, total - correct);
    lastPercent = percent;
    lastErrors = errors;
    lastTotal = total;

    result.style.display = 'block';
    result.textContent = 'Resultado: ' + correct + '/' + total + ' (' + percent + '%)';

    if (saveBtn) {
      saveBtn.style.display = 'inline-flex';
    }
  });

  if (saveBtn) {
    saveBtn.addEventListener('click', function(){
      if (!window.QUIZ_RETURN_TO) {
        return;
      }

      const hasQuery = window.QUIZ_RETURN_TO.indexOf('?') !== -1;
      const joiner = hasQuery ? '&' : '?';
      const target = window.QUIZ_RETURN_TO
        + joiner + 'quiz_percent=' + encodeURIComponent(String(lastPercent))
        + '&quiz_errors=' + encodeURIComponent(String(lastErrors))
        + '&quiz_total=' + encodeURIComponent(String(lastTotal));

      window.location.href = target;
    });
  }
})();
</script>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '🧠', $content);
