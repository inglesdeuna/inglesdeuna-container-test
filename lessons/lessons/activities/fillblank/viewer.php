<?php
require_once __DIR__ . '/../../core/_activity_viewer_template.php';
require_once __DIR__ . '/../../core/db.php';

// Get activity ID and unit from query
$activityId = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
$unit       = isset($_GET['unit']) ? trim((string)$_GET['unit']) : '';

// Load activity from DB (reuse your load_fillblank_activity function)
function load_fillblank_activity(PDO $pdo, string $unit, string $activityId): array {
    $fallback = [
        'id' => '',
        'instructions' => 'Write the missing words in the blanks.',
        'text' => '',
        'wordbank' => '',
        'answerkey' => '',
    ];
    $row = null;
    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE id = :id AND type = 'fillblank' LIMIT 1");
        $stmt->execute(['id' => $activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row && $unit !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE unit_id = :unit AND type = 'fillblank' ORDER BY id ASC LIMIT 1");
        $stmt->execute(['unit' => $unit]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row) return $fallback;
    $data = json_decode($row['data'] ?? '', true);
    return [
        'id' => (string)($row['id'] ?? ''),
        'instructions' => $data['instructions'] ?? $fallback['instructions'],
        'text' => $data['text'] ?? '',
        'wordbank' => $data['wordbank'] ?? '',
        'answerkey' => $data['answerkey'] ?? '',
    ];
}

$activity = load_fillblank_activity($pdo, $unit, $activityId);
?>
<style>
/* keep your CSS exactly as you had it */
</style>

<div class="fbk-card">
  <div class="fbk-title">Fill-in-the-Blank Activity</div>
  <div class="fbk-instructions" id="fbk-instructions"></div>
  <div class="fbk-wordbank" id="fbk-wordbank" style="display:none;"></div>
  <form id="fbk-form">
    <div class="fbk-text" id="fbk-text"></div>
    <div class="fbk-btn-row">
      <button type="button" class="fbk-btn secondary">Previous</button>
      <button type="submit" class="fbk-btn">Submit Answers</button>
      <button type="button" class="fbk-btn secondary">Next</button>
    </div>
  </form>
  <div class="fbk-feedback" id="fbk-feedback"></div>
</div>

<script>
// Inject PHP data into JS safely
const activityData = <?= json_encode($activity, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

// Render instructions, wordbank, and blanks
document.getElementById('fbk-instructions').textContent = activityData.instructions;
if (activityData.wordbank) {
  document.getElementById('fbk-wordbank').textContent = 'Word Bank: ' + activityData.wordbank;
  document.getElementById('fbk-wordbank').style.display = '';
}

function renderTextWithBlanks(text) {
  let idx = 0;
  return text.replace(/

\[blank\]

/g, () => {
    return `<input class="fbk-blank-input" name="blank${++idx}" autocomplete="off" />`;
  });
}
document.getElementById('fbk-text').innerHTML = renderTextWithBlanks(activityData.text);

// Handle form submit
document.getElementById('fbk-form').onsubmit = function(e) {
  e.preventDefault();
  const answers = (activityData.answerkey || '').split(',');
  let correct = 0;
  for (let i = 0; i < answers.length; i++) {
    const val = document.querySelector(`[name=blank${i+1}]`).value.trim().toLowerCase();
    if (val === answers[i].trim().toLowerCase()) correct++;
  }
  const fb = document.getElementById('fbk-feedback');
  if (correct === answers.length) {
    fb.textContent = '✅ All correct!';
    fb.style.color = '#14b8a6';
  } else {
    fb.textContent = `❌ ${correct} of ${answers.length} correct. Try again!`;
    fb.style.color = '#7c3aed';
  }
};
</script>
