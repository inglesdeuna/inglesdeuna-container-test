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

<form class="d2d-editor" id="d2dEditorForm" method="post" enctype="multipart/form-data">
    <section class="d2d-intro">
        <h3>Dot to Dot Editor</h3>
        <p>Upload the final colored drawing, then click over the image to place point numbers in order. The viewer will show a white board with numbered dots and reveal this image after students connect all points.</p>
    </section>

    <div class="d2d-grid">
        <aside class="d2d-card">
            <label for="activity_title">Activity title</label>
            <input id="activity_title" name="activity_title" type="text" value="<?= htmlspecialchars($activityTitle, ENT_QUOTES, 'UTF-8') ?>" required>

            <label for="activity_instruction" style="margin-top:10px;">Instruction</label>
            <textarea id="activity_instruction" name="activity_instruction" placeholder="Example: Connect numbers in order from 1 to 24."><?= htmlspecialchars($activityInstruction, ENT_QUOTES, 'UTF-8') ?></textarea>

            <label for="label_mode" style="margin-top:10px;">Point labels mode</label>
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
                        <button type="button" class="d2d-btn d2d-btn-soft" id="addPointBtn">+ Add point</button>
                        <button type="button" class="d2d-btn d2d-btn-soft" id="autoPlaceBtn">Auto-place points on outline</button>
                        <span style="font-size:13px;color:#64748b;">(Manual o automático)</span>
                    </div>
            </select>

            <div class="d2d-row-3" style="margin-top:8px;">
                <div>
                    <label for="sequence_start">Start</label>
                    <input id="sequence_start" name="sequence_start" type="number" min="1" step="1" value="<?= (int) ($activityLabelSettings['start'] ?? 1) ?>">
                </div>
                <div>
                    <label for="sequence_step">Step</label>
                    <input id="sequence_step" name="sequence_step" type="number" min="1" step="1" value="<?= (int) ($activityLabelSettings['step'] ?? 1) ?>">
                </div>
                <div>
                    <label for="sequence_end">End</label>
                    <input id="sequence_end" name="sequence_end" type="number" min="1" step="1" value="<?= (int) ($activityLabelSettings['end'] ?? 20) ?>">
                </div>
            </div>
            <p class="d2d-cap" id="d2dCapacityInfo"></p>

            <label for="main_image" style="margin-top:10px;">Final colored image</label>
            <input id="main_image" name="main_image" type="file" accept="image/*">
            const autoPlaceBtn = document.getElementById('autoPlaceBtn');
            // --- Auto-place points on outline ---
            if (autoPlaceBtn) {
                autoPlaceBtn.addEventListener('click', function () {
                    if (!imageEl.complete || !imageEl.naturalWidth) {
                        alert('Load an image first.');
                        return;
                    }
                    const settings = normalizeSettings();
                    const numPoints = capacity(settings);
                    // Create a temp canvas to process the image
                    const tempCanvas = document.createElement('canvas');
                    const w = imageEl.naturalWidth;
                    const h = imageEl.naturalHeight;
                    tempCanvas.width = w;
                    tempCanvas.height = h;
                    const ctx = tempCanvas.getContext('2d');
                    ctx.drawImage(imageEl, 0, 0, w, h);
                    // Get grayscale
                    const imgData = ctx.getImageData(0, 0, w, h);
                    const gray = new Uint8ClampedArray(w * h);
                    for (let i = 0; i < w * h; i++) {
                        const r = imgData.data[i * 4];
                        const g = imgData.data[i * 4 + 1];
                        const b = imgData.data[i * 4 + 2];
                        gray[i] = 0.299 * r + 0.587 * g + 0.114 * b;
                    }
                    // Simple edge detection (Sobel)
                    const edge = new Uint8ClampedArray(w * h);
                    for (let y = 1; y < h - 1; y++) {
                        for (let x = 1; x < w - 1; x++) {
                            const gx =
                                -gray[(y - 1) * w + (x - 1)] - 2 * gray[y * w + (x - 1)] - gray[(y + 1) * w + (x - 1)]
                                + gray[(y - 1) * w + (x + 1)] + 2 * gray[y * w + (x + 1)] + gray[(y + 1) * w + (x + 1)];
                            const gy =
                                -gray[(y - 1) * w + (x - 1)] - 2 * gray[(y - 1) * w + x] - gray[(y - 1) * w + (x + 1)]
                                + gray[(y + 1) * w + (x - 1)] + 2 * gray[(y + 1) * w + x] + gray[(y + 1) * w + (x + 1)];
                            edge[y * w + x] = Math.sqrt(gx * gx + gy * gy) > 100 ? 255 : 0;
                        }
                    }
                    // Find edge pixels
                    const edgePixels = [];
                    for (let y = 0; y < h; y++) {
                        for (let x = 0; x < w; x++) {
                            if (edge[y * w + x] === 255) {
                                edgePixels.push({ x, y });
                            }
                        }
                    }
                    if (edgePixels.length < numPoints) {
                        alert('Could not detect enough outline points. Try a clearer image.');
                        return;
                    }
                    // Distribute points evenly along the edge pixels (simple sampling)
                    const step = Math.floor(edgePixels.length / numPoints);
                    const newPoints = [];
                    for (let i = 0; i < numPoints; i++) {
                        const idx = i * step;
                        const p = edgePixels[idx % edgePixels.length];
                        // Normalize to [0,1]
                        newPoints.push({ x: p.x / w, y: p.y / h });
                    }
                    points = newPoints;
                    updatePointList();
                    draw();
                });
            }
            <input type="hidden" name="image_existing" id="image_existing" value="<?= htmlspecialchars($activityImage, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="points_json" id="points_json" value="<?= htmlspecialchars(json_encode($activityPoints, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>">

            <p class="d2d-note">Tip: place points across the contour in a continuous path. Minimum: 3 points.</p>

            <div class="d2d-actions">
                <button type="button" class="d2d-btn d2d-btn-soft" id="undoPointBtn">Undo last point</button>
                <button type="button" class="d2d-btn d2d-btn-danger" id="clearPointsBtn">Clear points</button>
                <button type="submit" class="d2d-btn d2d-btn-save">Save activity</button>
            </div>
        </aside>

        <section class="d2d-card">
            <label>Point placement canvas</label>
            <div class="d2d-stage-wrap">
                <div class="d2d-stage" id="d2dStage">
                    <div class="d2d-empty" id="d2dEmptyState">Upload or load an image to place points.</div>
                    <img id="d2dImage" src="<?= htmlspecialchars($activityImage, ENT_QUOTES, 'UTF-8') ?>" alt="dot-to-dot template" style="<?= $activityImage === '' ? 'display:none;' : '' ?>">
                    <canvas id="d2dOverlay" class="d2d-overlay" style="<?= $activityImage === '' ? 'display:none;' : '' ?>"></canvas>
                </div>
            </div>

            <label style="margin-top:10px;">Point list</label>
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
                <button type="button" class="d2d-btn d2d-btn-soft" id="addPointBtn">+ Add point</button>
                <button type="button" class="d2d-btn d2d-btn-soft" id="autoPlaceBtn">Auto-place points on outline</button>
                <span style="font-size:13px;color:#64748b;">(Manual o automático)</span>
            </div>
                const autoPlaceBtn = document.getElementById('autoPlaceBtn');
                // --- Auto-place points on outline ---
                if (autoPlaceBtn) {
                    autoPlaceBtn.addEventListener('click', function () {
                        if (!imageEl.complete || !imageEl.naturalWidth) {
                            alert('Load an image first.');
                            return;
                        }
                        const settings = normalizeSettings();
                        const numPoints = capacity(settings);
                        // Create a temp canvas to process the image
                        const tempCanvas = document.createElement('canvas');
                        const w = imageEl.naturalWidth;
                        const h = imageEl.naturalHeight;
                        tempCanvas.width = w;
                        tempCanvas.height = h;
                        const ctx = tempCanvas.getContext('2d');
                        ctx.drawImage(imageEl, 0, 0, w, h);
                        // Get grayscale
                        const imgData = ctx.getImageData(0, 0, w, h);
                        const gray = new Uint8ClampedArray(w * h);
                        for (let i = 0; i < w * h; i++) {
                            const r = imgData.data[i * 4];
                            const g = imgData.data[i * 4 + 1];
                            const b = imgData.data[i * 4 + 2];
                            gray[i] = 0.299 * r + 0.587 * g + 0.114 * b;
                        }
                        // Simple edge detection (Sobel)
                        const edge = new Uint8ClampedArray(w * h);
                        for (let y = 1; y < h - 1; y++) {
                            for (let x = 1; x < w - 1; x++) {
                                const gx =
                                    -gray[(y - 1) * w + (x - 1)] - 2 * gray[y * w + (x - 1)] - gray[(y + 1) * w + (x - 1)]
                                    + gray[(y - 1) * w + (x + 1)] + 2 * gray[y * w + (x + 1)] + gray[(y + 1) * w + (x + 1)];
                                const gy =
                                    -gray[(y - 1) * w + (x - 1)] - 2 * gray[(y - 1) * w + x] - gray[(y - 1) * w + (x + 1)]
                                    + gray[(y + 1) * w + (x - 1)] + 2 * gray[(y + 1) * w + x] + gray[(y + 1) * w + (x + 1)];
                                edge[y * w + x] = Math.sqrt(gx * gx + gy * gy) > 100 ? 255 : 0;
                            }
                        }
                        // Find edge pixels
                        const edgePixels = [];
                        for (let y = 0; y < h; y++) {
                            for (let x = 0; x < w; x++) {
                                if (edge[y * w + x] === 255) {
                                    edgePixels.push({ x, y });
                                }
                            }
                        }
                        if (edgePixels.length < numPoints) {
                            alert('Could not detect enough outline points. Try a clearer image.');
                            return;
                        }
                        // Distribute points evenly along the edge pixels (simple sampling)
                        const step = Math.floor(edgePixels.length / numPoints);
                        const newPoints = [];
                        for (let i = 0; i < numPoints; i++) {
                            const idx = i * step;
                            const p = edgePixels[idx % edgePixels.length];
                            // Normalize to [0,1]
                            newPoints.push({ x: p.x / w, y: p.y / h });
                        }
                        points = newPoints;
                        updatePointList();
                        draw();
                    });
                }
            <ul class="d2d-list" id="d2dPointList"></ul>
        </section>
    </div>
</form>

<script>
(function () {
    const imageEl = document.getElementById('d2dImage');
    const overlay = document.getElementById('d2dOverlay');
    const stageEl = document.getElementById('d2dStage');
    const emptyEl = document.getElementById('d2dEmptyState');
    const pointsInput = document.getElementById('points_json');
    const pointList = document.getElementById('d2dPointList');
    const fileInput = document.getElementById('main_image');
    const clearBtn = document.getElementById('clearPointsBtn');
    const undoBtn = document.getElementById('undoPointBtn');
    const formEl = document.getElementById('d2dEditorForm');
    const addPointBtn = document.getElementById('addPointBtn');
        // Botón manual para agregar punto centrado
        if (addPointBtn) {
            addPointBtn.addEventListener('click', function () {
                const settings = normalizeSettings();
                const max = capacity(settings);
                if (points.length >= max) {
                    alert('Ya alcanzaste el límite configurado de ' + max + ' puntos. Cambia End/Step o elimina puntos.');
                    return;
                }
                // Por defecto, agrega punto centrado (0.5, 0.5) o el siguiente disponible si ya existe uno ahí
                let x = 0.5, y = 0.5;
                // Si ya existe un punto centrado, busca un lugar cercano libre
                let offset = 0.05;
                while (points.some(p => Math.abs(p.x - x) < 0.01 && Math.abs(p.y - y) < 0.01)) {
                    x = Math.max(0.05, Math.min(0.95, x + offset));
                    y = Math.max(0.05, Math.min(0.95, y + offset));
                    offset = -offset * 1.1; // alterna dirección y aumenta
                }
                points.push({ x: x, y: y });
                updatePointList();
                draw();
            });
        }
    const labelModeEl = document.getElementById('label_mode');
    const startEl = document.getElementById('sequence_start');
    const stepEl = document.getElementById('sequence_step');
    const endEl = document.getElementById('sequence_end');
    const capacityInfoEl = document.getElementById('d2dCapacityInfo');

    let points = [];

    try {
        const parsed = JSON.parse(pointsInput.value || '[]');
        if (Array.isArray(parsed)) {
            points = parsed
                .map(function (p) {
                    return { x: Number(p.x), y: Number(p.y) };
                })
                .filter(function (p) {
                    return Number.isFinite(p.x) && Number.isFinite(p.y) && p.x >= 0 && p.x <= 1 && p.y >= 0 && p.y <= 1;
                });
        }
    } catch (e) {
        points = [];
    }

    function getCtx() {
        return overlay.getContext('2d');
    }

    function normalizeSettings() {
        let start = Number(startEl && startEl.value ? startEl.value : 1);
        let step = Number(stepEl && stepEl.value ? stepEl.value : 1);
        let end = Number(endEl && endEl.value ? endEl.value : 20);
        const mode = labelModeEl && labelModeEl.value ? String(labelModeEl.value) : 'number';

        if (!Number.isFinite(start) || start < 1) start = 1;
        if (!Number.isFinite(step) || step < 1) step = 1;
        if (!Number.isFinite(end) || end < start) end = start;

        if (startEl) startEl.value = String(Math.round(start));
        if (stepEl) stepEl.value = String(Math.round(step));
        if (endEl) endEl.value = String(Math.round(end));

        return {
            mode: mode,
            start: Math.round(start),
            step: Math.round(step),
            end: Math.round(end)
        };
    }

    function capacity(settings) {
        // No longer restricts point count; used only for label preview.
        return Math.floor((settings.end - settings.start) / settings.step) + 1;
    }

    function numberToLetters(value) {
        if (value < 1) return String(value);
        let n = value;
        let letters = '';
        while (n > 0) {
            n -= 1;
            letters = String.fromCharCode(65 + (n % 26)) + letters;
            n = Math.floor(n / 26);
        }
        return letters;
    }

    function numberToWordsEn(value) {
        const ones = ['zero','one','two','three','four','five','six','seven','eight','nine','ten','eleven','twelve','thirteen','fourteen','fifteen','sixteen','seventeen','eighteen','nineteen'];
        const tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];
        if (value < 20) return ones[value] || String(value);
        if (value < 100) {
            const ten = Math.floor(value / 10);
            const rest = value % 10;
            return rest === 0 ? tens[ten] : (tens[ten] + '-' + ones[rest]);
        }
        if (value < 1000) {
            const hundred = Math.floor(value / 100);
            const rest = value % 100;
            return rest === 0 ? (ones[hundred] + ' hundred') : (ones[hundred] + ' hundred ' + numberToWordsEn(rest));
        }
        return String(value);
    }

    function pointValue(index, settings) {
        return settings.start + (index * settings.step);
    }

    function pointLabel(index, settings) {
        const value = pointValue(index, settings);
        if (settings.mode === 'letter') return numberToLetters(value);
        if (settings.mode === 'word') return numberToWordsEn(value);
        return String(value);
    }

    function refreshCapacityHint() {
        const settings = normalizeSettings();
        const max = capacity(settings);
        if (capacityInfoEl) {
            capacityInfoEl.textContent = 'This setup allows up to ' + max + ' points.';
        }
    }

    function syncCanvasSize() {
        const rect = imageEl.getBoundingClientRect();
        if (!rect.width || !rect.height) {
            return;
        }

        overlay.style.width = rect.width + 'px';
        overlay.style.height = rect.height + 'px';
        overlay.style.left = imageEl.offsetLeft + 'px';
        overlay.style.top = imageEl.offsetTop + 'px';

        const dpr = window.devicePixelRatio || 1;
        overlay.width = Math.round(rect.width * dpr);
        overlay.height = Math.round(rect.height * dpr);

        const ctx = getCtx();
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

        draw();
    }

    // --- Drag and drop/select/create separation ---
    let dragIndex = null;
    let dragOffset = { x: 0, y: 0 };
    let isDragging = false;
    let mouseDownOnPoint = false;

    function draw() {
        const rect = overlay.getBoundingClientRect();
        const ctx = getCtx();
        if (!ctx || !rect.width || !rect.height) {
            return;
        }

        ctx.clearRect(0, 0, rect.width, rect.height);

        if (points.length > 1) {
            ctx.strokeStyle = '#2563eb';
            ctx.lineWidth = 3;
            ctx.lineCap = 'round';
            ctx.beginPath();
            points.forEach(function (p, index) {
                const x = p.x * rect.width;
                const y = p.y * rect.height;
                if (index === 0) {
                    ctx.moveTo(x, y);
                } else {
                    ctx.lineTo(x, y);
                }
            });
            ctx.stroke();
        }

        const settings = normalizeSettings();
        points.forEach(function (p, index) {
            const x = p.x * rect.width;
            const y = p.y * rect.height;

            ctx.fillStyle = dragIndex === index ? '#f59e42' : '#1d4ed8';
            ctx.beginPath();
            ctx.arc(x, y, 9, 0, Math.PI * 2);
            ctx.fill();

            ctx.fillStyle = '#111827';
            ctx.font = '700 16px Nunito, sans-serif';
            ctx.fillText(pointLabel(index, settings), x + 10, y - 10);
        });
    }

    function getPointAt(x, y) {
        // x, y in canvas pixel coordinates
        const rect = overlay.getBoundingClientRect();
        for (let i = 0; i < points.length; i++) {
            const px = points[i].x * rect.width;
            const py = points[i].y * rect.height;
            const dist = Math.sqrt((px - x) * (px - x) + (py - y) * (py - y));
            if (dist <= 14) return i;
        }
        return null;
    }

    // --- Drag and drop, selection, and creation separation ---
    // (Eliminado: duplicado)

    overlay.addEventListener('mousedown', function (event) {
        const rect = overlay.getBoundingClientRect();
        const x = (event.clientX - rect.left);
        const y = (event.clientY - rect.top);
        const idx = getPointAt(x, y);
        if (idx !== null) {
            dragIndex = idx;
            dragOffset.x = x - points[idx].x * rect.width;
            dragOffset.y = y - points[idx].y * rect.height;
            overlay.style.cursor = 'grabbing';
            isDragging = false;
            mouseDownOnPoint = true;
            event.stopPropagation();
            event.preventDefault();
        } else {
            dragIndex = null;
            mouseDownOnPoint = false;
        }
    });

    overlay.addEventListener('mousemove', function (event) {
        if (dragIndex === null) return;
        isDragging = true;
        const rect = overlay.getBoundingClientRect();
        let x = (event.clientX - rect.left - dragOffset.x) / rect.width;
        let y = (event.clientY - rect.top - dragOffset.y) / rect.height;
        x = Math.max(0, Math.min(1, x));
        y = Math.max(0, Math.min(1, y));
        points[dragIndex].x = x;
        points[dragIndex].y = y;
        updatePointList();
        draw();
    });

    overlay.addEventListener('mouseup', function (event) {
        if (dragIndex !== null) {
            overlay.style.cursor = '';
            dragIndex = null;
            isDragging = false;
            event.stopPropagation();
            event.preventDefault();
        }
        mouseDownOnPoint = false;
    });

    overlay.addEventListener('click', function (event) {
        // Nunca crear punto por click en canvas, solo seleccionar/mover
        // Si el click fue sobre un punto, solo selecciona
        // Si fue drag, ignora
        event.stopPropagation();
        event.preventDefault();
        return;
    });

    function updatePointList() {
        pointList.innerHTML = '';

        if (!points.length) {
            const li = document.createElement('li');
            li.textContent = 'No points yet.';
            pointList.appendChild(li);
            pointsInput.value = '[]';
            return;
        }

        const settings = normalizeSettings();
        points.forEach(function (p, index) {
            const li = document.createElement('li');
            const xPercent = Math.round(p.x * 1000) / 10;
            const yPercent = Math.round(p.y * 1000) / 10;
            let label = '';
            // Only assign label if within sequence, else blank
            const seqMax = capacity(settings);
            if (index < seqMax) {
                label = pointLabel(index, settings);
            }
            li.innerHTML = '<span>' + label + '</span><span>X ' + xPercent + '% | Y ' + yPercent + '% <button type="button" class="d2d-list-remove" data-remove-index="' + index + '">x</button></span>';
            pointList.appendChild(li);
        });

        pointList.querySelectorAll('[data-remove-index]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const idx = Number(btn.getAttribute('data-remove-index'));
                if (!Number.isFinite(idx)) {
                    return;
                }
                points.splice(idx, 1);
                updatePointList();
                draw();
            });
        });

        pointsInput.value = JSON.stringify(points.map(function (p, index) {
            const seqMax = capacity(settings);
            return {
                x: Number(p.x.toFixed(6)),
                y: Number(p.y.toFixed(6)),
                label: index < seqMax ? pointLabel(index, settings) : ''
            };
        }));
    }

    function ensureCanvasVisibility() {
        const hasImage = !!imageEl.getAttribute('src');
        imageEl.style.display = hasImage ? '' : 'none';
        overlay.style.display = hasImage ? '' : 'none';
        emptyEl.style.display = hasImage ? 'none' : '';
    }

    function addPointNormalized(x, y) {
        if (x < 0 || x > 1 || y < 0 || y > 1) {
            return;
        }
        points.push({ x: x, y: y });
        updatePointList();
        draw();
    }

    overlay.addEventListener('click', function (event) {
        event.stopPropagation();
        const rect = overlay.getBoundingClientRect();
        if (!rect.width || !rect.height) {
            return;
        }

        const x = (event.clientX - rect.left) / rect.width;
        const y = (event.clientY - rect.top) / rect.height;

        if (x < 0 || x > 1 || y < 0 || y > 1) {
            return;
        }

        addPointNormalized(x, y);
    });

    stageEl.addEventListener('click', function (event) {
        if (!imageEl.getAttribute('src')) {
            return;
        }

        // Fallback: if overlay sizing fails in some browsers, allow clicks on stage using image bounds.
        const rect = imageEl.getBoundingClientRect();
        if (!rect.width || !rect.height) {
            return;
        }

        const x = (event.clientX - rect.left) / rect.width;
        const y = (event.clientY - rect.top) / rect.height;

        addPointNormalized(x, y);
    });

    undoBtn.addEventListener('click', function () {
        if (!points.length) {
            return;
        }
        points.pop();
        updatePointList();
        draw();
    });

    clearBtn.addEventListener('click', function () {
        points = [];
        updatePointList();
        draw();
    });

    fileInput.addEventListener('change', function (event) {
        const file = event.target.files && event.target.files[0] ? event.target.files[0] : null;
        if (!file) {
            return;
        }

        const reader = new FileReader();
        reader.onload = function (e) {
            const result = e.target && e.target.result ? String(e.target.result) : '';
            if (!result) {
                return;
            }
            imageEl.src = result;
            points = [];
            updatePointList();
            ensureCanvasVisibility();
            imageEl.onload = syncCanvasSize;
        };
        reader.readAsDataURL(file);
    });

    formEl.addEventListener('submit', function (event) {
        // Ensure hidden field is always up-to-date before server validation.
        updatePointList();

        if (!imageEl.getAttribute('src')) {
            event.preventDefault();
            alert('Upload a final colored image before saving.');
            return;
        }

        if (points.length < 3) {
            event.preventDefault();
            alert('Add at least 3 points to create a playable dot-to-dot.');
        }
    });

    [labelModeEl, startEl, stepEl, endEl].forEach(function (el) {
        if (!el) {
            return;
        }
        el.addEventListener('change', function () {
            refreshCapacityHint();
            updatePointList();
            draw();
        });
        el.addEventListener('input', function () {
            refreshCapacityHint();
            updatePointList();
            draw();
        });
    });

    window.addEventListener('resize', syncCanvasSize);

    ensureCanvasVisibility();
    refreshCapacityHint();
    updatePointList();

    if (imageEl.getAttribute('src')) {
        if (imageEl.complete) {
            syncCanvasSize();
            setTimeout(syncCanvasSize, 120);
        } else {
            imageEl.onload = syncCanvasSize;
        }
    }
})();
</script>

<?php
$content = ob_get_clean();
render_activity_editor('Dot to Dot Editor', 'fas fa-pencil-ruler', $content);
