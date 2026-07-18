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

function ddpe_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

$error = '';
$saved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title        = trim($_POST['activity_title']        ?? '');
    $instructions = trim($_POST['activity_instructions'] ?? '');
    $bgImage      = trim($_POST['bg_image_existing']     ?? '');
    $itemsJson    = $_POST['items_json'] ?? '[]';

    /* Upload new background if provided */
    if (!empty($_FILES['bg_image']['tmp_name'])) {
        $uploaded = upload_to_cloudinary($_FILES['bg_image']['tmp_name']);
        if ($uploaded) $bgImage = $uploaded;
    }

    $rawItems = json_decode($itemsJson, true);
    if (!is_array($rawItems)) $rawItems = [];

    $cleanItems = [];
    foreach ($rawItems as $it) {
        if (!is_array($it)) continue;
        $id      = (int)($it['id'] ?? 0);
        $pic_url = trim((string)($it['pic_url'] ?? ''));
        if ($id <= 0) continue;

        /* Upload new picture for this item if a file was submitted */
        $fileKey = 'pic_new';
        if (
            isset($_FILES[$fileKey]['tmp_name'][$id])
            && is_string($_FILES[$fileKey]['tmp_name'][$id])
            && $_FILES[$fileKey]['tmp_name'][$id] !== ''
            && is_uploaded_file($_FILES[$fileKey]['tmp_name'][$id])
        ) {
            $newUrl = upload_to_cloudinary($_FILES[$fileKey]['tmp_name'][$id]);
            if ($newUrl) $pic_url = $newUrl;
        }

        if ($pic_url === '') continue; /* skip items with no picture */

        $cleanItems[] = [
            'id'      => $id,
            'pic_url' => $pic_url,
            'label'   => trim((string)($it['label'] ?? '')),
            'x'       => max(0, min(90, round((float)($it['x'] ?? 10), 4))),
            'y'       => max(0, min(90, round((float)($it['y'] ?? 10), 4))),
            'w'       => max(4, min(50, round((float)($it['w'] ?? 12), 4))),
            'h'       => max(4, min(50, round((float)($it['h'] ?? 15), 4))),
        ];
    }

    if ($bgImage === '') {
        $error = 'Please upload a background image.';
    } elseif (count($cleanItems) < 1) {
        $error = 'Add at least one picture zone and upload a picture for it.';
    } else {
        $payload = json_encode([
            'title'            => $title !== '' ? $title : 'Drag & Drop Picture',
            'instructions'     => $instructions,
            'background_image' => $bgImage,
            'items'            => $cleanItems,
        ], JSON_UNESCAPED_UNICODE);

        if ($activityId !== '') {
            $stmt = $pdo->prepare("UPDATE activities SET data = :data WHERE id = :id AND type = 'dragdrop_pic'");
            $stmt->execute(['data' => $payload, 'id' => $activityId]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO activities (unit_id, type, data) VALUES (:unit, 'dragdrop_pic', :data)");
            $stmt->execute(['unit' => $unit, 'data' => $payload]);
            $activityId = (string) $pdo->lastInsertId();
        }
        $saved = true;
    }
}

