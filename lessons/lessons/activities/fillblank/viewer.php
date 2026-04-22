
<?php
require_once __DIR__ . '/../../core/_activity_viewer_template.php';
require_once __DIR__ . '/../../core/db.php';

$activityId = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string)$_GET['unit']) : '';


function load_fillblank_activity(PDO $pdo, string $unit, string $activityId): array {
  $fallback = [
    'id' => '',
    'instructions' => 'Write the missing words in the blanks.',
    'blocks' => [],
    'wordbank' => '',
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
  // Backward compatibility: if old format, convert to one block
  if (!isset($data['blocks']) && isset($data['text'])) {
    $blocks = [[
      'text' => $data['text'],
      'answers' => array_map('trim', explode(',', $data['answerkey'] ?? '')),
    ]];
  } else {
    $blocks = $data['blocks'] ?? [];
  }
  return [
    'id' => (string)($row['id'] ?? ''),
    'instructions' => $data['instructions'] ?? $fallback['instructions'],
    'blocks' => $blocks,
    'wordbank' => $data['wordbank'] ?? '',
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
/* Botones estilo Unscramble, global */
.us-btn {
  padding: 11px 18px;
  border: none;
  border-radius: 999px;
  color: white;
  cursor: pointer;
  margin: 6px;
  min-width: 148px;
  font-weight: 800;
  font-family: 'Nunito','Segoe UI',sans-serif;
  font-size: 14px;
  box-shadow: 0 10px 22px rgba(15,23,42,.12);
  transition: transform .15s ease, filter .15s ease;
}
.us-btn:hover {
  filter: brightness(1.04);
  transform: translateY(-1px);
}
.us-btn-show { background: linear-gradient(180deg,#d8b4fe 0%,#a855f7 100%); }
.us-btn-next { background: linear-gradient(180deg,#818cf8 0%,#6366f1 100%); }
</style>
</style>
</style>
<div class="fbk-card">
  <div class="fbk-title">Fill-in-the-Blank Activity</div>
  <div class="fbk-instructions" id="fbk-instructions"><?= htmlspecialchars($activity['instructions']) ?></div>
  <?php if (!empty($activity['wordbank'])): ?>
    <div class="fbk-wordbank" id="fbk-wordbank">Word Bank: <?= htmlspecialchars($activity['wordbank']) ?></div>
  <?php endif; ?>
  <?php
    $blocks = $activity['blocks'];
    if (!$blocks || !is_array($blocks)) {
      echo '<div class="fbk-text" style="color:#b91c1c;font-weight:bold;">No activity blocks found.</div>';
    } else {
      echo '<form id="fbk-form">';
      foreach ($blocks as $blockIdx => $block) {
        $text = $block['text'] ?? '';
        $answers = isset($block['answers']) && is_array($block['answers']) ? $block['answers'] : [];
        $blankCount = 0;
        $rendered = preg_replace_callback('/___+/', function($m) use (&$blankCount, $blockIdx) {
          $blankCount++;
          return '<input class="fbk-blank-input" name="blank' . $blockIdx . '_' . $blankCount . '" autocomplete="off" />';
        }, htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
        $display = $blockIdx === 0 ? '' : 'style="display:none"';
        echo '<div class="fbk-text block-viewer" data-block="' . $blockIdx . '" ' . $display . '>' . $rendered . '</div>';
      }
      echo '<div class="controls" style="margin-top:22px;text-align:center;">';
      echo '<button type="button" class="us-btn us-btn-show" id="submitBtn">Show Answer</button>';
      echo '<button type="button" class="us-btn us-btn-next" id="prevBtn" style="display:none">Previous</button>';
      echo '<button type="button" class="us-btn us-btn-next" id="nextBtn">Next</button>';
      echo '</div>';
      echo '</form>';
      echo '<div class="fbk-feedback" id="fbk-feedback"></div>';
    }
  ?>
</div>
<script>
const blocks = <?= json_encode($activity['blocks']) ?>;
let currentBlock = 0;
const fbkForm = document.getElementById('fbk-form');
const blockEls = document.querySelectorAll('.block-viewer');
const prevBtn = document.getElementById('prevBtn');
const nextBtn = document.getElementById('nextBtn');
const submitBtn = document.getElementById('submitBtn');
const fb = document.getElementById('fbk-feedback');
let showAnswers = false;

function showBlock(idx) {
  blockEls.forEach((el, i) => {
    el.style.display = (i === idx) ? '' : 'none';
  });
  prevBtn.style.display = idx > 0 ? '' : 'none';
  nextBtn.style.display = '';
  submitBtn.style.display = '';
  fb.textContent = '';
  submitBtn.textContent = 'Show Answer';
}

prevBtn.onclick = function() {
  if (currentBlock > 0) {
    currentBlock--;
    showBlock(currentBlock);
  }
};

nextBtn.onclick = function() {
  if (currentBlock < blocks.length - 1) {
    currentBlock++;
    showBlock(currentBlock);
  } else {
    // Calcular score real
    let total = 0, correct = 0;
    for (let idx = 0; idx < blocks.length; idx++) {
      const block = blocks[idx];
      const answers = Array.isArray(block.answers) ? block.answers : [];
      total += answers.length;
      for (let i = 0; i < answers.length; i++) {
        const input = document.querySelector(`[name=blank${idx}_${i+1}]`);
        if (!input) continue;
        const val = input.value.trim().toLowerCase();
        if (val === (answers[i]||'').trim().toLowerCase()) correct++;
      }
    }
    // Navegar a completed con score real
    window.location.href = `completed.php?id=<?= urlencode($activityId) ?>&unit=<?= urlencode($unit) ?>&correct=${correct}&total=${total}`;
  }
};

function checkBlock(idx) {
  const block = blocks[idx];
  const answers = Array.isArray(block.answers) ? block.answers : [];
  let correct = 0, total = answers.length;
  for (let i = 0; i < answers.length; i++) {
    const input = document.querySelector(`[name=blank${idx}_${i+1}]`);
    if (!input) continue;
    const val = input.value.trim().toLowerCase();
    if (val === (answers[i]||'').trim().toLowerCase()) correct++;
  }
  return {correct, total};
}

function showBlockAnswers(idx) {
  const block = blocks[idx];
  const answers = Array.isArray(block.answers) ? block.answers : [];
  for (let i = 0; i < answers.length; i++) {
    const input = document.querySelector(`[name=blank${idx}_${i+1}]`);
    if (input) input.value = answers[i] || '';
  }
}

submitBtn.onclick = function(e) {
  e.preventDefault();
  if (!showAnswers) {
    const {correct, total} = checkBlock(currentBlock);
    if (correct === total) {
      fb.textContent = '✅ All correct!';
      fb.style.color = '#14b8a6';
      showAnswers = true;
      submitBtn.textContent = 'Show Answer';
    } else {
      fb.textContent = `❌ ${correct} of ${total} correct. Try again!`;
      fb.style.color = '#7c3aed';
      showAnswers = true;
      submitBtn.textContent = 'Show Answer';
    }
  } else {
    showBlockAnswers(currentBlock);
    fb.textContent = '✔ Answers shown.';
    fb.style.color = '#14b8a6';
  }
};

showBlock(currentBlock);
</script>

<?php
$content = ob_get_clean();
render_activity_viewer('Fill-in-the-Blank Activity', 'fa-solid fa-pen-to-square', $content);
?>
