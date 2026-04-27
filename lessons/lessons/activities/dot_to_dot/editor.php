<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/dot_to_dot_functions.php';

session_start();
if (empty($_SESSION['academic_logged']) && empty($_SESSION['admin_logged'])) {
    header('Location: /lessons/lessons/academic/login.php');
    exit;
}

$activityId = $_GET['id'] ?? '';
$unit = $_GET['unit'] ?? '';
if (!$unit && $activityId) {
    // Try to resolve unit from activity if needed (not implemented here)
    $unit = '';
}
if (!$unit) die('Unit not specified');

$error = '';
$saved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['activity_title'] ?? '');
    $instruction = trim($_POST['activity_instruction'] ?? '');
    $pointsRaw = $_POST['points_json'] ?? '[]';
    $image = trim($_POST['image_existing'] ?? '');
    $labelMode = $_POST['label_mode'] ?? 'number';
    $labelStart = (int)($_POST['label_start'] ?? 1);
    $labelStep = (int)($_POST['label_step'] ?? 1);
    $labelEnd = (int)($_POST['label_end'] ?? 20);

    $labelSettings = dot_to_dot_normalize_label_settings([
        'mode' => $labelMode,
        'start' => $labelStart,
        'step' => $labelStep,
        'end' => $labelEnd,
    ], 0);

    // Handle image upload
    if (!empty($_FILES['main_image']['tmp_name'])) {
        $target = '/tmp/d2d_' . uniqid() . '_' . basename($_FILES['main_image']['name']);
        if (move_uploaded_file($_FILES['main_image']['tmp_name'], $target)) {
            $image = '/uploads/' . basename($target); // You should move/copy to a public uploads dir in production
        }
    }

    $points = json_decode($pointsRaw, true);
    if (!is_array($points)) $points = [];

    if (!$image) {
        $error = 'Please upload an image.';
    } elseif (count($points) < 3) {
        $error = 'Add at least 3 points.';
    } else {
        $id = save_dot_to_dot_activity($pdo, $unit, $activityId, $title, $instruction, $image, $points, $labelSettings);
        $saved = true;
        $activityId = $id;
    }
}

