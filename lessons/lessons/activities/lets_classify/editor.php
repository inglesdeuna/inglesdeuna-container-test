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
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $unit = $row ? (string)($row['unit_id'] ?? '') : '';
}
if ($unit === '') die('Unit not specified');

$error = '';
$saved = false;

/* -- POST: save ------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title        = trim($_POST['activity_title']        ?? '');
    $instructions = trim($_POST['activity_instructions'] ?? '');
    $cefr         = trim($_POST['cefr']                  ?? 'A1');
    $labelMode    = trim($_POST['label_mode']            ?? 'both');
    $catsJson     = $_POST['categories_json']            ?? '[]';
    $itemsJson    = $_POST['items_json']                 ?? '[]';

    $cats  = json_decode($catsJson,  true);
    $items = json_decode($itemsJson, true);
    if (!is_array($cats))  $cats  = [];
    if (!is_array($items)) $items = [];

    $cleanCats = [];
    foreach ($cats as $c) {
        if (!is_array($c)) continue;
        $catId   = (int)($c['id']    ?? 0);
        $catName = trim((string)($c['name']  ?? ''));
        $catImg  = trim((string)($c['image'] ?? ''));
        if ($catId <= 0 || $catName === '') continue;

        $fileKey = 'cat_image_' . $catId;
        if (!empty($_FILES[$fileKey]['tmp_name'])) {
            $uploaded = upload_to_cloudinary($_FILES[$fileKey]['tmp_name']);
            if ($uploaded) $catImg = $uploaded;
        }
        $cleanCats[] = ['id' => $catId, 'name' => $catName, 'image' => $catImg];
    }

    $validCatIds = array_map(static fn($c) => (int)$c['id'], $cleanCats);
    $cleanItems = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $itemId    = (int)($item['id']           ?? 0);
        $itemName  = trim((string)($item['name'] ?? ''));
        $itemImg   = trim((string)($item['image'] ?? ''));
        $itemAudio = trim((string)($item['audio'] ?? ''));
        $itemCat   = (int)($item['category_id']  ?? 0);
        if ($itemId <= 0 || $itemName === '') continue;
        if (!in_array($itemCat, $validCatIds, true)) $itemCat = 0;

        $fileKey = 'item_image_' . $itemId;
        if (!empty($_FILES[$fileKey]['tmp_name'])) {
            $uploaded = upload_to_cloudinary($_FILES[$fileKey]['tmp_name']);
            if ($uploaded) $itemImg = $uploaded;
        }
        $cleanItems[] = [
            'id'          => $itemId,
            'name'        => $itemName,
            'image'       => $itemImg,
            'audio'       => $itemAudio,
            'category_id' => $itemCat,
        ];
    }

    if (count($cleanCats) < 2) {
        $error = 'Add at least 2 categories.';
    } elseif (count($cleanCats) > 6) {
        $error = 'Use a maximum of 6 categories.';
    } elseif (count($cleanCats) % 2 !== 0) {
        $error = 'Categories must be created in pairs: 2, 4, or 6.';
    } elseif (count($cleanItems) < 2) {
        $error = 'Add at least 2 items.';
    } else {
        $payload = json_encode([
            'title'        => $title !== '' ? $title : "Let's Classify",
            'instructions' => $instructions,
            'cefr'         => $cefr,
            'label_mode'   => $labelMode,
            'categories'   => $cleanCats,
            'items'        => $cleanItems,
        ], JSON_UNESCAPED_UNICODE);

        if ($activityId !== '') {
            $stmt = $pdo->prepare("UPDATE activities SET data = :data WHERE id = :id AND type = 'lets_classify'");
            $stmt->execute(['data' => $payload, 'id' => $activityId]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO activities (unit_id, type, data, position, created_at)
                VALUES (
                    :unit,
                    'lets_classify',
                    :data,
                    (SELECT COALESCE(MAX(position), 0) + 1 FROM activities WHERE unit_id = :unit2),
                    CURRENT_TIMESTAMP
                )
                RETURNING id
            ");
            $stmt->execute(['unit' => $unit, 'unit2' => $unit, 'data' => $payload]);
            $activityId = (string) $stmt->fetchColumn();
        }
        $saved = true;
    }
}

/* -- Load existing --------------------------------------------- */
$activity = [
    'title'        => '',
    'instructions' => '',
    'cefr'         => 'A1',
    'label_mode'   => 'both',
    'categories'   => [],
    'items'        => [],
];
if ($activityId !== '') {
    $stmt = $pdo->prepare("SELECT data FROM activities WHERE id = :id AND type = 'lets_classify' LIMIT 1");
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
.lc-ed-wrap { display: flex; flex-direction: column; gap: 22px; }
.lc-section { background: #fff; border: 1px solid #EDE9FA; border-radius: 16px; overflow: hidden; }
.lc-section-head { padding: 13px 18px; background: #F9F8FF; border-bottom: 1px solid #EDE9FA; display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.lc-section-head h3 { margin: 0; font-size: 13px; font-weight: 800; color: #F97316; text-transform: uppercase; letter-spacing: .04em; display: flex; align-items: center; gap: 7px; }
.lc-section-body { padding: 18px; }

.lc-field-row { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; }
.lc-field-row:last-child { margin-bottom: 0; }
.lc-label { width: 110px; flex-shrink: 0; font-size: 12px; font-weight: 700; color: #7F77DD; }
.lc-input, .lc-select, .lc-textarea { border: 1.5px solid #EDE9FA; border-radius: 8px; padding: 7px 11px; font-family: inherit; font-size: 13px; color: #1E1B3A; background: #fff; flex: 1; transition: border-color .15s; }
.lc-input:focus, .lc-select:focus, .lc-textarea:focus { outline: none; border-color: #7F77DD; }
.lc-textarea { resize: vertical; min-height: 60px; }
.lc-select { flex: 0 0 auto; }

/* Categories: always paired, two cards per row */
.lc-cats-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin-bottom: 14px; max-width: 560px; }
.lc-cat-card { border: 1.5px solid #EDE9FA; border-radius: 14px; padding: 12px; background: #FAFAFE; display: flex; flex-direction: column; gap: 8px; min-width: 0; }
.lc-cat-top { display: flex; align-items: center; gap: 7px; }
.lc-cat-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; background: #EDE9FA; }
.lc-cat-name { flex: 1; min-width: 0; border: none; border-bottom: 1.5px dashed #EDE9FA; background: transparent; font-size: 14px; font-weight: 700; color: #1E1B3A; font-family: inherit; padding: 2px 0; }
.lc-cat-name:focus { outline: none; border-color: #7F77DD; }
.lc-cat-card.lc-cat-error { border-color: #fca5a5; background: #fff5f5; }
.lc-cat-card.lc-cat-error .lc-cat-name { border-bottom-color: #f87171; }
.lc-inline-alert { display:none; padding:12px 16px; border-radius:10px; font-size:14px; font-weight:600; margin-bottom:16px; background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }
.lc-cat-del { width: 26px; height: 26px; display: inline-flex; align-items: center; justify-content: center; background: #FFF0E8; border: 1px solid #FFD8C2; border-radius: 999px; cursor: pointer; color: #D85A30; font-size: 13px; line-height: 1; padding: 0; flex-shrink: 0; transition: background .15s, color .15s, transform .15s; }
.lc-cat-del:hover { background: #FFE1D1; color: #B93817; transform: scale(1.04); }
.lc-cat-del:disabled { opacity: .45; cursor: not-allowed; transform: none; }
.lc-img-preview { width: 100%; height: 74px; border-radius: 10px; border: 1.5px dashed #EDE9FA; background: #F5F3FF; display: flex; align-items: center; justify-content: center; font-size: 12px; color: #9B8FCC; gap: 5px; cursor: pointer; overflow: hidden; position: relative; }
.lc-img-preview img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; border-radius: 8px; }
.lc-img-preview.has-img { border-style: solid; border-color: #c5bff5; }
.lc-img-overlay { position: absolute; inset: 0; background: rgba(127,119,221,.55); display: flex; align-items: center; justify-content: center; opacity: 0; transition: opacity .15s; border-radius: 8px; font-size: 11px; color: #fff; font-weight: 700; gap: 4px; }
.lc-img-preview:hover .lc-img-overlay { opacity: 1; }
.lc-file-hidden { display: none; }
.lc-img-label { font-size: 11px; color: #9B8FCC; text-align: center; }
.lc-cat-help { color:#9B8FCC; font-size:12px; font-weight:700; margin-top:2px; }

.lc-items-table { width: 100%; border-collapse: collapse; }
.lc-items-table th { background: #F9F8FF; font-size: 11px; font-weight: 800; color: #9B8FCC; text-transform: uppercase; letter-spacing: .04em; padding: 8px 10px; border-bottom: 1.5px solid #EDE9FA; text-align: left; }
.lc-items-table td { padding: 7px 10px; border-bottom: 1px solid #F5F3FF; vertical-align: middle; }
.lc-items-table tr:last-child td { border-bottom: none; }
.lc-drag-col { width: 28px; color: #D0CDF0; cursor: grab; font-size: 16px; text-align: center; }
.lc-thumb-col { width: 44px; }
.lc-thumb-wrap { width: 40px; height: 40px; border-radius: 8px; border: 1.5px dashed #EDE9FA; background: #F5F3FF; display: flex; align-items: center; justify-content: center; cursor: pointer; overflow: hidden; position: relative; flex-shrink: 0; }
.lc-thumb-wrap img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; border-radius: 6px; }
.lc-thumb-wrap.has-img { border-style: solid; border-color: #c5bff5; }
.lc-thumb-overlay { position: absolute; inset: 0; background: rgba(127,119,221,.55); display: flex; align-items: center; justify-content: center; opacity: 0; transition: opacity .15s; border-radius: 6px; }
.lc-thumb-wrap:hover .lc-thumb-overlay { opacity: 1; }
.lc-item-name-in { border: 1.5px solid #EDE9FA; border-radius: 7px; padding: 5px 9px; font-size: 13px; font-family: inherit; color: #1E1B3A; background: #fff; width: 100%; transition: border-color .15s; }
.lc-item-name-in:focus { outline: none; border-color: #7F77DD; }
.lc-cat-sel { border: 1.5px solid #EDE9FA; border-radius: 7px; padding: 5px 8px; font-size: 12px; font-family: inherit; color: #1E1B3A; background: #fff; width: 100%; }
.lc-audio-btn { display: inline-flex; align-items: center; gap: 5px; border: 1.5px solid #EDE9FA; background: #fff; border-radius: 7px; padding: 5px 10px; font-size: 11px; font-weight: 700; color: #9B8FCC; cursor: pointer; white-space: nowrap; font-family: inherit; transition: border-color .15s, color .15s; }
.lc-audio-btn:hover { border-color: #7F77DD; color: #7F77DD; }
.lc-audio-btn.ready { border-color: #9FE1CB; color: #0F6E56; background: #E1F5EE; }
.lc-audio-btn.loading { opacity: .6; pointer-events: none; }
.lc-del-btn { background: none; border: none; cursor: pointer; color: #F0997B; font-size: 15px; padding: 3px 5px; }
.lc-del-btn:hover { color: #D85A30; }

.lc-add-btn { display: inline-flex; align-items: center; gap: 6px; border: 1.5px dashed #EDE9FA; background: none; border-radius: 8px; padding: 8px 16px; font-size: 13px; font-weight: 700; color: #9B8FCC; cursor: pointer; font-family: inherit; width: 100%; justify-content: center; margin-top: 8px; transition: border-color .15s, color .15s, opacity .15s; }
.lc-add-btn:hover { border-color: #7F77DD; color: #7F77DD; }
.lc-add-btn:disabled { opacity: .45; cursor: not-allowed; }
.lc-csv-btn { display: inline-flex; align-items: center; gap: 6px; border: 1.5px solid #EDE9FA; background: #fff; border-radius: 8px; padding: 6px 12px; font-size: 12px; font-weight: 700; color: #9B8FCC; cursor: pointer; font-family: inherit; transition: border-color .15s; }
.lc-csv-btn:hover { border-color: #7F77DD; color: #7F77DD; }
.lc-alert { padding: 12px 16px; border-radius: 10px; font-size: 14px; font-weight: 600; margin-bottom: 16px; }
.lc-alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
.lc-alert-success { background: #dcfce7; color: #14532d; border: 1px solid #86efac; }
.lc-footer { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.lc-save-btn { display: inline-flex; align-items: center; gap: 7px; padding: 11px 28px; border: none; border-radius: 10px; background: #F97316; color: #fff; font-weight: 800; font-size: 15px; cursor: pointer; font-family: inherit; transition: filter .15s, transform .15s; }
.lc-save-btn:hover { filter: brightness(1.07); transform: translateY(-1px); }
.lc-preview-btn { display: inline-flex; align-items: center; gap: 7px; padding: 10px 22px; border: none; border-radius: 10px; background: #7F77DD; color: #fff; font-weight: 700; font-size: 14px; cursor: pointer; font-family: inherit; text-decoration: none; transition: filter .15s, transform .15s; }
.lc-preview-btn:hover { filter: brightness(1.07); transform: translateY(-1px); color: #fff; }
.lc-status-badge { font-size: 12px; color: #9B8FCC; background: #F5F3FF; border: 1px solid #EDE9FA; border-radius: 20px; padding: 5px 14px; }
.lc-items-table tr.dragging-row { opacity: .4; }
.lc-items-table tr.drag-over-row td { border-top: 2px solid #7F77DD; }
@media(max-width:680px){ .lc-field-row{align-items:flex-start;flex-direction:column}.lc-label{width:auto}.lc-cats-grid{grid-template-columns:1fr;max-width:none}.lc-items-table{font-size:12px}.lc-items-table th,.lc-items-table td{padding:6px} }
</style>

<?php if ($error !== ''): ?>
<div class="lc-alert lc-alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php if ($saved): ?>
<div class="lc-alert lc-alert-success">✔ Activity saved successfully!</div>
<?php endif; ?>

<div class="lc-inline-alert" id="lcInlineAlert"></div>
<form method="POST" enctype="multipart/form-data" id="lcForm">
    <input type="hidden" name="categories_json" id="lcCatsJson" value="">
    <input type="hidden" name="items_json" id="lcItemsJson" value="">

    <div class="lc-ed-wrap">
        <div class="lc-section">
            <div class="lc-section-head"><h3><i class="fas fa-sliders-h"></i> General settings</h3></div>
            <div class="lc-section-body">
                <div class="lc-field-row">
                    <span class="lc-label">Activity title</span>
                    <input class="lc-input" type="text" name="activity_title" value="<?= htmlspecialchars($activity['title'], ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. Let's Classify — Transportation">
                </div>
                <div class="lc-field-row">
                    <span class="lc-label">Instructions</span>
                    <textarea class="lc-textarea" name="activity_instructions" placeholder="Drag each item to the correct category."><?= htmlspecialchars($activity['instructions'], ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>
                <div class="lc-field-row">
                    <span class="lc-label">CEFR level</span>
                    <select class="lc-select" name="cefr" style="width:90px;">
                        <?php foreach (['A1','A2','B1','B2','C1','C2'] as $lvl): ?>
                        <option value="<?= $lvl ?>"<?= $activity['cefr'] === $lvl ? ' selected' : '' ?>><?= $lvl ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="lc-label" style="width:90px;margin-left:16px;">Show labels</span>
                    <select class="lc-select" name="label_mode" style="width:160px;">
                        <option value="both"<?= $activity['label_mode']==='both'?' selected':'' ?>>Name + image</option>
                        <option value="image"<?= $activity['label_mode']==='image'?' selected':'' ?>>Image only</option>
                        <option value="name"<?= $activity['label_mode']==='name'?' selected':'' ?>>Name only</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="lc-section">
            <div class="lc-section-head">
                <h3><i class="fas fa-th-large"></i> Categories <span id="lcCatCount" style="font-size:11px;font-weight:400;color:#9B8FCC;margin-left:4px;">(2, 4 or 6)</span></h3>
                <button type="button" class="lc-add-btn" id="lcAddCat" style="width:auto;margin:0;padding:5px 14px;font-size:12px;"><i class="fas fa-plus"></i> Add pair</button>
            </div>
            <div class="lc-section-body">
                <div class="lc-cat-help">Categories are organized in pairs: 2 = one row, 4 = two rows, 6 = three rows.</div>
                <div class="lc-cats-grid" id="lcCatsGrid"></div>
            </div>
        </div>

        <div class="lc-section">
            <div class="lc-section-head">
                <h3><i class="fas fa-images"></i> Items <span id="lcItemCount" style="font-size:11px;font-weight:400;color:#9B8FCC;margin-left:4px;"></span></h3>
                <button type="button" class="lc-csv-btn" id="lcCsvBtn"><i class="fas fa-file-csv"></i> Bulk import CSV</button>
                <input type="file" id="lcCsvFile" accept=".csv" class="lc-file-hidden">
            </div>
            <div class="lc-section-body" style="padding:0;">
                <table class="lc-items-table">
                    <thead><tr><th class="lc-drag-col"></th><th style="width:50px;">Image</th><th>Name</th><th style="width:150px;">Category</th><th style="width:110px;">Audio</th><th style="width:36px;"></th></tr></thead>
                    <tbody id="lcItemsTbody"></tbody>
                </table>
                <div style="padding:10px 14px;"><button type="button" class="lc-add-btn" id="lcAddItem"><i class="fas fa-plus"></i> Add item</button></div>
            </div>
        </div>

        <div class="lc-footer">
            <span class="lc-status-badge" id="lcStatusBadge">0 items · 0 categories</span>
            <div style="display:flex;gap:10px;align-items:center;">
                <?php if ($activityId !== ''): ?>
                <a class="lc-preview-btn" href="viewer.php?id=<?= urlencode($activityId) ?>&unit=<?= urlencode($unit) ?>" target="_blank"><i class="fas fa-eye"></i> Preview</a>
                <?php endif; ?>
                <button type="submit" class="lc-save-btn"><i class="fas fa-save"></i> Save activity</button>
            </div>
        </div>
    </div>
</form>

<script>
const INIT_CATS  = <?= json_encode(array_values($activity['categories']), JSON_UNESCAPED_UNICODE) ?>;
const INIT_ITEMS = <?= json_encode(array_values($activity['items']),      JSON_UNESCAPED_UNICODE) ?>;
const MAX_CATS = 6;
const MIN_CATS = 2;

let cats     = JSON.parse(JSON.stringify(INIT_CATS));
let items    = JSON.parse(JSON.stringify(INIT_ITEMS));
let nextCatId  = cats.length  ? Math.max(...cats.map(c=>parseInt(c.id) || 0))  + 1 : 1;
let nextItemId = items.length ? Math.max(...items.map(i=>parseInt(i.id) || 0)) + 1 : 1;
const pendingFiles = {};
const DOT_COLORS = ['#F97316','#378ADD','#3B6D11','#D4537E','#7F77DD','#0EA5E9'];
const catsGrid = document.getElementById('lcCatsGrid');
const tbody = document.getElementById('lcItemsTbody');
const addCatBtn = document.getElementById('lcAddCat');

function ensureInitialCategories() {
    if (!Array.isArray(cats)) cats = [];
    if (cats.length === 0) {
        cats.push({ id: nextCatId++, name: '', image: '' }, { id: nextCatId++, name: '', image: '' });
    } else if (cats.length === 1) {
        cats.push({ id: nextCatId++, name: '', image: '' });
    }
}

function showInlineAlert(message) {
    const inlineAlert = document.getElementById('lcInlineAlert');
    inlineAlert.textContent = message;
    inlineAlert.style.display = 'block';
    inlineAlert.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function hideInlineAlert() {
    const inlineAlert = document.getElementById('lcInlineAlert');
    inlineAlert.style.display = 'none';
    inlineAlert.textContent = '';
}

function reassignItemsFromRemovedCategory(catId) {
    items.forEach(item => {
        if (parseInt(item.category_id) === parseInt(catId)) item.category_id = 0;
    });
}

function renderCats() {
    catsGrid.innerHTML = '';
    cats.forEach((c, idx) => {
        const card = document.createElement('div');
        card.className = 'lc-cat-card';
        card.dataset.id = c.id;

        const dot = document.createElement('div');
        dot.className = 'lc-cat-dot';
        dot.style.background = DOT_COLORS[idx % DOT_COLORS.length];

        const nameInp = document.createElement('input');
        nameInp.className = 'lc-cat-name';
        nameInp.type = 'text';
        nameInp.value = c.name || '';
        nameInp.placeholder = 'Category name';
        nameInp.addEventListener('input', () => {
            c.name = nameInp.value.trim();
            if (c.name) card.classList.remove('lc-cat-error');
            renderItems();
            updateStatus();
        });

        const del = document.createElement('button');
        del.type = 'button';
        del.className = 'lc-cat-del';
        del.innerHTML = '<i class="fas fa-trash"></i>';
        del.title = cats.length <= MIN_CATS ? 'At least 2 categories are required' : 'Remove category';
        del.disabled = cats.length <= MIN_CATS;
        del.addEventListener('click', () => {
            hideInlineAlert();
            if (cats.length <= MIN_CATS) {
                showInlineAlert('At least 2 categories are required.');
                return;
            }
            delete pendingFiles['cat_' + c.id];
            reassignItemsFromRemovedCategory(c.id);
            cats = cats.filter(x => parseInt(x.id) !== parseInt(c.id));
            renderCats();
        });

        const top = document.createElement('div');
        top.className = 'lc-cat-top';
        top.appendChild(dot);
        top.appendChild(nameInp);
        top.appendChild(del);

        const fileInput = document.createElement('input');
        fileInput.type = 'file';
        fileInput.accept = 'image/*';
        fileInput.name = 'cat_image_' + c.id;
        fileInput.className = 'lc-file-hidden';
        fileInput.id = 'catFile_' + c.id;
        if (pendingFiles['cat_' + c.id]) {
            try {
                const dt = new DataTransfer();
                dt.items.add(pendingFiles['cat_' + c.id]);
                fileInput.files = dt.files;
            } catch (e) {}
        }

        const previewSrc = c._preview || c.image || '';
        const preview = document.createElement('div');
        preview.className = 'lc-img-preview' + (previewSrc ? ' has-img' : '');
        preview.id = 'catPreview_' + c.id;
        if (previewSrc) {
            const img = document.createElement('img');
            img.src = previewSrc;
            preview.appendChild(img);
        }
        const overlay = document.createElement('div');
        overlay.className = 'lc-img-overlay';
        overlay.innerHTML = '<i class="fas fa-camera"></i> Change';
        preview.appendChild(overlay);
        if (!previewSrc) {
            const lbl = document.createElement('span');
            lbl.className = 'lc-img-label';
            lbl.innerHTML = '<i class="fas fa-upload"></i> Upload image';
            preview.appendChild(lbl);
        }
        preview.addEventListener('click', () => fileInput.click());
        fileInput.addEventListener('change', function() {
            const file = this.files[0];
            if (!file) return;
            pendingFiles['cat_' + c.id] = file;
            const reader = new FileReader();
            reader.onload = e => {
                c._preview = e.target.result;
                renderCats();
            };
            reader.readAsDataURL(file);
        });

        card.appendChild(top);
        card.appendChild(fileInput);
        card.appendChild(preview);
        catsGrid.appendChild(card);
    });
    renderItems();
    updateStatus();
}

addCatBtn.addEventListener('click', () => {
    hideInlineAlert();
    if (cats.length >= MAX_CATS) {
        showInlineAlert('Maximum 6 categories.');
        return;
    }
    const slots = MAX_CATS - cats.length;
    const addCount = Math.min(2, slots);
    for (let i = 0; i < addCount; i++) {
        cats.push({ id: nextCatId++, name: '', image: '' });
    }
    renderCats();
    const firstBlank = catsGrid.querySelector('.lc-cat-name[value=""], .lc-cat-name');
    firstBlank?.focus();
});

function renderItems() {
    tbody.innerHTML = '';
    items.forEach(item => {
        const tr = document.createElement('tr');
        tr.dataset.id = item.id;

        const tdDrag = document.createElement('td');
        tdDrag.className = 'lc-drag-col';
        tdDrag.innerHTML = '<i class="fas fa-grip-vertical"></i>';
        tdDrag.draggable = true;
        setupRowDrag(tr, tdDrag);

        const tdThumb = document.createElement('td');
        const fileInput = document.createElement('input');
        fileInput.type = 'file';
        fileInput.accept = 'image/*';
        fileInput.name = 'item_image_' + item.id;
        fileInput.className = 'lc-file-hidden';
        fileInput.id = 'itemFile_' + item.id;
        if (pendingFiles['item_' + item.id]) {
            try {
                const dt = new DataTransfer();
                dt.items.add(pendingFiles['item_' + item.id]);
                fileInput.files = dt.files;
            } catch (e) {}
        }
        const itemPreviewSrc = item._preview || item.image || '';
        const thumb = document.createElement('div');
        thumb.className = 'lc-thumb-wrap' + (itemPreviewSrc ? ' has-img' : '');
        thumb.id = 'itemThumb_' + item.id;
        if (itemPreviewSrc) {
            const img = document.createElement('img');
            img.src = itemPreviewSrc;
            thumb.appendChild(img);
        }
        const thOv = document.createElement('div');
        thOv.className = 'lc-thumb-overlay';
        thOv.innerHTML = '<i class="fas fa-camera" style="color:#fff;font-size:12px;"></i>';
        thumb.appendChild(thOv);
        thumb.addEventListener('click', () => fileInput.click());
        fileInput.addEventListener('change', function() {
            const file = this.files[0];
            if (!file) return;
            pendingFiles['item_' + item.id] = file;
            const reader = new FileReader();
            reader.onload = e => { item._preview = e.target.result; renderItems(); };
            reader.readAsDataURL(file);
        });
        tdThumb.appendChild(fileInput);
        tdThumb.appendChild(thumb);

        const tdName = document.createElement('td');
        const nameInp = document.createElement('input');
        nameInp.type = 'text';
        nameInp.className = 'lc-item-name-in';
        nameInp.value = item.name || '';
        nameInp.placeholder = 'Item name…';
        nameInp.addEventListener('input', () => { item.name = nameInp.value.trim(); updateStatus(); });
        tdName.appendChild(nameInp);

        const tdCat = document.createElement('td');
        const catSel = document.createElement('select');
        catSel.className = 'lc-cat-sel';
        catSel.id = 'itemCatSel_' + item.id;
        const optBlank = document.createElement('option');
        optBlank.value = '0';
        optBlank.textContent = '— select —';
        catSel.appendChild(optBlank);
        cats.forEach(c => {
            const opt = document.createElement('option');
            opt.value = c.id;
            opt.textContent = c.name || '(unnamed)';
            if (parseInt(c.id) === parseInt(item.category_id)) opt.selected = true;
            catSel.appendChild(opt);
        });
        catSel.addEventListener('change', () => { item.category_id = parseInt(catSel.value) || 0; });
        tdCat.appendChild(catSel);

        const tdAudio = document.createElement('td');
        const audioBtn = document.createElement('button');
        audioBtn.type = 'button';
        audioBtn.className = 'lc-audio-btn' + (item.audio ? ' ready' : '');
        audioBtn.dataset.id = item.id;
        audioBtn.innerHTML = item.audio ? '<i class="fas fa-check"></i> Ready' : '<i class="fas fa-volume-up"></i> Generate';
        audioBtn.addEventListener('click', () => generateAudio(item, audioBtn));
        tdAudio.appendChild(audioBtn);

        const tdDel = document.createElement('td');
        const delBtn = document.createElement('button');
        delBtn.type = 'button';
        delBtn.className = 'lc-del-btn';
        delBtn.innerHTML = '<i class="fas fa-trash"></i>';
        delBtn.addEventListener('click', () => {
            delete pendingFiles['item_' + item.id];
            items = items.filter(x => parseInt(x.id) !== parseInt(item.id));
            renderItems();
            updateStatus();
        });
        tdDel.appendChild(delBtn);

        tr.appendChild(tdDrag);
        tr.appendChild(tdThumb);
        tr.appendChild(tdName);
        tr.appendChild(tdCat);
        tr.appendChild(tdAudio);
        tr.appendChild(tdDel);
        tbody.appendChild(tr);
    });
    updateStatus();
}

document.getElementById('lcAddItem').addEventListener('click', () => {
    items.push({ id: nextItemId++, name: '', image: '', audio: '', category_id: cats.length ? cats[0].id : 0 });
    renderItems();
    tbody.lastElementChild?.querySelector('input')?.focus();
});

async function generateAudio(item, btn) {
    if (!item.name) { alert('Enter a name first.'); return; }
    btn.classList.add('loading');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating…';
    try {
        const res = await fetch('../../core/tts.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ text: item.name, voice: 'Rachel' })
        });
        const data = await res.json();
        if (data.url) {
            item.audio = data.url;
            btn.className = 'lc-audio-btn ready';
            btn.innerHTML = '<i class="fas fa-check"></i> Ready';
        } else {
            throw new Error(data.error || 'TTS failed');
        }
    } catch (e) {
        btn.className = 'lc-audio-btn';
        btn.innerHTML = '<i class="fas fa-volume-up"></i> Generate';
        alert('Audio generation failed: ' + e.message);
    }
}

document.getElementById('lcCsvBtn').addEventListener('click', () => {
    document.getElementById('lcCsvFile').click();
});
document.getElementById('lcCsvFile').addEventListener('change', function() {
    const file = this.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = e => {
        const lines = e.target.result.split('\n');
        lines.forEach(line => {
            const parts = line.split(',');
            if (parts.length < 1) return;
            const name = parts[0].trim().replace(/^"|"$/g,'');
            const catName = (parts[1] || '').trim().replace(/^"|"$/g,'');
            if (!name) return;
            const matchCat = cats.find(c => (c.name || '').toLowerCase() === catName.toLowerCase());
            items.push({ id: nextItemId++, name, image: '', audio: '', category_id: matchCat ? matchCat.id : (cats.length ? cats[0].id : 0) });
        });
        renderItems();
    };
    reader.readAsText(file);
    this.value = '';
});

let dragSrcRow = null;
function setupRowDrag(tr, handle) {
    handle.addEventListener('mousedown', () => { tr.draggable = true; });
    tr.addEventListener('dragstart', e => {
        dragSrcRow = tr;
        tr.classList.add('dragging-row');
        e.dataTransfer.effectAllowed = 'move';
    });
    tr.addEventListener('dragend', () => {
        tr.draggable = false;
        tr.classList.remove('dragging-row');
        tbody.querySelectorAll('tr').forEach(r => r.classList.remove('drag-over-row'));
        const newOrder = [];
        tbody.querySelectorAll('tr').forEach(r => {
            const found = items.find(i => parseInt(i.id) === parseInt(r.dataset.id));
            if (found) newOrder.push(found);
        });
        items = newOrder;
    });
    tr.addEventListener('dragover', e => {
        e.preventDefault();
        if (dragSrcRow && dragSrcRow !== tr) {
            tbody.querySelectorAll('tr').forEach(r => r.classList.remove('drag-over-row'));
            tr.classList.add('drag-over-row');
        }
    });
    tr.addEventListener('drop', e => {
        e.preventDefault();
        if (dragSrcRow && dragSrcRow !== tr) {
            const allRows = [...tbody.querySelectorAll('tr')];
            const srcIdx = allRows.indexOf(dragSrcRow);
            const tgtIdx = allRows.indexOf(tr);
            if (srcIdx < tgtIdx) tr.parentNode.insertBefore(dragSrcRow, tr.nextSibling);
            else tr.parentNode.insertBefore(dragSrcRow, tr);
        }
    });
}

function updateStatus() {
    const cefr = document.querySelector('[name="cefr"]')?.value || '';
    document.getElementById('lcStatusBadge').textContent = items.length + ' items · ' + cats.length + ' categories' + (cefr ? ' · ' + cefr : '');
    document.getElementById('lcItemCount').textContent = '(' + items.length + ')';
    document.getElementById('lcCatCount').textContent = '(' + cats.length + '/6 · pairs)';
    addCatBtn.disabled = cats.length >= MAX_CATS;
}

document.getElementById('lcForm').addEventListener('submit', function(e) {
    tbody.querySelectorAll('tr').forEach(tr => {
        const id = parseInt(tr.dataset.id);
        const item = items.find(i => parseInt(i.id) === id);
        if (item) {
            const inp = tr.querySelector('.lc-item-name-in');
            if (inp) item.name = inp.value.trim();
            const sel = tr.querySelector('.lc-cat-sel');
            if (sel) item.category_id = parseInt(sel.value) || 0;
        }
    });
    catsGrid.querySelectorAll('.lc-cat-card').forEach(card => {
        const id = parseInt(card.dataset.id);
        const cat = cats.find(c => parseInt(c.id) === id);
        if (cat) {
            const inp = card.querySelector('.lc-cat-name');
            if (inp) cat.name = inp.value.trim();
        }
        card.classList.remove('lc-cat-error');
    });

    hideInlineAlert();
    let errors = [];
    const validCats = cats.filter(c => (c.name || '') !== '');
    const missingName = cats.filter(c => (c.name || '') === '');
    if (missingName.length) {
        missingName.forEach(c => {
            const card = catsGrid.querySelector('.lc-cat-card[data-id="' + c.id + '"]');
            if (card) { card.classList.add('lc-cat-error'); card.querySelector('.lc-cat-name')?.focus(); }
        });
        errors.push('Each category needs a name. Fill in the highlighted ' + (missingName.length === 1 ? 'category' : 'categories') + '.');
    }
    if (cats.length < MIN_CATS) errors.push('Add at least 2 categories.');
    if (cats.length > MAX_CATS) errors.push('Use a maximum of 6 categories.');
    if (cats.length % 2 !== 0) errors.push('Categories must stay in pairs: use 2, 4, or 6 categories.');
    if (validCats.length < MIN_CATS && !errors.some(m => m.startsWith('Each'))) errors.push('At least 2 categories must have a name.');

    const validCatIds = validCats.map(c => parseInt(c.id));
    const validItems = items.filter(i => (i.name || '') !== '');
    if (validItems.length < 2) errors.push('Add at least 2 items with names.');
    const unassignedItems = validItems.filter(i => !validCatIds.includes(parseInt(i.category_id)));
    if (unassignedItems.length) errors.push('Assign every item to one of the current categories.');

    if (errors.length) {
        e.preventDefault();
        showInlineAlert(errors[0]);
        return;
    }

    document.getElementById('lcCatsJson').value  = JSON.stringify(cats.map(({_preview, ...rest}) => rest));
    document.getElementById('lcItemsJson').value = JSON.stringify(items.map(({_preview, ...rest}) => rest));
});

ensureInitialCategories();
renderCats();
renderItems();
</script>
<?php
$content = ob_get_clean();
render_activity_editor("Let's Classify — Editor", 'fas fa-layer-group', $content);
