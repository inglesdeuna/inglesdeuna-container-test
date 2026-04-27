<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/dot_to_dot_functions.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_editor_template.php';

if (empty($_SESSION['academic_logged']) && empty($_SESSION['admin_logged'])) {
    header('Location: /lessons/lessons/academic/login.php');
    exit;
}

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';

if ($unit === '' && $activityId !== '') {
    $unit = dot_to_dot_resolve_unit_from_activity($pdo, $activityId);
}
if ($unit === '') {
    die('Unit not specified');
}

$error = '';
$saved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title       = trim($_POST['activity_title'] ?? '');
    $instruction = trim($_POST['activity_instruction'] ?? '');
    $pointsRaw   = $_POST['points_json'] ?? '[]';
    $image       = trim($_POST['image_existing'] ?? '');
    $labelMode   = $_POST['label_mode'] ?? 'number';
    $labelStart  = (int) ($_POST['label_start'] ?? 1);
    $labelStep   = (int) ($_POST['label_step'] ?? 1);
    $labelEnd    = (int) ($_POST['label_end'] ?? 20);

    $labelSettings = dot_to_dot_normalize_label_settings([
        'mode'  => $labelMode,
        'start' => $labelStart,
        'step'  => $labelStep,
        'end'   => $labelEnd,
    ], 0);

    if (!empty($_FILES['main_image']['tmp_name'])) {
        $target = '/tmp/d2d_' . uniqid() . '_' . basename($_FILES['main_image']['name']);
        if (move_uploaded_file($_FILES['main_image']['tmp_name'], $target)) {
            $image = '/uploads/' . basename($target);
        }
    }

    $points = json_decode($pointsRaw, true);
    if (!is_array($points)) {
        $points = [];
    }

    if ($image === '') {
        $error = 'Please upload an image.';
    } elseif (count($points) < 3) {
        $error = 'Add at least 3 points.';
    } else {
        $id        = save_dot_to_dot_activity($pdo, $unit, $activityId, $title, $instruction, $image, $points, $labelSettings);
        $saved     = true;
        $activityId = $id;
    }
}

$activity = ($activityId !== '')
    ? load_dot_to_dot_activity($pdo, $unit, $activityId)
    : [
        'title'          => '',
        'instruction'    => '',
        'image'          => '',
        'label_settings' => dot_to_dot_default_label_settings(),
        'points'         => [],
    ];

$activityTitle       = $activity['title'];
$activityInstruction = $activity['instruction'];
$activityImage       = $activity['image'];
$activityPoints      = $activity['points'];
$activityLabels      = $activity['label_settings'];
$errorMessage        = $error;

