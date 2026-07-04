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

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit       = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
$source     = isset($_GET['source']) ? trim((string) $_GET['source']) : '';

if ($unit === '' && $activityId !== '') {
    $stmt = $pdo->prepare('SELECT unit_id FROM activities WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $unit = $row ? (string)($row['unit_id'] ?? '') : '';
}
if ($unit === '') die('Unit not specified');

$error = '';
$saved = false;

function lc_upload_if_present(string $key, string $fallback = ''): string
{
    if (!empty($_FILES[$key]['tmp_name'])) {
        $uploaded = upload_to_cloudinary($_FILES[$key]['tmp_name']);
        if ($uploaded) return $uploaded;
    }
    return $fallback;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title        = trim($_POST['activity_title'] ?? '');
    $instructions = trim($_POST['activity_instructions'] ?? '');
    $cefr         = trim($_POST['cefr'] ?? 'A1');
    $labelMode    = trim($_POST['label_mode'] ?? 'both');
    $cats         = json_decode($_POST['categories_json'] ?? '[]', true);
    $items        = json_decode($_POST['items_json'] ?? '[]', true);

    if (!is_array($cats)) $cats = [];
    if (!is_array($items)) $items = [];

    $cleanCats = [];
    foreach ($cats as $cat) {
        if (!is_array($cat)) continue;
        $catId = (int)($cat['id'] ?? 0);
        $catName = trim((string)($cat['name'] ?? ''));
        $catImg = trim((string)($cat['image'] ?? ''));
        if ($catId <= 0 || $catName === '') continue;
        $catImg = lc_upload_if_present('cat_image_' . $catId, $catImg);
        $cleanCats[] = ['id' => $catId, 'name' => $catName, 'image' => $catImg];
    }

    $validCatIds = array_map(static fn($c) => (int)$c['id'], $cleanCats);
    $cleanItems = [];

    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $itemId = (int)($item['id'] ?? 0);
        $itemName = trim((string)($item['name'] ?? ''));
        $itemImg = trim((string)($item['image'] ?? ''));
        $itemAudio = trim((string)($item['audio'] ?? ''));
        $itemCat = (int)($item['category_id'] ?? 0);
        if ($itemId <= 0) continue;

        $itemImg = lc_upload_if_present('item_image_' . $itemId, $itemImg);

        // Image-only items are valid. This is important for classification by pictures.
        if ($itemName === '' && $itemImg === '') continue;
        if (!in_array($itemCat, $validCatIds, true)) $itemCat = 0;

        $cleanItems[] = [
            'id' => $itemId,
            'name' => $itemName,
            'image' => $itemImg,
            'audio' => $itemAudio,
            'category_id' => $itemCat,
        ];
    }

    if (count($cleanCats) < 2) {
        $error = 'Add at least 2 categories.';
    } elseif (count($cleanCats) > 6) {
        $error = 'Use a maximum of 6 categories.';
    } elseif (count($cleanCats) % 2 !== 0) {
        $error = 'Categories must stay in pairs: use 2, 4, or 6 categories.';
    } elseif (count($cleanItems) < 2) {
        $error = 'Add at least 2 items. Items can have a name, an image, or both.';
    } elseif (count(array_filter($cleanItems, static fn($i) => (int)$i['category_id'] <= 0)) > 0) {
        $error = 'Assign every item to one of the current categories.';
    } else {
        $payload = json_encode([
            'title' => $title !== '' ? $title : "Let's Classify",
            'instructions' => $instructions,
            'cefr' => $cefr,
            'label_mode' => $labelMode,
            'categories' => $cleanCats,
            'items' => $cleanItems,
        ], JSON_UNESCAPED_UNICODE);

        if ($activityId !== '') {
            $stmt = $pdo->prepare("UPDATE activities SET data = :data WHERE id = :id AND type = 'lets_classify'");
            $stmt->execute(['data' => $payload, 'id' => $activityId]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO activities (unit_id, type, data, position, created_at)
                VALUES (:unit, 'lets_classify', :data, (SELECT COALESCE(MAX(position), 0) + 1 FROM activities WHERE unit_id = :unit2), CURRENT_TIMESTAMP)
                RETURNING id");
            $stmt->execute(['unit' => $unit, 'unit2' => $unit, 'data' => $payload]);
            $activityId = (string)$stmt->fetchColumn();
        }
        $saved = true;
        $activity = json_decode($payload, true);
    }
}

$activity = $activity ?? [
    'title' => '',
    'instructions' => '',
    'cefr' => 'A1',
    'label_mode' => 'both',
    'categories' => [],
    'items' => [],
];

if (!$saved && $activityId !== '') {
    $stmt = $pdo->prepare("SELECT data FROM activities WHERE id = :id AND type = 'lets_classify' LIMIT 1");
    $stmt->execute(['id' => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['data'])) {
        $decoded = json_decode($row['data'], true);
        if (is_array($decoded)) $activity = array_merge($activity, $decoded);
    }
}

ob_start();
?>
<style>
.lc-ed-wrap{display:flex;flex-direction:column;gap:22px}.lc-section{background:#fff;border:1px solid #EDE9FA;border-radius:16px;overflow:hidden}.lc-section-head{padding:13px 18px;background:#F9F8FF;border-bottom:1px solid #EDE9FA;display:flex;align-items:center;justify-content:space-between;gap:10px}.lc-section-head h3{margin:0;font-size:13px;font-weight:800;color:#F97316;text-transform:uppercase;letter-spacing:.04em;display:flex;align-items:center;gap:7px}.lc-section-body{padding:18px}.lc-field-row{display:flex;align-items:center;gap:12px;margin-bottom:12px}.lc-field-row:last-child{margin-bottom:0}.lc-label{width:110px;flex-shrink:0;font-size:12px;font-weight:700;color:#7F77DD}.lc-input,.lc-select,.lc-textarea{border:1.5px solid #EDE9FA;border-radius:8px;padding:7px 11px;font-family:inherit;font-size:13px;color:#1E1B3A;background:#fff;flex:1;transition:border-color .15s}.lc-input:focus,.lc-select:focus,.lc-textarea:focus{outline:none;border-color:#7F77DD}.lc-textarea{resize:vertical;min-height:60px}.lc-select{flex:0 0 auto}.lc-cats-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-bottom:14px;max-width:560px}.lc-cat-card{border:1.5px solid #EDE9FA;border-radius:14px;padding:12px;background:#FAFAFE;display:flex;flex-direction:column;gap:8px;min-width:0}.lc-cat-top{display:flex;align-items:center;gap:7px}.lc-cat-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0;background:#EDE9FA}.lc-cat-name{flex:1;min-width:0;border:none;border-bottom:1.5px dashed #EDE9FA;background:transparent;font-size:14px;font-weight:700;color:#1E1B3A;font-family:inherit;padding:2px 0}.lc-cat-name:focus{outline:none;border-color:#7F77DD}.lc-cat-card.lc-cat-error{border-color:#fca5a5;background:#fff5f5}.lc-cat-del{width:26px;height:26px;display:inline-flex;align-items:center;justify-content:center;background:#FFF0E8;border:1px solid #FFD8C2;border-radius:999px;cursor:pointer;color:#D85A30;font-size:13px;line-height:1;padding:0;flex-shrink:0}.lc-cat-del:disabled{opacity:.45;cursor:not-allowed}.lc-inline-alert{display:none;padding:12px 16px;border-radius:10px;font-size:14px;font-weight:600;margin-bottom:16px;background:#fee2e2;color:#991b1b;border:1px solid #fca5a5}.lc-img-preview{width:100%;height:74px;border-radius:10px;border:1.5px dashed #EDE9FA;background:#F5F3FF;display:flex;align-items:center;justify-content:center;font-size:12px;color:#9B8FCC;gap:5px;cursor:pointer;overflow:hidden;position:relative}.lc-img-preview img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;border-radius:8px}.lc-img-preview.has-img{border-style:solid;border-color:#c5bff5}.lc-img-overlay{position:absolute;inset:0;background:rgba(127,119,221,.55);display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity .15s;border-radius:8px;font-size:11px;color:#fff;font-weight:700;gap:4px}.lc-img-preview:hover .lc-img-overlay{opacity:1}.lc-file-hidden{display:none}.lc-img-label{font-size:11px;color:#9B8FCC;text-align:center}.lc-cat-help{color:#9B8FCC;font-size:12px;font-weight:700;margin:0 0 10px}.lc-items-table{width:100%;border-collapse:collapse}.lc-items-table th{background:#F9F8FF;font-size:11px;font-weight:800;color:#9B8FCC;text-transform:uppercase;letter-spacing:.04em;padding:8px 10px;border-bottom:1.5px solid #EDE9FA;text-align:left}.lc-items-table td{padding:7px 10px;border-bottom:1px solid #F5F3FF;vertical-align:middle}.lc-drag-col{width:28px;color:#D0CDF0;cursor:grab;font-size:16px;text-align:center}.lc-thumb-wrap{width:40px;height:40px;border-radius:8px;border:1.5px dashed #EDE9FA;background:#F5F3FF;display:flex;align-items:center;justify-content:center;cursor:pointer;overflow:hidden;position:relative;flex-shrink:0}.lc-thumb-wrap img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;border-radius:6px}.lc-thumb-wrap.has-img{border-style:solid;border-color:#c5bff5}.lc-thumb-overlay{position:absolute;inset:0;background:rgba(127,119,221,.55);display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity .15s;border-radius:6px}.lc-thumb-wrap:hover .lc-thumb-overlay{opacity:1}.lc-item-name-in{border:1.5px solid #EDE9FA;border-radius:7px;padding:5px 9px;font-size:13px;font-family:inherit;color:#1E1B3A;background:#fff;width:100%;transition:border-color .15s}.lc-item-name-in:focus{outline:none;border-color:#7F77DD}.lc-cat-sel{border:1.5px solid #EDE9FA;border-radius:7px;padding:5px 8px;font-size:12px;font-family:inherit;color:#1E1B3A;background:#fff;width:100%}.lc-audio-btn{display:inline-flex;align-items:center;gap:5px;border:1.5px solid #EDE9FA;background:#fff;border-radius:7px;padding:5px 10px;font-size:11px;font-weight:700;color:#9B8FCC;cursor:pointer;white-space:nowrap;font-family:inherit}.lc-audio-btn.ready{border-color:#9FE1CB;color:#0F6E56;background:#E1F5EE}.lc-del-btn{background:none;border:none;cursor:pointer;color:#F0997B;font-size:15px;padding:3px 5px}.lc-add-btn{display:inline-flex;align-items:center;gap:6px;border:1.5px dashed #EDE9FA;background:none;border-radius:8px;padding:8px 16px;font-size:13px;font-weight:700;color:#9B8FCC;cursor:pointer;font-family:inherit;width:100%;justify-content:center;margin-top:8px}.lc-add-btn:disabled{opacity:.45;cursor:not-allowed}.lc-csv-btn{display:inline-flex;align-items:center;gap:6px;border:1.5px solid #EDE9FA;background:#fff;border-radius:8px;padding:6px 12px;font-size:12px;font-weight:700;color:#9B8FCC;cursor:pointer;font-family:inherit}.lc-alert{padding:12px 16px;border-radius:10px;font-size:14px;font-weight:600;margin-bottom:16px}.lc-alert-error{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5}.lc-alert-success{background:#dcfce7;color:#14532d;border:1px solid #86efac}.lc-footer{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.lc-save-btn{display:inline-flex;align-items:center;gap:7px;padding:11px 28px;border:none;border-radius:10px;background:#F97316;color:#fff;font-weight:800;font-size:15px;cursor:pointer;font-family:inherit}.lc-preview-btn{display:inline-flex;align-items:center;gap:7px;padding:10px 22px;border:none;border-radius:10px;background:#7F77DD;color:#fff;font-weight:700;font-size:14px;cursor:pointer;font-family:inherit;text-decoration:none}.lc-preview-btn:hover{color:#fff}.lc-status-badge{font-size:12px;color:#9B8FCC;background:#F5F3FF;border:1px solid #EDE9FA;border-radius:20px;padding:5px 14px}.lc-items-table tr.dragging-row{opacity:.4}.lc-items-table tr.drag-over-row td{border-top:2px solid #7F77DD}@media(max-width:680px){.lc-field-row{align-items:flex-start;flex-direction:column}.lc-label{width:auto}.lc-cats-grid{grid-template-columns:1fr;max-width:none}.lc-items-table{font-size:12px}.lc-items-table th,.lc-items-table td{padding:6px}}
</style>

<?php if ($error !== ''): ?><div class="lc-alert lc-alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<?php if ($saved): ?><div class="lc-alert lc-alert-success">✔ Activity saved successfully!</div><?php endif; ?>
<div class="lc-inline-alert" id="lcInlineAlert"></div>

<form method="POST" enctype="multipart/form-data" id="lcForm">
<input type="hidden" name="categories_json" id="lcCatsJson" value="">
<input type="hidden" name="items_json" id="lcItemsJson" value="">
<div class="lc-ed-wrap">
    <div class="lc-section"><div class="lc-section-head"><h3><i class="fas fa-sliders-h"></i> General settings</h3></div><div class="lc-section-body">
        <div class="lc-field-row"><span class="lc-label">Activity title</span><input class="lc-input" type="text" name="activity_title" value="<?= htmlspecialchars($activity['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="e.g. Let's Classify — Transportation"></div>
        <div class="lc-field-row"><span class="lc-label">Instructions</span><textarea class="lc-textarea" name="activity_instructions" placeholder="Drag each item to the correct category."><?= htmlspecialchars($activity['instructions'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea></div>
        <div class="lc-field-row"><span class="lc-label">CEFR level</span><select class="lc-select" name="cefr" style="width:90px;"><?php foreach(['A1','A2','B1','B2','C1','C2'] as $lvl): ?><option value="<?= $lvl ?>"<?= ($activity['cefr'] ?? 'A1') === $lvl ? ' selected' : '' ?>><?= $lvl ?></option><?php endforeach; ?></select><span class="lc-label" style="width:90px;margin-left:16px;">Show labels</span><select class="lc-select" name="label_mode" style="width:160px;"><option value="both"<?= ($activity['label_mode'] ?? 'both')==='both'?' selected':'' ?>>Name + image</option><option value="image"<?= ($activity['label_mode'] ?? '')==='image'?' selected':'' ?>>Image only</option><option value="name"<?= ($activity['label_mode'] ?? '')==='name'?' selected':'' ?>>Name only</option></select></div>
    </div></div>

    <div class="lc-section"><div class="lc-section-head"><h3><i class="fas fa-th-large"></i> Categories <span id="lcCatCount" style="font-size:11px;font-weight:400;color:#9B8FCC;margin-left:4px;"></span></h3><button type="button" class="lc-add-btn" id="lcAddCat" style="width:auto;margin:0;padding:5px 14px;font-size:12px;"><i class="fas fa-plus"></i> Add pair</button></div><div class="lc-section-body"><div class="lc-cat-help">Categories are organized in pairs: 2 = one row, 4 = two rows, 6 = three rows.</div><div class="lc-cats-grid" id="lcCatsGrid"></div></div></div>

    <div class="lc-section"><div class="lc-section-head"><h3><i class="fas fa-images"></i> Items <span id="lcItemCount" style="font-size:11px;font-weight:400;color:#9B8FCC;margin-left:4px;"></span></h3><button type="button" class="lc-csv-btn" id="lcCsvBtn"><i class="fas fa-file-csv"></i> Bulk import CSV</button><input type="file" id="lcCsvFile" accept=".csv" class="lc-file-hidden"></div><div class="lc-section-body" style="padding:0;"><table class="lc-items-table"><thead><tr><th class="lc-drag-col"></th><th style="width:50px;">Image</th><th>Name</th><th style="width:150px;">Category</th><th style="width:110px;">Audio</th><th style="width:36px;"></th></tr></thead><tbody id="lcItemsTbody"></tbody></table><div style="padding:10px 14px;"><button type="button" class="lc-add-btn" id="lcAddItem"><i class="fas fa-plus"></i> Add item</button></div></div></div>

    <div class="lc-footer"><span class="lc-status-badge" id="lcStatusBadge">0 items · 0 categories</span><div style="display:flex;gap:10px;align-items:center;"><?php if ($activityId !== ''): ?><a class="lc-preview-btn" href="viewer.php?id=<?= urlencode($activityId) ?>&unit=<?= urlencode($unit) ?>" target="_blank"><i class="fas fa-eye"></i> Preview</a><?php endif; ?><button type="submit" class="lc-save-btn"><i class="fas fa-save"></i> Save activity</button></div></div>
</div>
</form>

<script>
const INIT_CATS = <?= json_encode(array_values($activity['categories'] ?? []), JSON_UNESCAPED_UNICODE) ?>;
const INIT_ITEMS = <?= json_encode(array_values($activity['items'] ?? []), JSON_UNESCAPED_UNICODE) ?>;
const MAX_CATS = 6, MIN_CATS = 2;
let cats = JSON.parse(JSON.stringify(INIT_CATS));
let items = JSON.parse(JSON.stringify(INIT_ITEMS));
let nextCatId = cats.length ? Math.max(...cats.map(c => parseInt(c.id) || 0)) + 1 : 1;
let nextItemId = items.length ? Math.max(...items.map(i => parseInt(i.id) || 0)) + 1 : 1;
const pendingFiles = {};
const DOT_COLORS = ['#F97316','#378ADD','#3B6D11','#D4537E','#7F77DD','#0EA5E9'];
const catsGrid = document.getElementById('lcCatsGrid');
const tbody = document.getElementById('lcItemsTbody');
const addCatBtn = document.getElementById('lcAddCat');

function ensureInitialCategories(){ if(!Array.isArray(cats)) cats=[]; while(cats.length < 2) cats.push({id:nextCatId++,name:'',image:''}); }
function showInlineAlert(message){ const el=document.getElementById('lcInlineAlert'); el.textContent=message; el.style.display='block'; el.scrollIntoView({behavior:'smooth',block:'nearest'}); }
function hideInlineAlert(){ const el=document.getElementById('lcInlineAlert'); el.style.display='none'; el.textContent=''; }
function itemHasContent(item){ return Boolean((item.name || '').trim() || (item.image || '').trim() || item._preview || pendingFiles['item_' + item.id]); }
function cleanForJson(list){ return list.map(({_preview, ...rest}) => rest); }

function restoreFileInput(input, key){ if(!pendingFiles[key]) return; try{ const dt=new DataTransfer(); dt.items.add(pendingFiles[key]); input.files=dt.files; }catch(e){} }

function buildImagePicker(kind, obj){
    const key = kind + '_' + obj.id;
    const fileInput = document.createElement('input');
    fileInput.type = 'file';
    fileInput.accept = 'image/*';
    fileInput.name = kind + '_image_' + obj.id;
    fileInput.className = 'lc-file-hidden';
    restoreFileInput(fileInput, key);

    const src = obj._preview || obj.image || '';
    const preview = document.createElement('div');
    preview.className = (kind === 'cat' ? 'lc-img-preview' : 'lc-thumb-wrap') + (src ? ' has-img' : '');
    if (src) { const img=document.createElement('img'); img.src=src; preview.appendChild(img); }
    const overlay = document.createElement('div');
    overlay.className = kind === 'cat' ? 'lc-img-overlay' : 'lc-thumb-overlay';
    overlay.innerHTML = kind === 'cat' ? '<i class="fas fa-camera"></i> Change' : '<i class="fas fa-camera" style="color:#fff;font-size:12px;"></i>';
    preview.appendChild(overlay);
    if (!src && kind === 'cat') { const lbl=document.createElement('span'); lbl.className='lc-img-label'; lbl.innerHTML='<i class="fas fa-upload"></i> Upload image'; preview.appendChild(lbl); }
    preview.addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', function(){ const file=this.files[0]; if(!file) return; pendingFiles[key]=file; const reader=new FileReader(); reader.onload=e=>{ obj._preview=e.target.result; kind === 'cat' ? renderCats() : renderItems(); }; reader.readAsDataURL(file); });
    return {fileInput, preview};
}

function renderCats(){
    catsGrid.innerHTML = '';
    cats.forEach((c, idx) => {
        const card = document.createElement('div'); card.className='lc-cat-card'; card.dataset.id=c.id;
        const top = document.createElement('div'); top.className='lc-cat-top';
        const dot = document.createElement('div'); dot.className='lc-cat-dot'; dot.style.background=DOT_COLORS[idx % DOT_COLORS.length];
        const nameInp = document.createElement('input'); nameInp.className='lc-cat-name'; nameInp.type='text'; nameInp.value=c.name||''; nameInp.placeholder='Category name';
        nameInp.addEventListener('input',()=>{ c.name=nameInp.value.trim(); card.classList.remove('lc-cat-error'); renderItems(); updateStatus(); });
        const del = document.createElement('button'); del.type='button'; del.className='lc-cat-del'; del.innerHTML='<i class="fas fa-trash"></i>'; del.disabled=cats.length<=MIN_CATS;
        del.addEventListener('click',()=>{ if(cats.length<=MIN_CATS){showInlineAlert('At least 2 categories are required.'); return;} delete pendingFiles['cat_'+c.id]; items.forEach(item=>{ if(parseInt(item.category_id)===parseInt(c.id)) item.category_id=0; }); cats=cats.filter(x=>parseInt(x.id)!==parseInt(c.id)); renderCats(); });
        top.append(dot,nameInp,del);
        const picker = buildImagePicker('cat', c);
        card.append(top,picker.fileInput,picker.preview);
        catsGrid.appendChild(card);
    });
    renderItems(); updateStatus();
}

addCatBtn.addEventListener('click',()=>{ hideInlineAlert(); if(cats.length>=MAX_CATS){showInlineAlert('Maximum 6 categories.'); return;} cats.push({id:nextCatId++,name:'',image:''},{id:nextCatId++,name:'',image:''}); if(cats.length>MAX_CATS) cats=cats.slice(0,MAX_CATS); renderCats(); });

function renderItems(){
    tbody.innerHTML='';
    items.forEach(item=>{
        const tr=document.createElement('tr'); tr.dataset.id=item.id;
        const tdDrag=document.createElement('td'); tdDrag.className='lc-drag-col'; tdDrag.innerHTML='<i class="fas fa-grip-vertical"></i>'; tdDrag.draggable=true; setupRowDrag(tr, tdDrag);
        const tdThumb=document.createElement('td'); const picker=buildImagePicker('item', item); tdThumb.append(picker.fileInput,picker.preview);
        const tdName=document.createElement('td'); const nameInp=document.createElement('input'); nameInp.type='text'; nameInp.className='lc-item-name-in'; nameInp.value=item.name||''; nameInp.placeholder='Item name...'; nameInp.addEventListener('input',()=>{ item.name=nameInp.value.trim(); updateStatus(); }); tdName.appendChild(nameInp);
        const tdCat=document.createElement('td'); const catSel=document.createElement('select'); catSel.className='lc-cat-sel'; catSel.innerHTML='<option value="0">— select —</option>'; cats.forEach(c=>{ const opt=document.createElement('option'); opt.value=c.id; opt.textContent=c.name||'(unnamed)'; if(parseInt(c.id)===parseInt(item.category_id)) opt.selected=true; catSel.appendChild(opt); }); catSel.addEventListener('change',()=>{ item.category_id=parseInt(catSel.value)||0; }); tdCat.appendChild(catSel);
        const tdAudio=document.createElement('td'); const audioBtn=document.createElement('button'); audioBtn.type='button'; audioBtn.className='lc-audio-btn'+(item.audio?' ready':''); audioBtn.innerHTML=item.audio?'<i class="fas fa-check"></i> Ready':'<i class="fas fa-volume-up"></i> Generate'; audioBtn.addEventListener('click',()=>generateAudio(item,audioBtn)); tdAudio.appendChild(audioBtn);
        const tdDel=document.createElement('td'); const delBtn=document.createElement('button'); delBtn.type='button'; delBtn.className='lc-del-btn'; delBtn.innerHTML='<i class="fas fa-trash"></i>'; delBtn.addEventListener('click',()=>{ delete pendingFiles['item_'+item.id]; items=items.filter(x=>parseInt(x.id)!==parseInt(item.id)); renderItems(); updateStatus(); }); tdDel.appendChild(delBtn);
        tr.append(tdDrag,tdThumb,tdName,tdCat,tdAudio,tdDel); tbody.appendChild(tr);
    });
    updateStatus();
}

document.getElementById('lcAddItem').addEventListener('click',()=>{ items.push({id:nextItemId++,name:'',image:'',audio:'',category_id:cats.length?cats[0].id:0}); renderItems(); tbody.lastElementChild?.querySelector('.lc-item-name-in')?.focus(); });

async function generateAudio(item,btn){ if(!item.name){alert('Enter a name first.'); return;} btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Generating...'; try{ const fd=new FormData(); fd.append('text',item.name); fd.append('voice_id','nzFihrBIvB34imQBuxub'); const res=await fetch('tts.php',{method:'POST',body:fd,credentials:'same-origin'}); const data=await res.json(); if(data.url){item.audio=data.url; btn.className='lc-audio-btn ready'; btn.innerHTML='<i class="fas fa-check"></i> Ready';}else throw new Error(data.error||'TTS failed'); }catch(e){btn.className='lc-audio-btn'; btn.innerHTML='<i class="fas fa-volume-up"></i> Generate'; alert('Audio generation failed: '+e.message);} finally{btn.disabled=false;} }

document.getElementById('lcCsvBtn').addEventListener('click',()=>document.getElementById('lcCsvFile').click());
document.getElementById('lcCsvFile').addEventListener('change',function(){ const file=this.files[0]; if(!file) return; const reader=new FileReader(); reader.onload=e=>{ e.target.result.split('\n').forEach(line=>{ const parts=line.split(','); const name=(parts[0]||'').trim().replace(/^"|"$/g,''); const catName=(parts[1]||'').trim().replace(/^"|"$/g,''); if(!name) return; const matchCat=cats.find(c=>(c.name||'').toLowerCase()===catName.toLowerCase()); items.push({id:nextItemId++,name,image:'',audio:'',category_id:matchCat?matchCat.id:(cats.length?cats[0].id:0)}); }); renderItems(); }; reader.readAsText(file); this.value=''; });

let dragSrcRow=null;
function setupRowDrag(tr,handle){ handle.addEventListener('mousedown',()=>{tr.draggable=true;}); tr.addEventListener('dragstart',e=>{dragSrcRow=tr;tr.classList.add('dragging-row');e.dataTransfer.effectAllowed='move';}); tr.addEventListener('dragend',()=>{tr.draggable=false;tr.classList.remove('dragging-row');tbody.querySelectorAll('tr').forEach(r=>r.classList.remove('drag-over-row')); const newOrder=[]; tbody.querySelectorAll('tr').forEach(r=>{const found=items.find(i=>parseInt(i.id)===parseInt(r.dataset.id)); if(found)newOrder.push(found);}); items=newOrder;}); tr.addEventListener('dragover',e=>{e.preventDefault(); if(dragSrcRow&&dragSrcRow!==tr){tbody.querySelectorAll('tr').forEach(r=>r.classList.remove('drag-over-row')); tr.classList.add('drag-over-row');}}); tr.addEventListener('drop',e=>{e.preventDefault(); if(dragSrcRow&&dragSrcRow!==tr){const rows=[...tbody.querySelectorAll('tr')]; const src=rows.indexOf(dragSrcRow); const tgt=rows.indexOf(tr); if(src<tgt) tr.parentNode.insertBefore(dragSrcRow,tr.nextSibling); else tr.parentNode.insertBefore(dragSrcRow,tr);}}); }

function syncFromDom(){
    tbody.querySelectorAll('tr').forEach(tr=>{ const id=parseInt(tr.dataset.id); const item=items.find(i=>parseInt(i.id)===id); if(!item)return; const inp=tr.querySelector('.lc-item-name-in'); if(inp)item.name=inp.value.trim(); const sel=tr.querySelector('.lc-cat-sel'); if(sel)item.category_id=parseInt(sel.value)||0; });
    catsGrid.querySelectorAll('.lc-cat-card').forEach(card=>{ const id=parseInt(card.dataset.id); const cat=cats.find(c=>parseInt(c.id)===id); if(!cat)return; const inp=card.querySelector('.lc-cat-name'); if(inp)cat.name=inp.value.trim(); card.classList.remove('lc-cat-error'); });
}

function updateStatus(){ const cefr=document.querySelector('[name="cefr"]')?.value||''; document.getElementById('lcStatusBadge').textContent=items.length+' items · '+cats.length+' categories'+(cefr?' · '+cefr:''); document.getElementById('lcItemCount').textContent='('+items.length+')'; document.getElementById('lcCatCount').textContent='('+cats.length+'/6 · pairs)'; addCatBtn.disabled=cats.length>=MAX_CATS; }

document.getElementById('lcForm').addEventListener('submit',function(e){
    syncFromDom(); hideInlineAlert(); let errors=[];
    const validCats=cats.filter(c=>(c.name||'').trim()!=='');
    const missingName=cats.filter(c=>(c.name||'').trim()==='');
    if(missingName.length){ missingName.forEach(c=>{ const card=catsGrid.querySelector('.lc-cat-card[data-id="'+c.id+'"]'); if(card)card.classList.add('lc-cat-error'); }); errors.push('Each category needs a name.'); }
    if(cats.length<MIN_CATS) errors.push('Add at least 2 categories.');
    if(cats.length>MAX_CATS) errors.push('Use a maximum of 6 categories.');
    if(cats.length%2!==0) errors.push('Categories must stay in pairs: use 2, 4, or 6 categories.');
    const validCatIds=validCats.map(c=>parseInt(c.id));
    const validItems=items.filter(itemHasContent);
    if(validItems.length<2) errors.push('Add at least 2 items. Items can have a name, an image, or both.');
    if(validItems.some(i=>!validCatIds.includes(parseInt(i.category_id)))) errors.push('Assign every item to one of the current categories.');
    if(errors.length){ e.preventDefault(); showInlineAlert(errors[0]); return; }
    document.getElementById('lcCatsJson').value=JSON.stringify(cleanForJson(cats));
    document.getElementById('lcItemsJson').value=JSON.stringify(cleanForJson(validItems));
});

ensureInitialCategories(); renderCats(); renderItems();
</script>
<?php
$content = ob_get_clean();
render_activity_editor("Let's Classify — Editor", 'fas fa-layer-group', $content);
