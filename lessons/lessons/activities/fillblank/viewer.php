<?php
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
$unit       = isset($_GET['unit']) ? trim((string)$_GET['unit']) : '';
$returnTo   = isset($_GET['return_to']) ? trim((string)$_GET['return_to']) : '';

if ($activityId === '' && $unit === '') {
    die('Activity not specified');
}

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
$blocks = $activity['blocks'];

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
.us-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 11px 18px;
  border: none;
  border-radius: 999px;
  color: white;
  cursor: pointer;
  min-width: 142px;
  font-weight: 800;
  font-family: 'Nunito','Segoe UI',sans-serif;
  font-size: 14px;
  line-height: 1;
  box-shadow: 0 10px 22px rgba(15,23,42,.12);
  transition: transform .15s ease, filter .15s ease;
}
.us-btn:hover {
  filter: brightness(1.04);
  transform: translateY(-1px);
}
.us-btn-show { background: linear-gradient(180deg,#d8b4fe 0%,#a855f7 100%); }
.us-btn-next { background: linear-gradient(180deg,#818cf8 0%,#6366f1 100%); }
.mc-feedback.good {
  color: #16a34a;
  background: #f0fdf4;
  border-radius: 8px;
  padding: 8px 0;
  font-weight: bold;
  font-size: 1.1em;
}
.mc-feedback.bad {
  color: #dc2626;
  background: #fef2f2;
  border-radius: 8px;
  padding: 8px 0;
  font-weight: bold;
  font-size: 1.1em;
}
</style>
<div id="fbk-completion" style="display:none;text-align:center;padding:32px 20px;">
  <div style="font-size:52px;margin-bottom:10px;">✅</div>
  <p style="font-family:'Fredoka','Trebuchet MS',sans-serif;font-size:28px;font-weight:700;color:#15803d;margin:0 0 8px;">Completed!</p>
  <p id="fbk-score" style="font-size:18px;font-weight:800;color:#166534;margin:0;"></p>
</div>
<div class="fbk-card">
  <div class="fbk-title">Fill-in-the-Blank Activity</div>
  <div class="fbk-instructions" id="fbk-instructions"><?= htmlspecialchars($activity['instructions']) ?></div>
  <?php if (!empty($activity['wordbank'])): ?>
    <div class="fbk-wordbank" id="fbk-wordbank">Word Bank: <?= htmlspecialchars($activity['wordbank']) ?></div>
  <?php endif; ?>
  <?php
    if (!$blocks || !is_array($blocks)) {
      echo '<div class="fbk-text" style="color:#b91c1c;font-weight:bold;">No activity blocks found.</div>';
    } else {
      echo '<form id="fbk-form">';
      foreach ($blocks as $blockIdx => $block) {
        $text = $block['text'] ?? '';
        $answers = isset($block['answers']) && is_array($block['answers']) ? $block['answers'] : [];
        $image = isset($block['image']) ? trim((string)$block['image']) : '';
        $blankCount = 0;
        $rendered = preg_replace_callback('/___+/', function($m) use (&$blankCount, $blockIdx) {
          $blankCount++;
          return '<input class="fbk-blank-input" name="blank' . $blockIdx . '_' . $blankCount . '" autocomplete="off" />';
        }, htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
        $display = $blockIdx === 0 ? '' : 'style="display:none"';
        echo '<div class="fbk-text block-viewer" data-block="' . $blockIdx . '" ' . $display . '>';
        if ($image) {
          echo '<div style="text-align:center;margin-bottom:12px;"><img src="' . htmlspecialchars($image, ENT_QUOTES, 'UTF-8') . '" alt="Block image" style="max-width:220px;max-height:140px;border-radius:10px;object-fit:contain;box-shadow:0 2px 8px rgba(0,0,0,.08);" /></div>';
        }
        echo $rendered . '</div>';
      }
      echo '<div class="controls" style="margin-top:22px;text-align:center;">';
      echo '<button type="button" class="us-btn us-btn-show" id="submitBtn">Show Answer</button>';
      echo '<button type="button" class="us-btn us-btn-next" id="prevBtn" style="display:none">Previous</button>';
      echo '<button type="button" class="us-btn us-btn-next" id="nextBtn">Next</button>';
      echo '</div>';
      echo '</form>';
      echo '<div class="fbk-feedback mc-feedback" id="fbk-feedback"></div>';
    }
  ?>
</div>
<script>
const blocks = <?= json_encode($activity['blocks']) ?>;
let currentBlock = 0;
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
  fb.className = 'fbk-feedback mc-feedback';
  submitBtn.textContent = 'Show Answer';
  showAnswers = false;
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
    // Score final
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
    const errors = Math.max(0, total - correct);
    const percent = total > 0 ? Math.round((correct / total) * 100) : 0;
    const FBK_RETURN_TO = <?= json_encode($returnTo) ?>;
    const FBK_ACTIVITY_ID = <?= json_encode($activityId) ?>;
    document.querySelector('.fbk-card').style.display = 'none';
    const panel = document.getElementById('fbk-completion');
    document.getElementById('fbk-score').textContent = 'Score: ' + correct + ' / ' + total + ' (' + percent + '%)';
    panel.style.display = '';
    if (FBK_RETURN_TO && FBK_ACTIVITY_ID) {
      const joiner = FBK_RETURN_TO.indexOf('?') !== -1 ? '&' : '?';
      const saveUrl = FBK_RETURN_TO + joiner +
        'activity_percent=' + encodeURIComponent(String(percent)) +
        '&activity_errors=' + encodeURIComponent(String(errors)) +
        '&activity_total=' + encodeURIComponent(String(total)) +
        '&activity_id=' + encodeURIComponent(FBK_ACTIVITY_ID) +
        '&activity_type=fillblank';
      fetch(saveUrl, { method: 'GET', credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { if (!r.ok) throw new Error(); })
        .catch(function () {
          try {
            if (window.top && window.top !== window.self) { window.top.location.href = saveUrl; return; }
          } catch (e) {}
          window.location.href = saveUrl;
        });
    }
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
      fb.textContent = '✔ Correct!';
      fb.className = 'fbk-feedback mc-feedback good';
      showAnswers = true;
      submitBtn.textContent = 'Show Answer';
    } else {
      fb.textContent = `✗ ${correct} of ${total} correct. Try again!`;
      fb.className = 'fbk-feedback mc-feedback bad';
      showAnswers = true;
      submitBtn.textContent = 'Show Answer';
    }
  } else {
    showBlockAnswers(currentBlock);
    fb.textContent = '✔ Answers shown.';
    fb.className = 'fbk-feedback mc-feedback good';
  }
};

showBlock(currentBlock);
</script>
<?php
$content = ob_get_clean();
render_activity_viewer('Fill-in-the-Blank Activity', 'fa-solid fa-pen-to-square', $content);
?>