ob_start();
?>
<style>
.d2d-card{background:#fff;border:1px solid #dbeafe;border-radius:16px;padding:14px;box-shadow:0 8px 20px rgba(15,23,42,.05)}
.d2d-card label{display:block;font-size:13px;font-weight:800;color:#0f766e;margin:0 0 6px}
.d2d-card input[type="text"],.d2d-card textarea,.d2d-card input[type="file"]{width:100%;border:1px solid #93c5fd;border-radius:10px;padding:10px 12px;background:#fff;box-sizing:border-box}
.d2d-card input[type="number"],.d2d-card select{width:100%;border:1px solid #93c5fd;border-radius:10px;padding:10px 12px;background:#fff;box-sizing:border-box}
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
#dotStage{position:relative;}
.dot{position:absolute;width:28px;height:28px;background:#fff;border:2px solid #2563eb;border-radius:50%;color:#2563eb;font-weight:bold;display:flex;align-items:center;justify-content:center;pointer-events:auto;user-select:none;cursor:pointer;font-size:16px;box-shadow:0 2px 8px #0002;z-index:2;transform:translate(-50%,-50%)}
#dotImg{opacity:1;z-index:1}
@media (max-width:900px){.d2d-row-2,.d2d-row-3{grid-template-columns:1fr}}
</style>

<?php if ($saved) { ?>
<p class="d2d-ok">Guardado correctamente.</p>
<?php } ?>
<?php if ($errorMessage !== '') { ?>
<p class="d2d-error"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
<?php } ?>

<form class="d2d-editor" id="d2dEditorForm" method="post" enctype="multipart/form-data" style="max-width:900px;margin:0 auto;">
    <section class="d2d-intro" style="margin-bottom:16px;">
        <h3>Dot to Dot Editor</h3>
        <p>Sube la imagen final y haz clic sobre la imagen para agregar puntos en orden. El viewer mostrará solo los puntos y revelará la imagen al conectar todos.</p>
    </section>

    <div class="d2d-card" style="max-width:600px;box-sizing:border-box;margin:0 auto;">
        <label for="activity_title">Título de la actividad</label>
        <input id="activity_title" name="activity_title" type="text"
               value="<?= htmlspecialchars($activityTitle, ENT_QUOTES, 'UTF-8') ?>" required>

        <label for="activity_instruction" style="margin-top:10px;">Instrucción</label>
        <textarea id="activity_instruction" name="activity_instruction"
                  placeholder="Ejemplo: Une los puntos en orden."><?= htmlspecialchars($activityInstruction, ENT_QUOTES, 'UTF-8') ?></textarea>

        <label for="main_image" style="margin-top:10px;">Imagen final a ocultar</label>
        <input id="main_image" name="main_image" type="file" accept="image/*">
        <input type="hidden" name="image_existing" id="image_existing"
               value="<?= htmlspecialchars($activityImage, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="points_json" id="points_json"
               value="<?= htmlspecialchars(json_encode($activityPoints, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>">

        <div id="dotStage" style="position:relative;width:320px;height:320px;margin:18px auto;background:#f8fafc;border-radius:14px;box-shadow:0 2px 8px #0001;overflow:hidden;">
            <img id="dotImg"
                 src="<?= htmlspecialchars($activityImage, ENT_QUOTES, 'UTF-8') ?>"
                 alt="dot-to-dot template"
                 style="width:100%;height:100%;object-fit:contain;display:<?= $activityImage === '' ? 'none' : 'block' ?>;position:absolute;left:0;top:0;z-index:1;">
        </div>

        <div style="margin-bottom:10px;display:flex;gap:12px;justify-content:center;">
            <button type="button" class="d2d-btn d2d-btn-soft" id="undoPointBtn">Deshacer último punto</button>
            <button type="submit" class="d2d-btn d2d-btn-save">Guardar actividad</button>
        </div>
        <p class="d2d-note" style="text-align:center;">Haz clic sobre la imagen para agregar puntos en orden. Mínimo: 3 puntos.</p>
    </div>
</form>

<script>
(function () {
    var points = <?= json_encode(array_map(function($p) {
        return ['x' => isset($p['x']) ? (float)$p['x'] : 0, 'y' => isset($p['y']) ? (float)$p['y'] : 0];
    }, $activityPoints), JSON_UNESCAPED_UNICODE) ?>;
    var current = points.length + 1;

    var imgInput   = document.getElementById('main_image');
    var dotImg     = document.getElementById('dotImg');
    var dotStage   = document.getElementById('dotStage');
    var pointsInput = document.getElementById('points_json');
    var undoBtn    = document.getElementById('undoPointBtn');

    // Render existing points on load
    points.forEach(function(p, i) { addDot(p.x, p.y, i + 1); });

    imgInput.addEventListener('change', function(e) {
        var file = e.target.files[0];
        if (!file) return;
        var reader = new FileReader();
        reader.onload = function(ev) {
            dotImg.src = ev.target.result;
            dotImg.style.display = 'block';
            clearDots();
        };
        reader.readAsDataURL(file);
    });

    dotImg.addEventListener('click', function(e) {
        var rect = dotImg.getBoundingClientRect();
        var x = (e.clientX - rect.left) / rect.width;
        var y = (e.clientY - rect.top) / rect.height;
        if (x < 0 || x > 1 || y < 0 || y > 1) return;
        addDot(x, y, current);
        points.push({ x: x, y: y });
        current++;
        updatePointsInput();
    });

    document.getElementById('d2dEditorForm').addEventListener('submit', function(e) {
        if (points.length < 3) {
            e.preventDefault();
            alert('Agrega al menos 3 puntos antes de guardar.');
        }
    });

    undoBtn.addEventListener('click', function() {
        if (points.length === 0) return;
        points.pop();
        current--;
        var lastDot = dotStage.querySelector('.dot:last-child');
        if (lastDot) lastDot.remove();
        updatePointsInput();
    });

    function addDot(x, y, number) {
        var dot = document.createElement('div');
        dot.className = 'dot';
        dot.textContent = number;
        dot.style.left = (x * 320) + 'px';
        dot.style.top  = (y * 320) + 'px';
        dotStage.appendChild(dot);
    }

    function clearDots() {
        points = [];
        current = 1;
        dotStage.querySelectorAll('.dot').forEach(function(d) { d.remove(); });
        updatePointsInput();
    }

    function updatePointsInput() {
        pointsInput.value = JSON.stringify(points);
    }
}());
</script>

<?php
$content = ob_get_clean();
render_activity_editor('Dot to Dot Editor', 'fas fa-pencil-ruler', $content);
