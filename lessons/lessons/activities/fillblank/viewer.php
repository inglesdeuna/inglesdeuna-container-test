
<?php
require_once __DIR__ . '/../../core/_activity_viewer_template.php';
require_once __DIR__ . '/../../core/db.php';

$activityId = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string)$_GET['unit']) : '';

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

ob_start();
?>
<style>
.fbk-card {
  max-width: 700px;
  margin: 32px auto;
  background: linear-gradient(135deg, #ede9fe 0%, #f8fafc 100%);
  border-radius: 22px;
  box-shadow: 0 6px 32px rgba(124,58,237,.07);
  padding: 32px 28px 24px 28px;
  display: flex;
  flex-direction: column;
  gap: 18px;
}
.fbk-title {
  font-family: 'Fredoka', 'Trebuchet MS', sans-serif;
  font-size: 2rem;
  color: #14b8a6;
  font-weight: 800;
  margin-bottom: 8px;
  text-align: center;
}
.fbk-instructions {
  font-weight: 700;
  color: #0e7490;
  margin-bottom: 8px;
  text-align: center;
}
.fbk-wordbank {
  background: #f3f0ff;
  border-radius: 12px;
  padding: 8px 14px;
  margin: 10px 0 0 0;
  color: #5b21b6;
  font-size: 1rem;
  text-align: center;
}
.fbk-text {
  font-size: 1.1rem;
  margin: 18px 0 10px 0;
  color: #334155;
  line-height: 1.7;
  text-align: left;
}
.fbk-blank-input {
  width: 110px;
  border: 2px solid #7dd3fc;
  border-radius: 8px;
  padding: 6px 10px;
  font-size: 1rem;
  margin: 0 4px;
  background: #fff;
  font-family: 'Nunito', 'Segoe UI', sans-serif;
}
.fbk-btn-row {
  display: flex;
  gap: 12px;
  justify-content: flex-end;
  margin-top: 18px;
}
.fbk-btn {
  padding: 12px 28px;
  border: none;
  border-radius: 999px;
  font-family: 'Nunito', 'Segoe UI', sans-serif;
  font-weight: 900;
  font-size: 15px;
  cursor: pointer;
  color: #fff;
  background: linear-gradient(180deg, #14b8a6 0%, #7c3aed 100%);
  box-shadow: 0 8px 20px rgba(15, 23, 42, .16);
  transition: transform .15s, filter .15s;
}
.fbk-btn:hover { transform: translateY(-2px); filter: brightness(1.06); }
.fbk-btn.secondary {
  background: linear-gradient(180deg, #94a3b8 0%, #64748b 100%);
}
.fbk-feedback {
  margin-top: 18px;
  font-size: 1.1rem;
  color: #7c3aed;
  text-align: center;
  min-height: 24px;
}
@media (max-width: 600px) {
  .fbk-card { padding: 12px 4vw; }
  .fbk-title { font-size: 1.3rem; }
  .fbk-blank-input { width: 70px; }
}
</style>
<div class="fbk-card">
  <div class="fbk-title">Fill-in-the-Blank Activity</div>
  <div class="fbk-instructions" id="fbk-instructions"><?= htmlspecialchars($activity['instructions']) ?></div>
  <?php if (!empty($activity['wordbank'])): ?>
    <div class="fbk-wordbank" id="fbk-wordbank">Word Bank: <?= htmlspecialchars($activity['wordbank']) ?></div>
  <?php endif; ?>
  <form id="fbk-form">
    <div class="fbk-text" id="fbk-text"><?php
      // Replace [blank] with input fields
      $text = $activity['text'];
      $blankCount = 0;
      $rendered = preg_replace_callback('/\\[blank\\]/', function() use (&$blankCount) {
        $blankCount++;
        return '<input class="fbk-blank-input" name="blank' . $blankCount . '" autocomplete="off" />';
      }, htmlspecialchars($text));
      echo $rendered;
    ?></div>
    <div class="fbk-btn-row">
      <button type="button" class="fbk-btn secondary" onclick="window.history.back()">Previous</button>
      <button type="submit" class="fbk-btn">Submit Answers</button>
      <button type="button" class="fbk-btn secondary" onclick="window.location.reload()">Next</button>
    </div>
  </form>
  <div class="fbk-feedback" id="fbk-feedback"></div>
</div>
<script>
const answerKey = <?= json_encode($activity['answerkey']) ?>;
const answers = (answerKey || '').split(',');
document.getElementById('fbk-form').onsubmit = function(e) {
  e.preventDefault();
  let correct = 0;
  for (let i = 0; i < answers.length; i++) {
    const val = document.querySelector(`[name=blank${i+1}]`).value.trim().toLowerCase();
    if (val === (answers[i]||'').trim().toLowerCase()) correct++;
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
<?php
$content = ob_get_clean();
render_activity_viewer('Fill-in-the-Blank Activity', 'fa-solid fa-pen-to-square', $content);
?>
  if (correct === answers.length) {
    fb.textContent = '✅ All correct!';
    fb.style.color = '#14b8a6';
  } else {
    fb.textContent = `❌ ${correct} of ${answers.length} correct. Try again!`;
    fb.style.color = '#7c3aed';
  }
};
</script>

<?php
$content = ob_get_clean();
render_activity_viewer('Fill-in-the-Blank Activity', 'fa-solid fa-pen-to-square', $content);
?>