// ...existing code...
$activity = $activityId ? load_dot_to_dot_activity($pdo, $unit, $activityId) : [
    'title' => '',
    'instruction' => '',
    'image' => '',
    'label_settings' => dot_to_dot_default_label_settings(),
    'points' => [],
];

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Dot to Dot Editor</title>
    <style>
        body { font-family: sans-serif; background: #f5f5f5; }
        .container { max-width: 700px; margin: 40px auto; background: #fff; border-radius: 12px; box-shadow: 0 4px 24px #0001; padding: 32px; }
        .stage { position: relative; width: 320px; height: 320px; background: #f8fafc; border-radius: 14px; box-shadow: 0 2px 8px #0001; overflow: hidden; margin: 0 auto 18px; }
        .stage img { width: 100%; height: 100%; object-fit: contain; position: absolute; left: 0; top: 0; z-index: 1; }
        .dot { position: absolute; width: 28px; height: 28px; background: #fff; border: 2px solid #2563eb; border-radius: 50%; color: #2563eb; font-weight: bold; display: flex; align-items: center; justify-content: center; user-select: none; font-size: 16px; box-shadow: 0 2px 8px #0002; z-index: 2; transform: translate(-50%,-50%); }
        .error { background: #fee2e2; color: #be123c; padding: 10px 16px; border-radius: 8px; margin-bottom: 12px; }
        .success { background: #ecfeff; color: #0f766e; padding: 10px 16px; border-radius: 8px; margin-bottom: 12px; }
        label { font-weight: bold; display: block; margin-top: 12px; }
        input[type="text"], textarea { width: 100%; padding: 8px; border-radius: 6px; border: 1px solid #bbb; }
        input[type="file"] { margin-top: 6px; }
        .actions { margin-top: 18px; display: flex; gap: 12px; }
        button { border: none; border-radius: 8px; padding: 10px 18px; font-weight: bold; background: #2563eb; color: #fff; cursor: pointer; }
        button:disabled { background: #bbb; }
    </style>
</head>
<body>
<div class="container">
    <h2>Dot to Dot Editor</h2>
    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($saved): ?><div class="success">Activity saved!</div><?php endif; ?>
    <form method="post" enctype="multipart/form-data" id="d2dEditorForm">
        <label>Title
            <input type="text" name="activity_title" value="<?= htmlspecialchars($activity['title']) ?>" required>
        </label>
        <label>Instruction
            <textarea name="activity_instruction" rows="2"><?= htmlspecialchars($activity['instruction']) ?></textarea>
        </label>
        <label>Image
            <input type="file" name="main_image" accept="image/*">
            <input type="hidden" name="image_existing" id="image_existing" value="<?= htmlspecialchars($activity['image']) ?>">
        </label>
        <div class="stage" id="dotStage">
            <img id="dotImg" src="<?= htmlspecialchars($activity['image']) ?>" alt="dot-to-dot template" style="display:<?= $activity['image'] ? 'block' : 'none' ?>;">
            <!-- Dots rendered by JS -->
        </div>
        <input type="hidden" name="points_json" id="points_json" value="<?= htmlspecialchars(json_encode($activity['points'])) ?>">
        <label>Label mode
            <select name="label_mode">
                <option value="number"<?= $activity['label_settings']['mode']==='number'?' selected':'' ?>>Number</option>
                <option value="letter"<?= $activity['label_settings']['mode']==='letter'?' selected':'' ?>>Letter</option>
                <option value="word"<?= $activity['label_settings']['mode']==='word'?' selected':'' ?>>Word</option>
            </select>
        </label>
        <label>Start <input type="number" name="label_start" value="<?= (int)$activity['label_settings']['start'] ?>" min="1"></label>
        <label>Step <input type="number" name="label_step" value="<?= (int)$activity['label_settings']['step'] ?>" min="1"></label>
        <label>End <input type="number" name="label_end" value="<?= (int)$activity['label_settings']['end'] ?>" min="1"></label>
        <div class="actions">
            <button type="button" id="undoBtn">Undo last point</button>
            <button type="submit">Save activity</button>
        </div>
    </form>
</div>
<script>
const dotStage = document.getElementById('dotStage');
const dotImg = document.getElementById('dotImg');
const pointsInput = document.getElementById('points_json');
const undoBtn = document.getElementById('undoBtn');
let points = JSON.parse(pointsInput.value || '[]');
let current = points.length + 1;

function renderDots() {
    dotStage.querySelectorAll('.dot').forEach(dot => dot.remove());
    points.forEach((pt, i) => {
        const dot = document.createElement('div');
        dot.className = 'dot';
        dot.textContent = i+1;
        dot.style.left = (pt.x * 320) + 'px';
        dot.style.top = (pt.y * 320) + 'px';
        dotStage.appendChild(dot);
    });
}
renderDots();

dotImg.addEventListener('click', function(e) {
    const rect = dotImg.getBoundingClientRect();
    const x = (e.clientX - rect.left) / rect.width;
    const y = (e.clientY - rect.top) / rect.height;
    if (x < 0 || x > 1 || y < 0 || y > 1) return;
    points.push({x, y});
    current++;
    pointsInput.value = JSON.stringify(points);
    renderDots();
});

undoBtn.addEventListener('click', function() {
    if (points.length === 0) return;
    points.pop();
    current--;
    pointsInput.value = JSON.stringify(points);
    renderDots();
});

document.querySelector('input[type="file"][name="main_image"]').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function(ev) {
        dotImg.src = ev.target.result;
        dotImg.style.display = 'block';
        points = [];
        current = 1;
        pointsInput.value = '[]';
        renderDots();
    };
    reader.readAsDataURL(file);
});
</script>
</body>
</html>
.d2d-card{background:#fff;border:1px solid #dbeafe;border-radius:16px;padding:14px;box-shadow:0 8px 20px rgba(15,23,42,.05)}
.d2d-card label{display:block;font-size:13px;font-weight:800;color:#0f766e;margin:0 0 6px}
.d2d-card input[type="text"],.d2d-card textarea,.d2d-card input[type="file"]{width:100%;border:1px solid #93c5fd;border-radius:10px;padding:10px 12px;background:#fff}
.d2d-card input[type="number"],.d2d-card select{width:100%;border:1px solid #93c5fd;border-radius:10px;padding:10px 12px;background:#fff}
.d2d-card textarea{resize:vertical;min-height:92px}
.d2d-row-2{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.d2d-row-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px}
.d2d-stage-wrap{background:linear-gradient(180deg,#ffffff 0%,#f8fafc 100%);border:1px dashed #93c5fd;border-radius:14px;padding:12px}
.d2d-stage{position:relative;border:2px solid #cbd5e1;border-radius:14px;background:#fff;display:flex;justify-content:center;align-items:center;min-height:280px;overflow:hidden}
.d2d-stage img{display:block;max-width:100%;height:auto;pointer-events:none;position:relative;z-index:1}
.d2d-overlay{position:absolute;inset:0;width:100%;height:100%;cursor:crosshair;z-index:2}
.d2d-empty{font-weight:800;color:#64748b;padding:24px;text-align:center}
.d2d-list{margin:0;padding:0;list-style:none;display:grid;gap:8px;max-height:340px;overflow:auto}
.d2d-list li{display:flex;justify-content:space-between;align-items:center;padding:8px 10px;border:1px solid #dbeafe;border-radius:10px;background:#f8fafc;font-weight:700;color:#1e3a8a}
.d2d-list-remove{border:none;border-radius:8px;background:#fee2e2;color:#be123c;font-weight:800;padding:4px 8px;cursor:pointer}
.d2d-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}
.d2d-btn{border:none;border-radius:999px;padding:10px 14px;font-weight:800;cursor:pointer}
.d2d-btn-add{background:linear-gradient(180deg,#22c55e,#15803d);color:#fff}
.d2d-btn-soft{background:#e0f2fe;color:#075985;border:1px solid #7dd3fc}
.d2d-btn-danger{background:linear-gradient(180deg,#f43f5e,#be123c);color:#fff}
.d2d-btn-save{background:linear-gradient(180deg,#0ea5e9,#0369a1);color:#fff}
.d2d-note{margin-top:8px;font-size:12px;color:#64748b;font-weight:700}
.d2d-cap{margin-top:8px;font-size:12px;color:#0f766e;font-weight:800}
.d2d-error,.d2d-ok{max-width:1020px;margin:0 auto 12px;border-radius:12px;padding:10px 14px;font-weight:800}
.d2d-error{background:#fff1f2;border:1px solid #fecdd3;color:#be123c}
.d2d-ok{background:#ecfeff;border:1px solid #99f6e4;color:#0f766e}
@media (max-width:900px){.d2d-grid{grid-template-columns:1fr}}
</style>

<?php if (isset($_GET['saved'])) { ?>
<p class="d2d-ok">Saved successfully.</p>
<?php } ?>
<?php if ($errorMessage !== '') { ?>
<p class="d2d-error"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
<?php } ?>

<form class="d2d-editor" id="d2dEditorForm" method="post" enctype="multipart/form-data" style="max-width: 900px; margin: 0 auto; min-height: 540px; display: flex; flex-direction: column; justify-content: center; align-items: center;">
    <section class="d2d-intro" style="width:100%;">
        <h3>Dot to Dot Editor</h3>
        <p>Sube la imagen final y haz clic sobre la imagen para agregar puntos en orden. El viewer mostrará solo los puntos y revelará la imagen al conectar todos.</p>
    </section>
    <div class="d2d-card" style="width:100%;max-width:600px;box-sizing:border-box;">
        <label for="activity_title">Título de la actividad</label>
        <input id="activity_title" name="activity_title" type="text" value="<?= htmlspecialchars($activityTitle, ENT_QUOTES, 'UTF-8') ?>" required>

        <label for="activity_instruction" style="margin-top:10px;">Instrucción</label>
        <textarea id="activity_instruction" name="activity_instruction" placeholder="Ejemplo: Une los puntos en orden."><?= htmlspecialchars($activityInstruction, ENT_QUOTES, 'UTF-8') ?></textarea>

        <label for="main_image" style="margin-top:10px;">Imagen final a ocultar</label>
        <input id="main_image" name="main_image" type="file" accept="image/*">
        <input type="hidden" name="image_existing" id="image_existing" value="<?= htmlspecialchars($activityImage, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="points_json" id="points_json" value="<?= htmlspecialchars(json_encode($activityPoints, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>">

        <div id="dotStage" style="position:relative;width:320px;height:320px;margin:18px auto;background:#f8fafc;border-radius:14px;box-shadow:0 2px 8px #0001;overflow:hidden;">
            <img id="dotImg" src="<?= htmlspecialchars($activityImage, ENT_QUOTES, 'UTF-8') ?>" alt="dot-to-dot template" style="width:100%;height:100%;object-fit:contain;display:<?= $activityImage === '' ? 'none' : 'block' ?>;position:absolute;left:0;top:0;z-index:1;">
            <!-- Los puntos se agregan aquí -->
        </div>

        <div style="margin-bottom:10px;display:flex;gap:12px;justify-content:center;">
            <button type="button" class="d2d-btn d2d-btn-soft" id="undoPointBtn">Deshacer último punto</button>
            <button type="submit" class="d2d-btn d2d-btn-save">Guardar actividad</button>
        </div>
        <p class="d2d-note" style="text-align:center;">Haz clic sobre la imagen para agregar puntos en orden. Mínimo: 3 puntos.</p>
    </div>
</form>

<style>
#dotStage { position: relative; }
.dot {
    position: absolute;
    width: 28px;
    height: 28px;
    background: #fff;
    border: 2px solid #2563eb;
    border-radius: 50%;
    color: #2563eb;
    font-weight: bold;
    display: flex;
    align-items: center;
    justify-content: center;
    pointer-events: auto;
    user-select: none;
    cursor: pointer;
    font-size: 16px;
    box-shadow: 0 2px 8px #0002;
    opacity: 1;
    z-index: 2;
    transform: translate(-50%, -50%);
}
#dotImg {
    opacity: 1;
    z-index: 1;
}
</style>
<script>
let points = [];
let current = 1;

const imgInput = document.getElementById('main_image');
const dotImg = document.getElementById('dotImg');
const dotStage = document.getElementById('dotStage');
const pointsInput = document.getElementById('points_json');
const undoBtn = document.getElementById('undoPointBtn');

// Cargar imagen subida
imgInput.addEventListener('change', function(e) {
  const file = e.target.files[0];
  if (!file) return;
  const reader = new FileReader();
  reader.onload = function(ev) {
    dotImg.src = ev.target.result;
    dotImg.style.display = 'block';
    clearDots();
  };
  reader.readAsDataURL(file);
});



// El máximo se define automáticamente al guardar (último punto agregado)
let autoMax = null;
dotImg.addEventListener('click', function(e) {
    if (dotImg.classList.contains('revealed')) return;
    const rect = dotImg.getBoundingClientRect();
    const x = (e.clientX - rect.left) / rect.width;
    const y = (e.clientY - rect.top) / rect.height;
    // Solo permitir puntos dentro de la imagen visible
    if (x < 0 || x > 1 || y < 0 || y > 1) return;
    addDot(x, y, current);
    points.push({x, y});
    current++;
    updatePointsInput();
    if (points.length >= 3) {
        autoMax = points.length;
    }
});

// Al guardar, si hay al menos 3 puntos, revela la imagen y oculta los puntos

document.getElementById('d2dEditorForm').addEventListener('submit', function(e) {
    if (points.length < 3) return;
    // No redirigir, dejar que el formulario se procese normalmente en PHP
    // revealImage();
});

// También, si el usuario agrega un punto y ya no puede agregar más (opcional, si quieres bloquear el click)
// puedes deshabilitar el click en la imagen después del último punto

// Permitir revelar imagen manualmente si no hay máximo definido
function revealImage() {
    // No hacer fade, solo bloquear más clicks
    dotImg.style.pointerEvents = 'none';
}

// Si quieres que la imagen se revele cuando el usuario haga clic en un botón, puedes agregar un botón y llamar a revealImage()

function addDot(x, y, number) {
    const dot = document.createElement('div');
    dot.className = 'dot';
    dot.textContent = number;
    // El contenedor dotStage y la imagen siempre son 320x320
    dot.style.left = (x * 320) + 'px';
    dot.style.top = (y * 320) + 'px';
    dotStage.appendChild(dot);
}

function clearDots() {
  points = [];
  current = 1;
  document.querySelectorAll('.dot').forEach(dot => dot.remove());
  updatePointsInput();
}

function updatePointsInput() {
  pointsInput.value = JSON.stringify(points);
}

undoBtn.addEventListener('click', function() {
    if (points.length === 0) return;
    points.pop();
    current--;
    const lastDot = dotStage.querySelector('.dot:last-child');
    if (lastDot) lastDot.remove();
    updatePointsInput();
});

// Si quieres que la imagen se revele automáticamente al llegar a cierto número de puntos, define window.DOT_TO_DOT_MAX = N;
// Ejemplo: window.DOT_TO_DOT_MAX = 10; // para 10 puntos
</script>

<?php
$content = ob_get_clean();
render_activity_editor('Dot to Dot Editor', 'fas fa-pencil-ruler', $content);
