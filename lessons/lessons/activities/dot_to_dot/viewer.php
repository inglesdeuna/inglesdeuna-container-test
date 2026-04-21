<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/dot_to_dot_functions.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
if ($unit === '' && $activityId !== '') {
    $unit = dot_to_dot_resolve_unit_from_activity($pdo, $activityId);
}
if ($unit === '') {
    die('Unit not specified');
}

$activity = load_dot_to_dot_activity($pdo, $unit, $activityId);
$activityTitle = isset($activity['title']) ? (string) $activity['title'] : 'Dot to Dot';
$activityInstruction = isset($activity['instruction']) ? (string) $activity['instruction'] : '';
$activityImage = isset($activity['image']) ? (string) $activity['image'] : '';
$activityLabelSettings = isset($activity['label_settings']) && is_array($activity['label_settings'])
  ? normalize_dot_to_dot_label_settings($activity['label_settings'], isset($activity['points']) && is_array($activity['points']) ? count($activity['points']) : 0)
  : default_dot_to_dot_label_settings();
$activityPoints = isset($activity['points']) && is_array($activity['points']) ? dot_to_dot_apply_labels($activity['points'], $activityLabelSettings) : array();


?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($activityTitle) ?> - Dot to Dot</title>
<style>
body { margin:0; background:#f5f5f5; }
.d2d-viewer-container {
  max-width: 480px;
  margin: 40px auto;
  background: #fff;
  border-radius: 18px;
  box-shadow: 0 8px 32px #0002;
  padding: 24px 18px 32px 18px;
  display: flex;
  flex-direction: column;
  align-items: center;
}
.d2d-title { font-family:'Fredoka','Trebuchet MS',sans-serif;font-size:24px;color:#0f172a;margin-bottom:8px; }
.d2d-instruction { color:#0f766e;font-weight:700;margin-bottom:18px;text-align:center; }
.d2d-stage { position:relative;width:320px;height:320px;background:#f8fafc;border-radius:14px;box-shadow:0 2px 8px #0001;overflow:hidden;margin-bottom:18px; }
.d2d-img { width:100%;height:100%;object-fit:contain;position:absolute;left:0;top:0;z-index:1;opacity:0;transition:opacity 0.7s;pointer-events:none; }
.d2d-img.revealed { opacity:1; }
.d2d-dot { position:absolute;width:28px;height:28px;background:#fff;border:2px solid #2563eb;border-radius:50%;color:#2563eb;font-weight:bold;display:flex;align-items:center;justify-content:center;user-select:none;font-size:16px;box-shadow:0 2px 8px #0002;z-index:2;transform:translate(-50%,-50%);transition:background 0.2s; }
.d2d-dot.connected { background:#a7f3d0;border-color:#059669;color:#059669; }
.d2d-btn { border:none;border-radius:999px;padding:10px 18px;font-weight:800;cursor:pointer;font-size:16px;transition:background 0.2s; }
.d2d-btn-next { background:linear-gradient(180deg,#22c55e,#15803d);color:#fff;margin-top:18px; }
.d2d-btn-reveal { background:linear-gradient(180deg,#0ea5e9,#0369a1);color:#fff;margin-top:18px; }
.d2d-completed { color:#059669;font-weight:800;font-size:20px;margin-top:18px;text-align:center; }

</head>
<body>
<div class="d2d-viewer-container">
  <div class="d2d-title"><?= htmlspecialchars($activityTitle) ?></div>
  <div class="d2d-instruction"><?= htmlspecialchars($activityInstruction) ?></div>
  <?php if (!$activityImage || count($activityPoints) < 3): ?>
    <div class="d2d-empty">Esta actividad no tiene imagen o puntos suficientes.<br>Por favor, edítala para agregar contenido.</div>
  <?php else: ?>
    <div class="d2d-stage" id="d2dStage">
      <img src="<?= htmlspecialchars($activityImage) ?>" class="d2d-img" id="d2dImg" alt="Imagen final" />
      <!-- Dots se agregan por JS -->
    </div>
    <button class="d2d-btn d2d-btn-reveal" id="revealBtn">Revelar imagen</button>
    <button class="d2d-btn d2d-btn-next" id="nextBtn" style="display:none;">Siguiente</button>
    <div class="d2d-completed" id="completedMsg" style="display:none;">¡Actividad completada!</div>
  <?php endif; ?>
</div>
<script>
<?php if ($activityImage && count($activityPoints) >= 3): ?>
<script>
const points = <?= json_encode($activityPoints, JSON_UNESCAPED_UNICODE) ?>;
const stage = document.getElementById('d2dStage');
const img = document.getElementById('d2dImg');
const revealBtn = document.getElementById('revealBtn');
const nextBtn = document.getElementById('nextBtn');
const completedMsg = document.getElementById('completedMsg');

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

let connected = 0;
renderDots(connected);

stage.addEventListener('click', function(e) {
  if (img && img.classList.contains('revealed')) return;
  const rect = stage.getBoundingClientRect();
  const x = (e.clientX - rect.left) / rect.width;
  const y = (e.clientY - rect.top) / rect.height;
  if (connected < points.length) {
    const pt = points[connected];
    const px = pt.x, py = pt.y;
    if (Math.abs(x - px) < 0.05 && Math.abs(y - py) < 0.05) {
      connected++;
      renderDots(connected);
      if (connected === points.length) {
        revealBtn.style.display = 'inline-block';
        revealBtn.textContent = 'Revelar imagen';
      }
    }
  }
});

revealBtn.addEventListener('click', function() {
  if (img) img.classList.add('revealed');
  revealBtn.style.display = 'none';
  completedMsg.style.display = 'block';
  nextBtn.style.display = 'inline-block';
});

nextBtn.addEventListener('click', function() {
  window.location.href = '/lessons/lessons/activities/completed.php';
});
</script>
<?php endif; ?>
</script>
</body>
</html>
