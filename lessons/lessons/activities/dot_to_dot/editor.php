<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/cloudinary_upload.php';
require_once __DIR__ . '/dot_to_dot_functions.php';
require_once __DIR__ . '/../../core/_activity_editor_template.php';

if (isset($_SESSION['student_logged']) && $_SESSION['student_logged']) {
    header('Location: /lessons/lessons/academic/student_dashboard.php?error=access_denied');
    exit;
}

$isLoggedIn = !empty($_SESSION['academic_logged']) || !empty($_SESSION['admin_logged']);
if (!$isLoggedIn) {
    header('Location: /lessons/lessons/academic/login.php');
    exit;
}

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
$source = isset($_GET['source']) ? trim((string) $_GET['source']) : '';
$assignment = isset($_GET['assignment']) ? trim((string) $_GET['assignment']) : '';

if ($unit === '' && $activityId !== '') {
    $unit = dot_to_dot_resolve_unit_from_activity($pdo, $activityId);
}

if ($unit === '') {
    die('Unit not specified');
}

$activity = load_dot_to_dot_activity($pdo, $unit, $activityId);
if ($activityId === '' && !empty($activity['id'])) {
    $activityId = (string) $activity['id'];
}

$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedTitle = isset($_POST['activity_title']) ? trim((string) $_POST['activity_title']) : '';
    $postedInstruction = isset($_POST['activity_instruction']) ? trim((string) $_POST['activity_instruction']) : '';
    $postedLabelMode = isset($_POST['label_mode']) ? strtolower(trim((string) $_POST['label_mode'])) : 'number';
    $postedSequenceStart = isset($_POST['sequence_start']) ? (int) $_POST['sequence_start'] : 1;
    $postedSequenceStep = isset($_POST['sequence_step']) ? (int) $_POST['sequence_step'] : 1;
    $postedSequenceEnd = isset($_POST['sequence_end']) ? (int) $_POST['sequence_end'] : 20;

    if (!in_array($postedLabelMode, array('number', 'letter', 'word'), true)) {
        $postedLabelMode = 'number';
    }
    if ($postedSequenceStart < 1) {
        $postedSequenceStart = 1;
    }
    if ($postedSequenceStep < 1) {
        $postedSequenceStep = 1;
    }
    if ($postedSequenceEnd < $postedSequenceStart) {
        $postedSequenceEnd = $postedSequenceStart;
    }

    $labelSettings = normalize_dot_to_dot_label_settings(array(
        'mode' => $postedLabelMode,
        'start' => $postedSequenceStart,
        'step' => $postedSequenceStep,
        'end' => $postedSequenceEnd,
    ));

    $maxPoints = (int) floor((($labelSettings['end'] - $labelSettings['start']) / $labelSettings['step'])) + 1;
    if ($maxPoints < 1) {
        $maxPoints = 1;
    }

    $existingImage = isset($_POST['image_existing']) ? trim((string) $_POST['image_existing']) : '';
    $pointsRaw = isset($_POST['points_json']) ? trim((string) $_POST['points_json']) : '[]';

    $image = $existingImage;

    if (isset($_FILES['main_image']) && is_array($_FILES['main_image'])
        && !empty($_FILES['main_image']['tmp_name']) && !empty($_FILES['main_image']['name'])) {
        $uploaded = upload_to_cloudinary((string) $_FILES['main_image']['tmp_name']);
        if ($uploaded) {
            $image = (string) $uploaded;
        }
    }

    $points = array();
    $decodedPoints = json_decode($pointsRaw, true);

    if (is_array($decodedPoints)) {
        foreach ($decodedPoints as $point) {
            if (!is_array($point)) {
                continue;
            }

            $x = isset($point['x']) ? (float) $point['x'] : -1;
            $y = isset($point['y']) ? (float) $point['y'] : -1;

            if ($x < 0 || $x > 1 || $y < 0 || $y > 1) {
                continue;
            }

            $points[] = array('x' => round($x, 6), 'y' => round($y, 6));
        }
    }

    if (count($points) > $maxPoints) {
        $errorMessage = 'You placed ' . count($points) . ' points but this sequence allows only ' . $maxPoints . '. Increase end value, reduce step, or remove points.';
    } elseif ($image === '') {
        $errorMessage = 'Upload a final colored image before saving.';
    } elseif (count($points) < 3) {
        $errorMessage = 'Add at least 3 points to create a playable dot-to-dot.';
    } else {
        $savedActivityId = save_dot_to_dot_activity(
            $pdo,
            $unit,
            $activityId,
            $postedTitle,
            $postedInstruction,
            $image,
            $points,
            $labelSettings
        );

        $params = array('unit=' . urlencode($unit), 'saved=1');
        if ($savedActivityId !== '') {
            $params[] = 'id=' . urlencode($savedActivityId);
        }
        if ($assignment !== '') {
            $params[] = 'assignment=' . urlencode($assignment);
        }
        if ($source !== '') {
            $params[] = 'source=' . urlencode($source);
        }

        header('Location: editor.php?' . implode('&', $params));
        exit;
    }

    $activity['title'] = $postedTitle !== '' ? $postedTitle : default_dot_to_dot_title();
    $activity['instruction'] = $postedInstruction !== ''
        ? $postedInstruction
        : 'Connect the dots in order to reveal the picture.';
    $activity['image'] = $image;
    $activity['label_settings'] = $labelSettings;
    $activity['points'] = dot_to_dot_apply_labels($points, $labelSettings);
}

$activityTitle = isset($activity['title']) ? (string) $activity['title'] : default_dot_to_dot_title();
$activityInstruction = isset($activity['instruction'])
    ? (string) $activity['instruction']
    : 'Connect the dots in order to reveal the picture.';
$activityImage = isset($activity['image']) ? (string) $activity['image'] : '';
$activityPoints = isset($activity['points']) && is_array($activity['points']) ? $activity['points'] : array();
$activityLabelSettings = isset($activity['label_settings']) && is_array($activity['label_settings'])
    ? normalize_dot_to_dot_label_settings($activity['label_settings'], count($activityPoints))
    : default_dot_to_dot_label_settings();
$activityPoints = dot_to_dot_apply_labels($activityPoints, $activityLabelSettings);

ob_start();
?>
<style>
.d2d-editor{max-width:1020px;margin:0 auto}
.d2d-intro{background:linear-gradient(135deg,#ecfeff 0%,#eef2ff 55%,#fff7ed 100%);border:1px solid #bfdbfe;border-radius:18px;padding:16px 18px;margin-bottom:14px;box-shadow:0 14px 28px rgba(15,23,42,.08)}
.d2d-intro h3{margin:0 0 6px;font-family:'Fredoka','Trebuchet MS',sans-serif;font-size:24px;color:#0f172a}
.d2d-intro p{margin:0;color:#0f766e;font-weight:700;line-height:1.5}
.d2d-grid{display:grid;grid-template-columns:340px 1fr;gap:14px}
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
