<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/dot_to_dot_functions.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$returnTo = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';

if ($activityId === '' && $unit === '') {
  die('Activity not specified');
}

$activity = dot_to_dot_load_activity($pdo, $unit, $activityId);
$points = $activity['points'] ?? [];
$image = $activity['image'] ?? '';
$viewerTitle = !empty($activity['title']) ? (string) $activity['title'] : dot_to_dot_default_title();
if ($activityId === '' && !empty($activity['id'])) {
  $activityId = (string) $activity['id'];
}

ob_start();
?>
<style>
.d2d-viewer-shell { max-width: 480px; margin: 0 auto; }
.d2d-stage { position: relative; width: 320px; height: 320px; background: #f8fafc; border-radius: 14px; box-shadow: 0 2px 8px #0001; overflow: hidden; margin: 0 auto 18px; }
.d2d-img { width: 100%; height: 100%; object-fit: contain; position: absolute; left: 0; top: 0; z-index: 1; opacity: 0; transition: opacity 0.7s; pointer-events: none; }
.d2d-img.revealed { opacity: 1; }
.d2d-dot { position: absolute; width: 28px; height: 28px; background: #fff; border: 2px solid #2563eb; border-radius: 50%; color: #2563eb; font-weight: bold; display: flex; align-items: center; justify-content: center; user-select: none; font-size: 16px; box-shadow: 0 2px 8px #0002; z-index: 2; transform: translate(-50%,-50%); transition: background 0.2s; }
.d2d-dot.connected { background: #a7f3d0; border-color: #059669; color: #059669; }
.d2d-completed { color: #059669; font-weight: bold; font-size: 20px; margin-top: 18px; text-align: center; display: none; }
.d2d-btn { border: none; border-radius: 999px; padding: 10px 18px; font-weight: bold; background: #2563eb; color: #fff; cursor: pointer; font-size: 16px; margin-top: 18px; display: none; }
</style>

<div class="d2d-viewer-shell">
  <div class="d2d-intro">
    <h2><?= htmlspecialchars($viewerTitle) ?></h2>
    <p><?= htmlspecialchars($activity['instruction'] ?? '') ?></p>
  </div>
  <?php if (!$image || count($points) < 3): ?>
    <div style="color:#be123c;font-weight:bold;margin:24px 0;">This activity has no image or not enough points.</div>
  <?php else: ?>
    <div class="d2d-stage" id="d2dStage">
      <img src="<?= htmlspecialchars($image) ?>" class="d2d-img" id="d2dImg" alt="final image">
      <!-- Dots rendered by JS -->
    </div>
    <div class="d2d-completed" id="completedMsg">Activity completed!</div>
    <button class="d2d-btn" id="revealBtn">Reveal image</button>
  <?php endif; ?>
</div>

<?php if ($image && count($points) >= 3): ?>
<script>
const points = <?= json_encode($points, JSON_UNESCAPED_UNICODE) ?>;
const stage = document.getElementById('d2dStage');
const img = document.getElementById('d2dImg');
const revealBtn = document.getElementById('revealBtn');
const completedMsg = document.getElementById('completedMsg');
let connected = 0;

function renderDots(connectedCount = 0) {
  stage.querySelectorAll('.d2d-dot').forEach(dot => dot.remove());
  points.forEach((pt, i) => {
    const dot = document.createElement('div');
    dot.className = 'd2d-dot' + (i < connectedCount ? ' connected' : '');
    dot.textContent = pt.label || (i+1);
    dot.style.left = (pt.x * 320) + 'px';
    dot.style.top = (pt.y * 320) + 'px';
    stage.appendChild(dot);
  });
}
renderDots(connected);

stage.addEventListener('click', function(e) {
  if (img.classList.contains('revealed')) return;
  const rect = stage.getBoundingClientRect();
  const x = (e.clientX - rect.left) / rect.width;
  const y = (e.clientY - rect.top) / rect.height;
  if (connected < points.length) {
    const pt = points[connected];
    if (Math.abs(x - pt.x) < 0.05 && Math.abs(y - pt.y) < 0.05) {
      connected++;
      renderDots(connected);
      if (connected === points.length) {
        revealBtn.style.display = 'inline-block';
      }
    }
  }
});

revealBtn.addEventListener('click', function() {
  img.classList.add('revealed');
  revealBtn.style.display = 'none';
  completedMsg.style.display = 'block';
  // Feedback and navigation flow
  setTimeout(() => {
    completedMsg.textContent = 'You completed this activity!';
  }, 500);
});
</script>
<?php endif; ?>

<?php
render_activity_viewer($viewerTitle, '🔵', $content);
// End of file
