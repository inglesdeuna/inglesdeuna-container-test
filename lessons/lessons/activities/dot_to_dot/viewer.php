<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/dot_to_dot_functions.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';
$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
if ($unit === '' && $activityId !== '') {
    $unit = dot_to_dot_resolve_unit_from_activity($pdo, $activityId);
}
$activity = load_dot_to_dot_activity($pdo, $unit, $activityId);
$title = isset($activity['title']) ? (string) $activity['title'] : 'Dot to Dot';
$image = isset($activity['image']) ? (string) $activity['image'] : '';
$points = isset($activity['points']) && is_array($activity['points']) ? array_values($activity['points']) : array();
$cssVersion = (string) (@filemtime(__DIR__ . '/dot_to_dot.css') ?: time());
ob_start();
?>
<link rel="stylesheet" href="dot_to_dot.css?v=<?= htmlspecialchars($cssVersion, ENT_QUOTES, 'UTF-8') ?>">
<div style="max-width:500px;margin:40px auto 0;text-align:center;">
    <h2><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h2>
    <?php if ($image && count($points) >= 3) { ?>
        <div style="position:relative;display:inline-block;max-width:100%;">
            <img id="dotImg" src="<?= htmlspecialchars($image, ENT_QUOTES, 'UTF-8') ?>" style="max-width:400px;display:block;">
            <canvas id="dotCanvas" width="400" height="400" style="position:absolute;left:0;top:0;"></canvas>
        </div>
        <div style="margin-top:18px;">
            <button id="resetBtn" style="padding:8px 18px;font-size:15px;font-weight:bold;border-radius:8px;border:none;background:#0ea5e9;color:#fff;">Reset</button>
        </div>
    <?php } else { ?>
        <div style="padding:30px 0;">Activity not ready. Please add an image and at least 3 points.</div>
    <?php } ?>
</div>
<script>
const points = <?= json_encode($points, JSON_UNESCAPED_UNICODE) ?>;
const img = document.getElementById('dotImg');
const canvas = document.getElementById('dotCanvas');
const ctx = canvas ? canvas.getContext('2d') : null;
function drawDotsAndLines(current) {
  if (!ctx || !img.complete) return;
  ctx.clearRect(0,0,canvas.width,canvas.height);
  ctx.globalAlpha = 1;
  ctx.drawImage(img,0,0,canvas.width,canvas.height);
  ctx.fillStyle = '#0ea5e9';
  ctx.strokeStyle = '#0ea5e9';
  ctx.lineWidth = 3;
  for (let i=0; i<points.length; ++i) {
    const x = points[i].x * canvas.width;
    const y = points[i].y * canvas.height;
    ctx.beginPath();
    ctx.arc(x, y, 8, 0, 2*Math.PI);
    ctx.fill();
    ctx.fillStyle = '#fff';
    ctx.font = 'bold 15px sans-serif';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(i+1, x, y);
    ctx.fillStyle = '#0ea5e9';
    if (i > 0 && i <= current) {
      ctx.beginPath();
      ctx.moveTo(points[i-1].x * canvas.width, points[i-1].y * canvas.height);
      ctx.lineTo(x, y);
      ctx.stroke();
    }
  }
}
let current = 0;
if (img && canvas && ctx) {
  img.onload = () => drawDotsAndLines(current);
  drawDotsAndLines(current);
  canvas.addEventListener('click', function(e) {
    if (current >= points.length-1) return;
    const rect = canvas.getBoundingClientRect();
    const x = (e.clientX - rect.left) / rect.width * canvas.width;
    const y = (e.clientY - rect.top) / rect.height * canvas.height;
    const px = points[current+1].x * canvas.width;
    const py = points[current+1].y * canvas.height;
    if (Math.hypot(x-px, y-py) < 18) {
      current++;
      drawDotsAndLines(current);
    }
  });
  document.getElementById('resetBtn').onclick = function() {
    current = 0;
    drawDotsAndLines(current);
  };
}
</script>
<?php
$content = ob_get_clean();
render_activity_viewer($title, '🔢', $content);