/* Load existing data */
$activity = ['title' => '', 'instructions' => '', 'background_image' => '', 'items' => []];
if ($activityId !== '') {
    $stmt = $pdo->prepare("SELECT data FROM activities WHERE id = :id AND type = 'dragdrop_pic' LIMIT 1");
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
/* ── Grid layout ─────────────────────── */
.ddpe-grid {
    display: grid;
    grid-template-columns: 300px 1fr;
    gap: 18px;
    align-items: start;
}
@media (max-width: 820px) {
    .ddpe-grid { grid-template-columns: 1fr; }
}

/* Side panel */
.ddpe-side { display:flex; flex-direction:column; gap:14px; }
.ddpe-card {
    background:#fff;
    border:1px solid #dbeafe;
    border-radius:16px;
    padding:16px;
    box-shadow:0 4px 14px rgba(15,23,42,.06);
}
.ddpe-card label {
    display:block;
    font-size:12px;
    font-weight:800;
    color:#0f766e;
    margin:0 0 5px;
    text-transform:uppercase;
    letter-spacing:.04em;
}
.ddpe-card input[type="text"],
.ddpe-card textarea {
    width:100%;
    border:1px solid #93c5fd;
    border-radius:10px;
    padding:9px 12px;
    background:#fff;
    box-sizing:border-box;
    font-size:14px;
}
.ddpe-card textarea { resize:vertical; min-height:72px; }
.ddpe-card input[type="file"] { width:100%; font-size:13px; margin-top:4px; }

/* Items list */
.ddpe-item-list { list-style:none; margin:8px 0 0; padding:0; display:flex; flex-direction:column; gap:10px; }
.ddpe-item {
    display:flex;
    align-items:center;
    gap:8px;
    background:#f8fafc;
    border:1px solid #e2e8f0;
    border-radius:12px;
    padding:8px 10px;
}
.ddpe-item-num {
    width:22px; height:22px; border-radius:50%;
    background:#7F77DD; color:#fff; font-size:11px; font-weight:800;
    display:flex; align-items:center; justify-content:center; flex-shrink:0;
}
.ddpe-item-thumb {
    width:44px; height:44px; border-radius:6px;
    object-fit:cover; border:1px solid #e2e8f0; flex-shrink:0;
    background:#f1f5f9;
}
.ddpe-item-thumb.placeholder {
    display:flex; align-items:center; justify-content:center;
    font-size:20px; color:#cbd5e1;
}
.ddpe-item-body { flex:1; display:flex; flex-direction:column; gap:4px; min-width:0; }
.ddpe-item-label-input {
    border:none; background:transparent;
    font-size:13px; font-weight:600; color:#1f2937; outline:none; padding:0;
    width:100%;
}
.ddpe-item-label-input::placeholder { color:#9ca3af; font-weight:400; }
.ddpe-item-file-input { font-size:11px; width:100%; }
.ddpe-del-btn {
    background:none; border:none; cursor:pointer;
    color:#ef4444; font-size:18px; line-height:1; padding:2px 4px; flex-shrink:0;
}
.ddpe-del-btn:hover { color:#b91c1c; }
.ddpe-hint {
    font-size:12px; color:#64748b;
    margin:8px 0 0; line-height:1.5;
}
#noItemsHint { font-size:12px; color:#64748b; margin:6px 0 0; }

/* Canvas panel */
.ddpe-canvas-card {
    background:#fff;
    border:1px solid #dbeafe;
    border-radius:16px;
    padding:14px;
    box-shadow:0 4px 14px rgba(15,23,42,.06);
}
.ddpe-canvas-title {
    font-size:12px; font-weight:800; color:#0f766e;
    text-transform:uppercase; letter-spacing:.04em; margin:0 0 10px;
}
.ddpe-canvas-wrap {
    position:relative;
    border:2px dashed #93c5fd;
    border-radius:14px;
    background:#f8fafc;
    overflow:hidden;
    min-height:220px;
    display:flex;
    align-items:center;
    justify-content:center;
}
.ddpe-canvas-wrap.has-image { border-style:solid; border-color:#cbd5e1; }
#ddpeEdBg {
    display:block; max-width:100%; height:auto;
    border-radius:12px; pointer-events:none; user-select:none;
}
.ddpe-overlay {
    position:absolute; inset:0; cursor:crosshair; z-index:5;
}
.ddpe-empty {
    text-align:center; color:#64748b; font-size:14px;
    font-weight:600; padding:40px 20px; pointer-events:none;
}

/* Editor zones */
.ddpe-ed-zone {
    position:absolute;
    border:2px solid #7F77DD;
    border-radius:8px;
    background:rgba(127,119,221,.12);
    box-sizing:border-box;
    z-index:10;
    cursor:move;
    overflow:hidden;
    display:flex;
    align-items:center;
    justify-content:center;
}
.ddpe-ed-zone.selected { border-color:#F97316; background:rgba(249,115,22,.15); }
.ddpe-ed-num {
    position:absolute; top:-10px; left:-10px; z-index:20;
    width:20px; height:20px; border-radius:50%;
    background:#7F77DD; color:#fff; font-size:11px; font-weight:800;
    display:flex; align-items:center; justify-content:center;
    pointer-events:none; flex-shrink:0;
}
.ddpe-ed-zone.selected .ddpe-ed-num { background:#F97316; }
.ddpe-ed-resize {
    position:absolute; bottom:-5px; right:-5px;
    width:12px; height:12px; background:#7F77DD; border-radius:3px;
    cursor:se-resize; z-index:20;
}
.ddpe-ed-zone.selected .ddpe-ed-resize { background:#F97316; }
.ddpe-ed-zone-img {
    width:100%; height:100%; object-fit:cover;
    display:block; opacity:.75; pointer-events:none; user-select:none;
}
.ddpe-ed-zone-placeholder {
    font-size:28px; color:rgba(127,119,221,.4);
    pointer-events:none; user-select:none;
}

/* Buttons */
.ddpe-save-btn {
    display:inline-flex; align-items:center; justify-content:center;
    padding:12px 28px; border:none; border-radius:12px;
    background:linear-gradient(180deg,#22c55e 0%,#16a34a 100%);
    color:#fff; font-weight:800; font-size:15px; cursor:pointer;
    box-shadow:0 8px 20px rgba(22,163,74,.3);
    transition:transform .15s,filter .15s;
    width:100%; margin-top:6px;
}
.ddpe-save-btn:hover { filter:brightness(1.06); transform:translateY(-1px); }
.ddpe-preview-link {
    display:inline-flex; align-items:center; justify-content:center;
    padding:10px 20px; border:none; border-radius:12px;
    background:linear-gradient(180deg,#60a5fa 0%,#3b82f6 100%);
    color:#fff; font-weight:700; font-size:14px; text-decoration:none;
    cursor:pointer; width:100%; margin-top:6px;
    box-shadow:0 6px 16px rgba(59,130,246,.3);
    transition:transform .15s,filter .15s;
    box-sizing:border-box; text-align:center;
}
.ddpe-preview-link:hover { filter:brightness(1.06); transform:translateY(-1px); color:#fff; }

.ddpe-alert {
    padding:12px 16px; border-radius:10px;
    font-size:14px; font-weight:600; margin-bottom:14px;
}
.ddpe-alert-error   { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }
.ddpe-alert-success { background:#dcfce7; color:#14532d; border:1px solid #86efac; }
</style>

<?php if ($error !== ''): ?>
<div class="ddpe-alert ddpe-alert-error"><?= ddpe_h($error) ?></div>
<?php endif; ?>
<?php if ($saved): ?>
<div class="ddpe-alert ddpe-alert-success">Activity saved successfully!</div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" id="ddpeForm">
    <input type="hidden" name="items_json"        id="itemsJsonInput" value="">
    <input type="hidden" name="bg_image_existing" id="bgImageExisting"
           value="<?= ddpe_h($activity['background_image']) ?>">

    <div class="ddpe-grid">

        <!-- ── Side panel ──────────────────────── -->
        <div class="ddpe-side">

            <!-- Title & instructions -->
            <div class="ddpe-card">
                <label>Title</label>
                <input type="text" name="activity_title"
                       value="<?= ddpe_h($activity['title']) ?>"
                       placeholder="e.g. Fun Activity">
                <label style="margin-top:10px">Instructions (optional)</label>
                <textarea name="activity_instructions"
                          placeholder="Drag each picture to the correct place."><?= ddpe_h($activity['instructions']) ?></textarea>
            </div>

            <!-- Background image -->
            <div class="ddpe-card">
                <label>Background Scene Image</label>
                <input type="file" name="bg_image" accept="image/*" id="bgImageFile">
                <?php if ($activity['background_image'] !== ''): ?>
                <p style="font-size:12px;color:#16a34a;margin:6px 0 0">✔ Image uploaded</p>
                <?php endif; ?>
                <p class="ddpe-hint">Upload a scene or illustration. Then click on it to place picture zones.</p>
            </div>

            <!-- Items list -->
            <div class="ddpe-card">
                <label>Picture Zones</label>
                <p class="ddpe-hint" style="margin:0 0 8px">
                    Click on the image (right) to add a zone. Upload a picture for each numbered zone below.
                </p>
                <ul class="ddpe-item-list" id="itemList"></ul>
                <p id="noItemsHint">No zones yet — click on the image to add one.</p>
            </div>

            <!-- Save -->
            <div class="ddpe-card" style="gap:8px">
                <button type="submit" class="ddpe-save-btn">Save Activity</button>
                <?php if ($activityId !== ''): ?>
                <a href="viewer.php?id=<?= urlencode($activityId) ?>&unit=<?= urlencode($unit) ?>"
                   target="_blank" class="ddpe-preview-link">Preview</a>
                <?php endif; ?>
            </div>

        </div><!-- /side -->

        <!-- ── Canvas panel ────────────────────── -->
        <div class="ddpe-canvas-card">
            <p class="ddpe-canvas-title">Click image to place a zone · Drag to move · Drag corner to resize</p>
            <div class="ddpe-canvas-wrap" id="ddpeCanvasWrap">
                <div class="ddpe-empty" id="ddpeEmptyHint">Upload a background image to get started</div>
                <img id="ddpeEdBg" src="" alt="" style="display:none">
                <div class="ddpe-overlay" id="ddpeOverlay"></div>
            </div>
        </div>

    </div><!-- /grid -->
</form>

<script>
/* ── State ─────────────────────────────── */
const INIT_ITEMS = <?= json_encode(array_values($activity['items']), JSON_UNESCAPED_UNICODE) ?>;
let items   = INIT_ITEMS.length ? JSON.parse(JSON.stringify(INIT_ITEMS)) : [];
let nextId  = items.length ? Math.max(...items.map(function(p){ return p.id; })) + 1 : 1;

/* pending local (data-URL) picture previews keyed by item id */
const localPreviews = {};

const wrap      = document.getElementById('ddpeCanvasWrap');
const overlay   = document.getElementById('ddpeOverlay');
const bgImg     = document.getElementById('ddpeEdBg');
const emptyHint = document.getElementById('ddpeEmptyHint');
const itemList  = document.getElementById('itemList');
const noItemsHint = document.getElementById('noItemsHint');
const itemsInput  = document.getElementById('itemsJsonInput');
const bgExisting  = document.getElementById('bgImageExisting');
const bgFile      = document.getElementById('bgImageFile');

/* ── Background preview ─────────────────── */
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

bgFile.addEventListener('change', function() {
    var file = this.files[0];
    if (!file) return;
    var reader = new FileReader();
    reader.onload = function(e) {
        bgExisting.value = '';
        loadBgPreview(e.target.result);
    };
    reader.readAsDataURL(file);
});

if (<?= json_encode($activity['background_image']) ?>) {
    loadBgPreview(<?= json_encode($activity['background_image']) ?>);
}

/* ── Zone rendering ─────────────────────── */
function renderZones() {
    document.querySelectorAll('.ddpe-ed-zone').forEach(function(z){ z.remove(); });
    items.forEach(function(it){ createZoneEl(it); });
    renderItemList();
}

function getItemPreview(it) {
    return localPreviews[it.id] || it.pic_url || '';
}

function createZoneEl(it) {
    var el = document.createElement('div');
    el.className  = 'ddpe-ed-zone';
    el.id         = 'edzone-' + it.id;
    el.dataset.id = it.id;
    el.style.left   = it.x + '%';
    el.style.top    = it.y + '%';
    el.style.width  = it.w + '%';
    el.style.height = it.h + '%';

    var num = document.createElement('div');
    num.className   = 'ddpe-ed-num';
    num.textContent = it.id;
    el.appendChild(num);

    /* preview image inside zone */
    var preview = getItemPreview(it);
    if (preview) {
        var img = document.createElement('img');
        img.src = preview;
        img.className = 'ddpe-ed-zone-img';
        el.appendChild(img);
    } else {
        var ph = document.createElement('div');
        ph.className   = 'ddpe-ed-zone-placeholder';
        ph.textContent = '📷';
        el.appendChild(ph);
    }

    var handle = document.createElement('div');
    handle.className    = 'ddpe-ed-resize';
    handle.dataset.resize = 'true';
    el.appendChild(handle);

    wrap.appendChild(el);
    makeDraggable(el, it);
    makeResizable(handle, el, it);
}

function updateZoneContent(id) {
    var el = document.getElementById('edzone-' + id);
    if (!el) return;
    /* remove old content (image/placeholder), keep num and resize */
    el.querySelectorAll('.ddpe-ed-zone-img,.ddpe-ed-zone-placeholder').forEach(function(c){ c.remove(); });
    var it      = items.find(function(i){ return i.id === id; });
    var preview = getItemPreview(it || {});
    if (preview) {
        var img = document.createElement('img');
        img.src = preview;
        img.className = 'ddpe-ed-zone-img';
        el.insertBefore(img, el.querySelector('.ddpe-ed-resize'));
    } else {
        var ph = document.createElement('div');
        ph.className   = 'ddpe-ed-zone-placeholder';
        ph.textContent = '📷';
        el.insertBefore(ph, el.querySelector('.ddpe-ed-resize'));
    }
}

/* ── Item list in side panel ────────────── */
function renderItemList() {
    itemList.innerHTML = '';
    noItemsHint.style.display = items.length ? 'none' : 'block';

    items.forEach(function(it) {
        var li = document.createElement('li');
        li.className  = 'ddpe-item';
        li.dataset.id = it.id;

        /* number badge */
        var num = document.createElement('div');
        num.className   = 'ddpe-item-num';
        num.textContent = it.id;

        /* thumbnail */
        var preview = getItemPreview(it);
        var thumb;
        if (preview) {
            thumb = document.createElement('img');
            thumb.src       = preview;
            thumb.className = 'ddpe-item-thumb';
            thumb.alt       = '';
        } else {
            thumb = document.createElement('div');
            thumb.className   = 'ddpe-item-thumb placeholder';
            thumb.textContent = '📷';
        }

        /* body: label + file input */
        var body = document.createElement('div');
        body.className = 'ddpe-item-body';

        var labelInp = document.createElement('input');
        labelInp.type        = 'text';
        labelInp.className   = 'ddpe-item-label-input';
        labelInp.placeholder = 'Label (optional)';
        labelInp.value       = it.label || '';
        labelInp.addEventListener('input', function() {
            var item = items.find(function(p){ return p.id === it.id; });
            if (item) item.label = this.value;
        });

        var fileInp = document.createElement('input');
        fileInp.type      = 'file';
        fileInp.className = 'ddpe-item-file-input';
        fileInp.accept    = 'image/*';
        fileInp.name      = 'pic_new[' + it.id + ']';
        fileInp.addEventListener('change', function() {
            var file = this.files[0];
            if (!file) return;
            var reader = new FileReader();
            var capturedId = it.id;
            reader.onload = function(e) {
                localPreviews[capturedId] = e.target.result;
                /* update thumbnail in list */
                var liEl = itemList.querySelector('[data-id="' + capturedId + '"]');
                if (liEl) {
                    var oldThumb = liEl.querySelector('.ddpe-item-thumb');
                    if (oldThumb) {
                        var newThumb = document.createElement('img');
                        newThumb.src       = e.target.result;
                        newThumb.className = 'ddpe-item-thumb';
                        newThumb.alt       = '';
                        oldThumb.replaceWith(newThumb);
                    }
                }
                /* update canvas zone preview */
                updateZoneContent(capturedId);
            };
            reader.readAsDataURL(file);
        });

        body.appendChild(labelInp);
        body.appendChild(fileInp);

        /* delete button */
        var del = document.createElement('button');
        del.type        = 'button';
        del.className   = 'ddpe-del-btn';
        del.textContent = '×';
        del.title       = 'Remove zone';
        del.addEventListener('click', function() { removeItem(it.id); });

        li.appendChild(num);
        li.appendChild(thumb);
        li.appendChild(body);
        li.appendChild(del);
        itemList.appendChild(li);
    });
}

/* ── Add zone on canvas click ───────────── */
overlay.addEventListener('click', function(e) {
    if (!bgLoaded) return;
    if (e.target.dataset.resize) return;
    if (e.target.classList.contains('ddpe-ed-zone')) return;

    var rect = wrap.getBoundingClientRect();
    var imgW = bgImg.clientWidth  || rect.width;
    var imgH = bgImg.clientHeight || rect.height;

    var xPct = (e.clientX - rect.left) / imgW * 100;
    var yPct = (e.clientY - rect.top)  / imgH * 100;

    var w = 14, h = 12;
    var clampedX = Math.min(xPct - w / 2, 100 - w);
    var clampedY = Math.min(yPct - h / 2, 100 - h);

    var it = {
        id:      nextId++,
        pic_url: '',
        label:   '',
        x:       Math.max(0, parseFloat(clampedX.toFixed(4))),
        y:       Math.max(0, parseFloat(clampedY.toFixed(4))),
        w:       w,
        h:       h,
    };
    items.push(it);
    createZoneEl(it);
    renderItemList();

    /* focus the file input of the new item */
    var li = itemList.querySelector('[data-id="' + it.id + '"]');
    if (li) {
        var fi = li.querySelector('input[type="file"]');
        if (fi) setTimeout(function(){ fi.click(); }, 80);
    }
});

/* ── Drag to reposition ─────────────────── */
function makeDraggable(el, it) {
    var startMouseX, startMouseY, startX, startY, dragging = false;

    el.addEventListener('mousedown', function(e) {
        if (e.target.dataset.resize) return;
        e.preventDefault();
        dragging    = true;
        startMouseX = e.clientX;
        startMouseY = e.clientY;
        startX      = it.x;
        startY      = it.y;
        el.classList.add('selected');
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
    });

    function onMove(e) {
        if (!dragging) return;
        var rect = wrap.getBoundingClientRect();
        var imgW = bgImg.clientWidth  || rect.width;
        var imgH = bgImg.clientHeight || rect.height;
        var dx = (e.clientX - startMouseX) / imgW * 100;
        var dy = (e.clientY - startMouseY) / imgH * 100;
        it.x = Math.max(0, Math.min(100 - it.w, parseFloat((startX + dx).toFixed(4))));
        it.y = Math.max(0, Math.min(100 - it.h, parseFloat((startY + dy).toFixed(4))));
        el.style.left = it.x + '%';
        el.style.top  = it.y + '%';
    }

    function onUp() {
        dragging = false;
        el.classList.remove('selected');
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);
    }
}

/* ── Resize handle ──────────────────────── */
function makeResizable(handle, el, it) {
    handle.addEventListener('mousedown', function(e) {
        e.preventDefault();
        e.stopPropagation();
        var rect       = wrap.getBoundingClientRect();
        var imgW       = bgImg.clientWidth  || rect.width;
        var imgH       = bgImg.clientHeight || rect.height;
        var startMX    = e.clientX;
        var startMY    = e.clientY;
        var startW     = it.w;
        var startH     = it.h;

        function onMove(e) {
            var dx = (e.clientX - startMX) / imgW * 100;
            var dy = (e.clientY - startMY) / imgH * 100;
            it.w = Math.max(4, Math.min(60, parseFloat((startW + dx).toFixed(4))));
            it.h = Math.max(4, Math.min(60, parseFloat((startH + dy).toFixed(4))));
            el.style.width  = it.w + '%';
            el.style.height = it.h + '%';
        }
        function onUp() {
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);
        }
        document.addEventListener('mousemove', onMove);
        document.addEventListener('mouseup', onUp);
    });
}

/* ── Remove item ─────────────────────────── */
function removeItem(id) {
    items = items.filter(function(it){ return it.id !== id; });
    delete localPreviews[id];
    var el = document.getElementById('edzone-' + id);
    if (el) el.remove();
    renderItemList();
}

/* ── Serialize on submit ────────────────── */
document.getElementById('ddpeForm').addEventListener('submit', function() {
    /* include pic_url from existing data (not overwritten by new upload) */
    itemsInput.value = JSON.stringify(items);
});

/* ── Boot ────────────────────────────────── */
renderItemList();
</script>
<?php
$content = ob_get_clean();
render_activity_editor('Drag & Drop Picture — Editor', '🖼️', $content);
