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
        foreach ($decodedPoints as $index => $point) {
            if (!is_array($point)) {
                continue;
            }

            $x = isset($point['x']) ? (float) $point['x'] : -1;
            $y = isset($point['y']) ? (float) $point['y'] : -1;

            if ($x < 0 || $x > 1 || $y < 0 || $y > 1) {
                continue;
            }

            $points[] = array(
                'x' => round($x, 6),
                'y' => round($y, 6),
                'label' => (int) $index + 1,
            );
        }
    }

    if ($image === '') {
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
            $points
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
    $activity['points'] = $points;
}

$activityTitle = isset($activity['title']) ? (string) $activity['title'] : default_dot_to_dot_title();
$activityInstruction = isset($activity['instruction'])
    ? (string) $activity['instruction']
    : 'Connect the dots in order to reveal the picture.';
$activityImage = isset($activity['image']) ? (string) $activity['image'] : '';
$activityPoints = isset($activity['points']) && is_array($activity['points']) ? $activity['points'] : array();

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
.d2d-card textarea{resize:vertical;min-height:92px}
.d2d-stage-wrap{background:linear-gradient(180deg,#ffffff 0%,#f8fafc 100%);border:1px dashed #93c5fd;border-radius:14px;padding:12px}
.d2d-stage{position:relative;border:2px solid #cbd5e1;border-radius:14px;background:#fff;display:flex;justify-content:center;align-items:center;min-height:280px;overflow:hidden}
.d2d-stage img{display:block;max-width:100%;height:auto}
.d2d-overlay{position:absolute;inset:0;width:100%;height:100%;cursor:crosshair}
.d2d-empty{font-weight:800;color:#64748b;padding:24px;text-align:center}
.d2d-list{margin:0;padding:0;list-style:none;display:grid;gap:8px;max-height:340px;overflow:auto}
.d2d-list li{display:flex;justify-content:space-between;align-items:center;padding:8px 10px;border:1px solid #dbeafe;border-radius:10px;background:#f8fafc;font-weight:700;color:#1e3a8a}
.d2d-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}
.d2d-btn{border:none;border-radius:999px;padding:10px 14px;font-weight:800;cursor:pointer}
.d2d-btn-add{background:linear-gradient(180deg,#22c55e,#15803d);color:#fff}
.d2d-btn-soft{background:#e0f2fe;color:#075985;border:1px solid #7dd3fc}
.d2d-btn-danger{background:linear-gradient(180deg,#f43f5e,#be123c);color:#fff}
.d2d-btn-save{background:linear-gradient(180deg,#0ea5e9,#0369a1);color:#fff}
.d2d-note{margin-top:8px;font-size:12px;color:#64748b;font-weight:700}
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

            <label for="main_image" style="margin-top:10px;">Final colored image</label>
            <input id="main_image" name="main_image" type="file" accept="image/*">
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

    let points = [];

    try {
        const parsed = JSON.parse(pointsInput.value || '[]');
        if (Array.isArray(parsed)) {
            points = parsed
                .map(function (p) {
                    return {
                        x: Number(p.x),
                        y: Number(p.y)
                    };
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

        points.forEach(function (p, index) {
            const x = p.x * rect.width;
            const y = p.y * rect.height;

            ctx.fillStyle = '#1d4ed8';
            ctx.beginPath();
            ctx.arc(x, y, 7, 0, Math.PI * 2);
            ctx.fill();

            ctx.fillStyle = '#111827';
            ctx.font = '700 16px Nunito, sans-serif';
            ctx.fillText(String(index + 1), x + 10, y - 10);
        });
    }

    function updatePointList() {
        pointList.innerHTML = '';

        if (!points.length) {
            const li = document.createElement('li');
            li.textContent = 'No points yet.';
            pointList.appendChild(li);
            pointsInput.value = '[]';
            return;
        }

        points.forEach(function (p, index) {
            const li = document.createElement('li');
            const xPercent = Math.round(p.x * 1000) / 10;
            const yPercent = Math.round(p.y * 1000) / 10;
            li.innerHTML = '<span>#' + (index + 1) + '</span><span>X ' + xPercent + '% | Y ' + yPercent + '%</span>';
            pointList.appendChild(li);
        });

        pointsInput.value = JSON.stringify(points.map(function (p, index) {
            return {
                x: Number(p.x.toFixed(6)),
                y: Number(p.y.toFixed(6)),
                label: index + 1
            };
        }));
    }

    function ensureCanvasVisibility() {
        const hasImage = !!imageEl.getAttribute('src');
        imageEl.style.display = hasImage ? '' : 'none';
        overlay.style.display = hasImage ? '' : 'none';
        emptyEl.style.display = hasImage ? 'none' : '';
    }

    overlay.addEventListener('click', function (event) {
        const rect = overlay.getBoundingClientRect();
        if (!rect.width || !rect.height) {
            return;
        }

        const x = (event.clientX - rect.left) / rect.width;
        const y = (event.clientY - rect.top) / rect.height;

        if (x < 0 || x > 1 || y < 0 || y > 1) {
            return;
        }

        points.push({ x: x, y: y });
        updatePointList();
        draw();
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

    window.addEventListener('resize', syncCanvasSize);

    ensureCanvasVisibility();
    updatePointList();

    if (imageEl.getAttribute('src')) {
        if (imageEl.complete) {
            syncCanvasSize();
        } else {
            imageEl.onload = syncCanvasSize;
        }
    }
})();
</script>

<?php
$content = ob_get_clean();
render_activity_editor('Dot to Dot Editor', 'fas fa-pencil-ruler', $content);
