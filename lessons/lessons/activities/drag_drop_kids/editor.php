<?php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_editor_template.php';
require_once __DIR__ . '/../../core/cloudinary_upload.php';

if (isset($_SESSION['student_logged']) && $_SESSION['student_logged']) {
    header('Location: /lessons/lessons/academic/student_dashboard.php?error=access_denied');
    exit;
}
$isLoggedIn = !empty($_SESSION['academic_logged']) || !empty($_SESSION['admin_logged']);
if (!$isLoggedIn) {
    header('Location: /lessons/lessons/academic/login.php');
    exit;
}

$activityId = isset($_GET['id'])         ? trim((string) $_GET['id'])         : '';
$unit       = isset($_GET['unit'])       ? trim((string) $_GET['unit'])       : '';
$source     = isset($_GET['source'])     ? trim((string) $_GET['source'])     : '';
$assignment = isset($_GET['assignment']) ? trim((string) $_GET['assignment']) : '';

if ($unit === '' && $activityId !== '') {
    $stmt = $pdo->prepare('SELECT unit_id FROM activities WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $activityId]);
    $row  = $stmt->fetch(PDO::FETCH_ASSOC);
    $unit = $row ? (string)($row['unit_id'] ?? '') : '';
}
if ($unit === '') die('Unit not specified');

$error = '';
$saved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title        = trim($_POST['activity_title']        ?? '');
    $instructions = trim($_POST['activity_instructions'] ?? '');
    $bgImage      = trim($_POST['bg_image_existing']     ?? '');
    $pairsJson    = $_POST['pairs_json'] ?? '[]';

    if (!empty($_FILES['bg_image']['tmp_name'])) {
        $uploaded = upload_to_cloudinary($_FILES['bg_image']['tmp_name']);
        if ($uploaded) $bgImage = $uploaded;
    }

    $pairs = json_decode($pairsJson, true);
    if (!is_array($pairs)) $pairs = [];

    $cleanPairs = [];
    foreach ($pairs as $p) {
        if (!is_array($p)) continue;
        $id    = (int)($p['id'] ?? 0);
        $label = trim((string)($p['label'] ?? ''));
        if ($id <= 0 || $label === '') continue;
        $cleanPairs[] = [
            'id'    => $id,
            'label' => $label,
            'x'     => max(0, min(90, round((float)($p['x'] ?? 10), 4))),
            'y'     => max(0, min(90, round((float)($p['y'] ?? 10), 4))),
            'w'     => max(4, min(50, round((float)($p['w'] ?? 12), 4))),
            'h'     => max(4, min(50, round((float)($p['h'] ?? 8),  4))),
        ];
    }

    if ($bgImage === '') {
        $error = 'Please upload a background image.';
    } elseif (count($cleanPairs) < 2) {
        $error = 'Add at least 2 items (click on the image to place zones).';
    } else {
        $payload = json_encode([
            'title'            => $title !== '' ? $title : 'Drag & Drop',
            'instructions'     => $instructions,
            'background_image' => $bgImage,
            'pairs'            => $cleanPairs,
        ], JSON_UNESCAPED_UNICODE);

        if ($activityId !== '') {
            $stmt = $pdo->prepare("UPDATE activities SET data = :data WHERE id = :id AND type = 'drag_drop_kids'");
            $stmt->execute(['data' => $payload, 'id' => $activityId]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO activities (unit_id, type, data) VALUES (:unit, 'drag_drop_kids', :data)");
            $stmt->execute(['unit' => $unit, 'data' => $payload]);
            $activityId = (string) $pdo->lastInsertId();
        }
        $saved = true;
    }
}

/* Load existing data */
$activity = ['title' => '', 'instructions' => '', 'background_image' => '', 'pairs' => []];
if ($activityId !== '') {
    $stmt = $pdo->prepare("SELECT data FROM activities WHERE id = :id AND type = 'drag_drop_kids' LIMIT 1");
    $stmt->execute(['id' => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['data'])) {
        $d = json_decode($row['data'], true);
        if (is_array($d)) $activity = array_merge($activity, $d);
    }
}

ob_start();
?>
<style>
/* ── editor layout ─────────────────────────────── */
.ddk-ed-grid {
    display: grid;
    grid-template-columns: 280px 1fr;
    gap: 18px;
    align-items: start;
}
@media (max-width: 800px) {
    .ddk-ed-grid { grid-template-columns: 1fr; }
}

/* Side panel */
.ddk-side {
    display: flex;
    flex-direction: column;
    gap: 14px;
}
.ddk-card {
    background: #fff;
    border: 1px solid #dbeafe;
    border-radius: 16px;
    padding: 16px;
    box-shadow: 0 4px 14px rgba(15,23,42,.06);
}
.ddk-card label {
    display: block;
    font-size: 12px;
    font-weight: 800;
    color: #0f766e;
    margin: 0 0 5px;
    text-transform: uppercase;
    letter-spacing: .04em;
}
.ddk-card input[type="text"],
.ddk-card textarea {
    width: 100%;
    border: 1px solid #93c5fd;
    border-radius: 10px;
    padding: 9px 12px;
    background: #fff;
    box-sizing: border-box;
    font-size: 14px;
}
.ddk-card textarea { resize: vertical; min-height: 72px; }
.ddk-card input[type="file"] {
    width: 100%;
    font-size: 13px;
    margin-top: 4px;
}

/* Labels list */
.ddk-label-list { list-style: none; margin: 8px 0 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }
.ddk-label-item {
    display: flex;
    align-items: center;
    gap: 6px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 6px 8px;
}
.ddk-label-num {
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: #3b82f6;
    color: #fff;
    font-size: 11px;
    font-weight: 800;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.ddk-label-input {
    flex: 1;
    border: none;
    background: transparent;
    font-size: 13px;
    font-weight: 600;
    color: #1f2937;
    outline: none;
    padding: 0;
}
.ddk-label-input::placeholder { color: #9ca3af; font-weight: 400; }
.ddk-del-btn {
    background: none;
    border: none;
    cursor: pointer;
    color: #ef4444;
    font-size: 16px;
    line-height: 1;
    padding: 2px 4px;
    flex-shrink: 0;
}
.ddk-del-btn:hover { color: #b91c1c; }
.ddk-hint {
    font-size: 12px;
    color: #64748b;
    margin: 8px 0 0;
    line-height: 1.5;
}

/* Canvas */
.ddk-canvas-card {
    background: #fff;
    border: 1px solid #dbeafe;
    border-radius: 16px;
    padding: 14px;
    box-shadow: 0 4px 14px rgba(15,23,42,.06);
}
.ddk-canvas-title {
    font-size: 12px;
    font-weight: 800;
    color: #0f766e;
    text-transform: uppercase;
    letter-spacing: .04em;
    margin: 0 0 10px;
}
.ddk-canvas-wrap {
    position: relative;
    border: 2px dashed #93c5fd;
    border-radius: 14px;
    background: #f8fafc;
    overflow: hidden;
    min-height: 220px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.ddk-canvas-wrap.has-image { border-style: solid; border-color: #cbd5e1; }
#ddkEdBg {
    display: block;
    max-width: 100%;
    height: auto;
    border-radius: 12px;
    pointer-events: none;
    user-select: none;
}
.ddk-canvas-overlay {
    position: absolute;
    inset: 0;
    cursor: crosshair;
    z-index: 5;
}
.ddk-ed-empty {
    text-align: center;
    color: #64748b;
    font-size: 14px;
    font-weight: 600;
    padding: 40px 20px;
    pointer-events: none;
}

/* Editor zones */
.ddk-ed-zone {
    position: absolute;
    border: 2px solid #3b82f6;
    border-radius: 8px;
    background: rgba(59,130,246,.15);
    box-sizing: border-box;
    z-index: 10;
    cursor: move;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: visible;
}
.ddk-ed-zone.selected {
    border-color: #f97316;
    background: rgba(249,115,22,.18);
}
.ddk-ed-num {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: #3b82f6;
    color: #fff;
    font-size: 11px;
    font-weight: 800;
    display: flex;
    align-items: center;
    justify-content: center;
    position: absolute;
    top: -10px;
    left: -10px;
    z-index: 20;
    pointer-events: none;
    flex-shrink: 0;
}
.ddk-ed-zone.selected .ddk-ed-num { background: #f97316; }
.ddk-ed-resize {
    position: absolute;
    bottom: -5px;
    right: -5px;
    width: 12px;
    height: 12px;
    background: #3b82f6;
    border-radius: 3px;
    cursor: se-resize;
    z-index: 20;
}
.ddk-ed-zone.selected .ddk-ed-resize { background: #f97316; }

/* Buttons */
.ddk-save-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 12px 28px;
    border: none;
    border-radius: 12px;
    background: linear-gradient(180deg, #22c55e 0%, #16a34a 100%);
    color: #fff;
    font-weight: 800;
    font-size: 15px;
    cursor: pointer;
    box-shadow: 0 8px 20px rgba(22,163,74,.3);
    transition: transform .15s, filter .15s;
    width: 100%;
    margin-top: 6px;
}
.ddk-save-btn:hover { filter: brightness(1.06); transform: translateY(-1px); }
.ddk-preview-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 10px 20px;
    border: none;
    border-radius: 12px;
    background: linear-gradient(180deg, #60a5fa 0%, #3b82f6 100%);
    color: #fff;
    font-weight: 700;
    font-size: 14px;
    text-decoration: none;
    cursor: pointer;
    width: 100%;
    margin-top: 6px;
    box-shadow: 0 6px 16px rgba(59,130,246,.3);
    transition: transform .15s, filter .15s;
    box-sizing: border-box;
    text-align: center;
}
.ddk-preview-link:hover { filter: brightness(1.06); transform: translateY(-1px); color: #fff; }

.ddk-alert {
    padding: 12px 16px;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 14px;
}
.ddk-alert-error   { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
.ddk-alert-success { background: #dcfce7; color: #14532d; border: 1px solid #86efac; }
</style>

<?php if ($error !== ''): ?>
<div class="ddk-alert ddk-alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php if ($saved): ?>
<div class="ddk-alert ddk-alert-success">Activity saved successfully!</div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" id="ddkForm">
    <input type="hidden" name="pairs_json"        id="pairsJsonInput" value="">
    <input type="hidden" name="bg_image_existing" id="bgImageExisting"
           value="<?= htmlspecialchars($activity['background_image'], ENT_QUOTES, 'UTF-8') ?>">

    <div class="ddk-ed-grid">

        <!-- ── Side panel ──────────────────────── -->
        <div class="ddk-side">

            <!-- Title & instructions -->
            <div class="ddk-card">
                <label>Title</label>
                <input type="text" name="activity_title"
                       value="<?= htmlspecialchars($activity['title'], ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="e.g. Body Parts">
                <label style="margin-top:10px">Instructions (optional)</label>
                <textarea name="activity_instructions"
                          placeholder="Drag each word to the correct place."><?= htmlspecialchars($activity['instructions'], ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>

            <!-- Background image -->
            <div class="ddk-card">
                <label>Background Image</label>
                <input type="file" name="bg_image" accept="image/*" id="bgImageFile">
                <?php if ($activity['background_image'] !== ''): ?>
                <p style="font-size:12px;color:#16a34a;margin:6px 0 0">✔ Image uploaded</p>
                <?php endif; ?>
                <p class="ddk-hint">Upload an image (body, map, scene…). Then click on it to place labeled zones.</p>
            </div>

            <!-- Labels list -->
            <div class="ddk-card">
                <label>Labels</label>
                <p class="ddk-hint" style="margin:0 0 8px">
                    Click on the image (right) to add a zone. Type the label for each numbered zone here.
                </p>
                <ul class="ddk-label-list" id="labelList"></ul>
                <p class="ddk-hint" id="noZonesHint">No zones yet — click the image to add one.</p>
            </div>

            <!-- Save -->
            <div class="ddk-card" style="gap:8px">
                <button type="submit" class="ddk-save-btn">Save Activity</button>
                <?php if ($activityId !== ''): ?>
                <a href="viewer.php?id=<?= urlencode($activityId) ?>&unit=<?= urlencode($unit) ?>"
                   target="_blank" class="ddk-preview-link">Preview</a>
                <?php endif; ?>
            </div>

        </div><!-- /side -->

        <!-- ── Canvas panel ────────────────────── -->
        <div class="ddk-canvas-card">
            <p class="ddk-canvas-title">Image Canvas — click to place a zone, drag to move, drag corner to resize</p>
            <div class="ddk-canvas-wrap" id="ddkCanvasWrap">
                <div class="ddk-ed-empty" id="ddkEmptyHint">Upload an image to get started</div>
                <img id="ddkEdBg" src="" alt="" style="display:none">
                <div class="ddk-canvas-overlay" id="ddkOverlay"></div>
                <!-- zones injected here by JS -->
            </div>
        </div>

    </div><!-- /grid -->
</form>

<script>
/* ── State ─────────────────────────────────────── */
const INIT_PAIRS = <?= json_encode(array_values($activity['pairs']), JSON_UNESCAPED_UNICODE) ?>;
let pairs    = INIT_PAIRS.length ? JSON.parse(JSON.stringify(INIT_PAIRS)) : [];
let nextId   = pairs.length ? Math.max(...pairs.map(p => p.id)) + 1 : 1;

const wrap       = document.getElementById('ddkCanvasWrap');
const overlay    = document.getElementById('ddkOverlay');
const bgImg      = document.getElementById('ddkEdBg');
const emptyHint  = document.getElementById('ddkEmptyHint');
const labelList  = document.getElementById('labelList');
const noZonesHint= document.getElementById('noZonesHint');
const pairsInput = document.getElementById('pairsJsonInput');
const bgExisting = document.getElementById('bgImageExisting');
const bgFile     = document.getElementById('bgImageFile');

/* ── Image preview ─────────────────────────────── */
let bgLoaded = <?= json_encode($activity['background_image'] !== '') ?>;

function loadBgPreview(src) {
    if (!src) return;
    bgImg.src = src;
    bgImg.style.display = 'block';
    emptyHint.style.display = 'none';
    wrap.classList.add('has-image');
    bgLoaded = true;
    renderZones();
}

bgFile.addEventListener('change', function () {
    const file = this.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function (e) {
        bgExisting.value = '';
        loadBgPreview(e.target.result);
    };
    reader.readAsDataURL(file);
});

if (<?= json_encode($activity['background_image']) ?>) {
    loadBgPreview(<?= json_encode($activity['background_image']) ?>);
}

/* ── Zone rendering ────────────────────────────── */
function renderZones() {
    document.querySelectorAll('.ddk-ed-zone').forEach(z => z.remove());
    pairs.forEach(function (p) {
        createZoneEl(p);
    });
    renderLabels();
}

function createZoneEl(p) {
    const el = document.createElement('div');
    el.className   = 'ddk-ed-zone';
    el.id          = 'edzone-' + p.id;
    el.dataset.id  = p.id;
    el.style.left  = p.x + '%';
    el.style.top   = p.y + '%';
    el.style.width = p.w + '%';
    el.style.height= p.h + '%';

    const num = document.createElement('div');
    num.className   = 'ddk-ed-num';
    num.textContent = p.id;
    el.appendChild(num);

    const handle = document.createElement('div');
    handle.className = 'ddk-ed-resize';
    handle.dataset.resize = 'true';
    el.appendChild(handle);

    wrap.appendChild(el);
    makeDraggable(el, p);
    makeResizable(handle, el, p);
}

function renderLabels() {
    labelList.innerHTML = '';
    noZonesHint.style.display = pairs.length ? 'none' : 'block';

    pairs.forEach(function (p) {
        const li = document.createElement('li');
        li.className  = 'ddk-label-item';
        li.dataset.id = p.id;

        const num = document.createElement('div');
        num.className   = 'ddk-label-num';
        num.textContent = p.id;

        const inp = document.createElement('input');
        inp.type        = 'text';
        inp.className   = 'ddk-label-input';
        inp.placeholder = 'Label ' + p.id;
        inp.value       = p.label || '';
        inp.addEventListener('input', function () {
            const pair = pairs.find(pp => pp.id === p.id);
            if (pair) pair.label = this.value;
        });

        const del = document.createElement('button');
        del.type        = 'button';
        del.className   = 'ddk-del-btn';
        del.textContent = '×';
        del.addEventListener('click', function () {
            removePair(p.id);
        });

        li.appendChild(num);
        li.appendChild(inp);
        li.appendChild(del);
        labelList.appendChild(li);
    });
}

/* ── Add zone on canvas click ──────────────────── */
overlay.addEventListener('click', function (e) {
    if (!bgLoaded) return;
    if (e.target.dataset.resize) return;
    if (e.target.classList.contains('ddk-ed-zone')) return;

    const rect = wrap.getBoundingClientRect();
    const imgW  = bgImg.clientWidth  || rect.width;
    const imgH  = bgImg.clientHeight || rect.height;

    const xPct = ((e.clientX - rect.left) / imgW * 100);
    const yPct = ((e.clientY - rect.top)  / imgH * 100);

    const w = 14, h = 9;
    const clampedX = Math.min(xPct - w / 2, 100 - w);
    const clampedY = Math.min(yPct - h / 2, 100 - h);

    const p = {
        id:    nextId++,
        label: '',
        x:     Math.max(0, parseFloat(clampedX.toFixed(4))),
        y:     Math.max(0, parseFloat(clampedY.toFixed(4))),
        w:     w,
        h:     h,
    };
    pairs.push(p);
    createZoneEl(p);
    renderLabels();

    /* Focus the new label input */
    const li = labelList.querySelector('[data-id="' + p.id + '"]');
    if (li) {
        const inp = li.querySelector('input');
        if (inp) setTimeout(() => inp.focus(), 50);
    }
});

/* ── Drag to reposition ────────────────────────── */
function makeDraggable(el, p) {
    let startMouseX, startMouseY, startX, startY, dragging = false;

    el.addEventListener('mousedown', function (e) {
        if (e.target.dataset.resize) return;
        e.preventDefault();
        dragging = true;
        startMouseX = e.clientX;
        startMouseY = e.clientY;
        startX = p.x;
        startY = p.y;
        el.classList.add('selected');
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
    });

    function onMove(e) {
        if (!dragging) return;
        const rect = wrap.getBoundingClientRect();
        const imgW  = bgImg.clientWidth  || rect.width;
        const imgH  = bgImg.clientHeight || rect.height;
        const dx = (e.clientX - startMouseX) / imgW * 100;
        const dy = (e.clientY - startMouseY) / imgH * 100;
        p.x = Math.max(0, Math.min(100 - p.w, parseFloat((startX + dx).toFixed(4))));
        p.y = Math.max(0, Math.min(100 - p.h, parseFloat((startY + dy).toFixed(4))));
        el.style.left = p.x + '%';
        el.style.top  = p.y + '%';
    }

    function onUp() {
        dragging = false;
        el.classList.remove('selected');
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);
    }
}

/* ── Resize handle ─────────────────────────────── */
function makeResizable(handle, el, p) {
    handle.addEventListener('mousedown', function (e) {
        e.preventDefault();
        e.stopPropagation();
        const rect = wrap.getBoundingClientRect();
        const imgW  = bgImg.clientWidth  || rect.width;
        const imgH  = bgImg.clientHeight || rect.height;
        const startMouseX = e.clientX;
        const startMouseY = e.clientY;
        const startW = p.w, startH = p.h;

        function onMove(e) {
            const dx = (e.clientX - startMouseX) / imgW * 100;
            const dy = (e.clientY - startMouseY) / imgH * 100;
            p.w = Math.max(4, Math.min(60, parseFloat((startW + dx).toFixed(4))));
            p.h = Math.max(4, Math.min(60, parseFloat((startH + dy).toFixed(4))));
            el.style.width  = p.w + '%';
            el.style.height = p.h + '%';
        }

        function onUp() {
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);
        }

        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
    });
}

/* ── Remove pair ───────────────────────────────── */
function removePair(id) {
    pairs = pairs.filter(p => p.id !== id);
    const el = document.getElementById('edzone-' + id);
    if (el) el.remove();
    renderLabels();
}

/* ── Serialize before submit ───────────────────── */
document.getElementById('ddkForm').addEventListener('submit', function () {
    pairsInput.value = JSON.stringify(pairs);
});

/* ── Boot ──────────────────────────────────────── */
renderLabels();
</script>
<?php
$content = ob_get_clean();
render_activity_editor('Drag & Drop Kids — Editor', '🖼️', $content);
