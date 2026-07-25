<?php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_editor_template.php';
require_once __DIR__ . '/../../core/cloudinary_upload.php';

if (!empty($_SESSION['student_logged'])) {
    header('Location: /lessons/lessons/academic/student_dashboard.php?error=access_denied');
    exit;
}
if (empty($_SESSION['academic_logged']) && empty($_SESSION['admin_logged'])) {
    header('Location: /lessons/lessons/academic/login.php');
    exit;
}

$activityId = trim((string)($_GET['id'] ?? ''));
$unit       = trim((string)($_GET['unit'] ?? ''));

if ($unit === '' && $activityId !== '') {
    $stmt = $pdo->prepare('SELECT unit_id FROM activities WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $unit = $row ? (string)($row['unit_id'] ?? '') : '';
}
if ($unit === '') die('Unit not specified');

function ddpe_h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function ddpe_clean_item(array $item): ?array {
    $id = (int)($item['id'] ?? 0);
    $picUrl = trim((string)($item['pic_url'] ?? ''));
    if ($id <= 0 || $picUrl === '') return null;

    return [
        'id'      => $id,
        'pic_url' => $picUrl,
        'label'   => trim((string)($item['label'] ?? '')),
        'x'       => max(0, min(96, round((float)($item['x'] ?? 10), 4))),
        'y'       => max(0, min(96, round((float)($item['y'] ?? 10), 4))),
        'w'       => max(4, min(60, round((float)($item['w'] ?? 14), 4))),
        'h'       => max(4, min(60, round((float)($item['h'] ?? 12), 4))),
        'rot'     => ((int)($item['rot'] ?? 0) % 360 + 360) % 360,
        'flipH'   => !empty($item['flipH']),
        'layer'   => max(0, (int)($item['layer'] ?? 0)),
    ];
}

$error = '';
$saved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_pic_item') {
    header('Content-Type: application/json; charset=utf-8');
    if (empty($_FILES['image']['tmp_name']) || !is_uploaded_file($_FILES['image']['tmp_name'])) {
        echo json_encode(['error' => 'No file received']);
        exit;
    }
    $url = upload_to_cloudinary($_FILES['image']['tmp_name']);
    echo $url ? json_encode(['url' => $url]) : json_encode(['error' => 'Upload failed']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title        = trim((string)($_POST['activity_title'] ?? ''));
    $instructions = trim((string)($_POST['activity_instructions'] ?? ''));
    $background   = trim((string)($_POST['bg_image_existing'] ?? ''));

    if (!empty($_FILES['bg_image']['tmp_name']) && is_uploaded_file($_FILES['bg_image']['tmp_name'])) {
        $uploaded = upload_to_cloudinary($_FILES['bg_image']['tmp_name']);
        if ($uploaded) $background = $uploaded;
    }

    $rawItems = json_decode((string)($_POST['items_json'] ?? '[]'), true);
    if (!is_array($rawItems)) $rawItems = [];

    $items = [];
    foreach ($rawItems as $index => $rawItem) {
        if (!is_array($rawItem)) continue;
        if (!isset($rawItem['layer'])) $rawItem['layer'] = $index;
        $clean = ddpe_clean_item($rawItem);
        if ($clean) $items[] = $clean;
    }

    usort($items, static fn(array $a, array $b): int => $a['layer'] <=> $b['layer']);
    foreach ($items as $index => &$item) $item['layer'] = $index;
    unset($item);

    if ($background === '') {
        $error = 'Please upload a background image.';
    } elseif (!$items) {
        $error = 'Add at least one picture zone and upload a picture for it.';
    } else {
        $payload = json_encode([
            'title'            => $title !== '' ? $title : 'Drag & Drop Picture',
            'instructions'     => $instructions,
            'background_image' => $background,
            'items'            => $items,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($activityId !== '') {
            $stmt = $pdo->prepare("UPDATE activities SET data = :data WHERE id = :id AND type = 'dragdrop_pic'");
            $stmt->execute(['data' => $payload, 'id' => $activityId]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO activities (unit_id, type, data) VALUES (:unit, 'dragdrop_pic', :data)");
            $stmt->execute(['unit' => $unit, 'data' => $payload]);
            $activityId = (string)$pdo->lastInsertId();
        }
        $saved = true;
    }
}

$activity = ['title'=>'','instructions'=>'','background_image'=>'','items'=>[]];
if ($activityId !== '') {
    $stmt = $pdo->prepare("SELECT data FROM activities WHERE id = :id AND type = 'dragdrop_pic' LIMIT 1");
    $stmt->execute(['id' => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['data'])) {
        $decoded = json_decode($row['data'], true);
        if (is_array($decoded)) $activity = array_merge($activity, $decoded);
    }
}

$initialItems = [];
foreach (($activity['items'] ?? []) as $index => $item) {
    if (!is_array($item)) continue;
    if (!isset($item['layer'])) $item['layer'] = $index;
    $clean = ddpe_clean_item($item);
    if ($clean) $initialItems[] = $clean;
}
usort($initialItems, static fn(array $a, array $b): int => $a['layer'] <=> $b['layer']);
foreach ($initialItems as $index => &$item) $item['layer'] = $index;
unset($item);

ob_start();
?>
<style>
.ddpe-grid{display:grid;grid-template-columns:minmax(280px,330px) minmax(0,1fr);gap:18px;align-items:start}@media(max-width:900px){.ddpe-grid{grid-template-columns:1fr}}
.ddpe-card,.ddpe-canvas-card{background:#fff;border:1px solid #e4e1f4;border-radius:18px;padding:16px;box-shadow:0 6px 18px rgba(31,41,55,.06)}.ddpe-side{display:flex;flex-direction:column;gap:14px}
.ddpe-card label{display:block;margin:0 0 6px;font-size:12px;font-weight:800;color:#6d64c6;text-transform:uppercase;letter-spacing:.04em}.ddpe-card input[type=text],.ddpe-card textarea{width:100%;box-sizing:border-box;border:1px solid #cfc9ee;border-radius:11px;padding:10px 12px;font:inherit;background:#fff}.ddpe-card textarea{min-height:82px;resize:vertical}.ddpe-card input[type=file]{width:100%}.ddpe-note{font-size:12px;line-height:1.45;color:#64748b;margin:8px 0 0}
.ddpe-list{list-style:none;padding:0;margin:10px 0 0;display:flex;flex-direction:column;gap:9px}.ddpe-item{display:grid;grid-template-columns:24px 52px minmax(0,1fr) 30px;gap:8px;align-items:center;padding:9px;border:1px solid #e2e8f0;border-radius:13px;background:#f8fafc}.ddpe-badge{width:24px;height:24px;border-radius:50%;display:grid;place-items:center;background:#7F77DD;color:#fff;font-size:11px;font-weight:800}.ddpe-thumb{width:52px;height:52px;border-radius:8px;border:1px solid #e2e8f0;object-fit:contain;background:#fff}.ddpe-thumb.placeholder{display:grid;place-items:center;font-size:22px;color:#94a3b8}.ddpe-item-main{display:flex;flex-direction:column;gap:5px;min-width:0}.ddpe-item-main input[type=text]{border:0;background:transparent;padding:0;font-size:13px;font-weight:700;outline:none}.ddpe-item-main input[type=file]{font-size:11px}.ddpe-actions{display:flex;flex-wrap:wrap;gap:4px}.ddpe-mini{border:1px solid #cfc9ee;background:#fff;color:#5b53aa;border-radius:8px;padding:4px 7px;font-size:11px;font-weight:800;cursor:pointer}.ddpe-mini:disabled{opacity:.45;cursor:not-allowed}.ddpe-delete{border:0;background:transparent;color:#dc2626;font-size:22px;cursor:pointer}.ddpe-empty-list{font-size:12px;color:#64748b;margin:8px 0 0}
.ddpe-canvas-card{padding:14px}.ddpe-canvas-title{font-size:12px;font-weight:800;color:#6d64c6;text-transform:uppercase;letter-spacing:.04em;margin:0 0 10px}.ddpe-canvas-wrap{position:relative;min-height:260px;border:2px dashed #cfc9ee;border-radius:16px;background:#f8fafc;overflow:hidden;display:flex;align-items:center;justify-content:center}.ddpe-canvas-wrap.has-image{border-style:solid}#ddpeEdBg{display:block;max-width:100%;height:auto;pointer-events:none;user-select:none;-webkit-user-drag:none}.ddpe-overlay{position:absolute;z-index:5;touch-action:none}.ddpe-empty{padding:48px 20px;text-align:center;color:#64748b;font-weight:700;pointer-events:none}
.ddpe-zone{position:absolute;box-sizing:border-box;border:2px solid #7F77DD;border-radius:9px;background:rgba(127,119,221,.14);cursor:grab;touch-action:none;overflow:visible}.ddpe-zone.selected{border-color:#F97316;background:rgba(249,115,22,.16);z-index:999!important}.ddpe-zone img{width:100%;height:100%;object-fit:contain;display:block;pointer-events:none;user-select:none;transform-origin:center}.ddpe-placeholder{width:100%;height:100%;display:grid;place-items:center;font-size:28px;color:#7F77DD;pointer-events:none}.ddpe-zone-number{position:absolute;left:-9px;top:-9px;width:21px;height:21px;border-radius:50%;display:grid;place-items:center;background:#7F77DD;color:#fff;font-size:11px;font-weight:800;pointer-events:none}.ddpe-resize{position:absolute;right:-7px;bottom:-7px;width:15px;height:15px;border-radius:4px;background:#F97316;cursor:nwse-resize;touch-action:none}.ddpe-save{display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:12px;padding:12px 26px;background:#16a34a;color:#fff;font-weight:800;font-size:15px;cursor:pointer}.ddpe-alert{border-radius:12px;padding:10px 12px;margin-bottom:12px;font-weight:700}.ddpe-alert.error{background:#fee2e2;color:#991b1b}.ddpe-alert.success{background:#dcfce7;color:#166534}
</style>
<?php if ($error !== ''): ?><div class="ddpe-alert error"><?= ddpe_h($error) ?></div><?php endif; ?>
<?php if ($saved): ?><div class="ddpe-alert success">Activity saved successfully.</div><?php endif; ?>
<form id="ddpeForm" method="post" enctype="multipart/form-data">
<input type="hidden" name="items_json" id="itemsJsonInput"><input type="hidden" name="bg_image_existing" id="bgImageExisting" value="<?= ddpe_h((string)($activity['background_image'] ?? '')) ?>">
<div class="ddpe-grid"><aside class="ddpe-side">
<section class="ddpe-card"><label for="activityTitle">Title</label><input id="activityTitle" type="text" name="activity_title" value="<?= ddpe_h((string)($activity['title'] ?? '')) ?>" placeholder="Drag & Drop Picture"><label for="activityInstructions" style="margin-top:12px">Instructions</label><textarea id="activityInstructions" name="activity_instructions" placeholder="Drag each picture to the correct place."><?= ddpe_h((string)($activity['instructions'] ?? '')) ?></textarea></section>
<section class="ddpe-card"><label for="bgImageFile">Background image</label><input id="bgImageFile" type="file" name="bg_image" accept="image/*"><p class="ddpe-note">Upload a scene, then tap or click the scene to add zones. Drag zones to move them and use the orange corner to resize.</p></section>
<section class="ddpe-card"><label>Picture zones and layer order</label><ul class="ddpe-list" id="itemList"></ul><p id="noItemsHint" class="ddpe-empty-list">No zones yet.</p></section><button class="ddpe-save" type="submit">Save Activity</button></aside>
<section class="ddpe-canvas-card"><div class="ddpe-canvas-title">Scene editor</div><div class="ddpe-canvas-wrap" id="ddpeCanvasWrap"><div class="ddpe-empty" id="ddpeEmptyHint">Upload a background image to begin.</div><img id="ddpeEdBg" alt="Background preview" style="display:none"><div class="ddpe-overlay" id="ddpeOverlay"></div></div></section></div></form>
<script>
(function(){'use strict';
let items=<?= json_encode($initialItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;let nextId=items.reduce((m,it)=>Math.max(m,Number(it.id)||0),0)+1;let pendingUploads=0;let selectedId=null;
const wrap=document.getElementById('ddpeCanvasWrap'),overlay=document.getElementById('ddpeOverlay'),bgImg=document.getElementById('ddpeEdBg'),emptyHint=document.getElementById('ddpeEmptyHint'),itemList=document.getElementById('itemList'),noItemsHint=document.getElementById('noItemsHint'),itemsInput=document.getElementById('itemsJsonInput'),bgExisting=document.getElementById('bgImageExisting'),bgFile=document.getElementById('bgImageFile'),form=document.getElementById('ddpeForm');
function transformFor(item){return `rotate(${Number(item.rot)||0}deg) scaleX(${item.flipH?-1:1})`}function normalizeLayers(){items.forEach((item,index)=>item.layer=index)}
function syncOverlay(){if(!bgImg.complete||!bgImg.naturalWidth)return;const wr=wrap.getBoundingClientRect(),ir=bgImg.getBoundingClientRect();overlay.style.left=`${ir.left-wr.left}px`;overlay.style.top=`${ir.top-wr.top}px`;overlay.style.width=`${ir.width}px`;overlay.style.height=`${ir.height}px`}
function setBackground(src){if(!src)return;bgImg.onload=()=>{syncOverlay();renderAll()};bgImg.src=src;bgImg.style.display='block';emptyHint.style.display='none';wrap.classList.add('has-image');if(bgImg.complete&&bgImg.naturalWidth){syncOverlay();renderAll()}}
function bindZonePointer(zone,handle,item){zone.addEventListener('pointerdown',function(e){if(e.target===handle)return;e.preventDefault();e.stopPropagation();selectedId=item.id;renderAll();const active=overlay.querySelector(`[data-id="${item.id}"]`);if(!active)return;active.setPointerCapture(e.pointerId);const rect=overlay.getBoundingClientRect(),start={x:e.clientX,y:e.clientY,itemX:item.x,itemY:item.y};const move=ev=>{if(ev.pointerId!==e.pointerId)return;const dx=(ev.clientX-start.x)/rect.width*100,dy=(ev.clientY-start.y)/rect.height*100;item.x=Math.max(0,Math.min(100-item.w,Number((start.itemX+dx).toFixed(4))));item.y=Math.max(0,Math.min(100-item.h,Number((start.itemY+dy).toFixed(4))));active.style.left=`${item.x}%`;active.style.top=`${item.y}%`};const up=ev=>{if(ev.pointerId!==e.pointerId)return;active.removeEventListener('pointermove',move);active.removeEventListener('pointerup',up);active.removeEventListener('pointercancel',up)};active.addEventListener('pointermove',move);active.addEventListener('pointerup',up);active.addEventListener('pointercancel',up)});handle.addEventListener('pointerdown',function(e){e.preventDefault();e.stopPropagation();selectedId=item.id;zone.classList.add('selected');handle.setPointerCapture(e.pointerId);const rect=overlay.getBoundingClientRect(),start={x:e.clientX,y:e.clientY,w:item.w,h:item.h};const move=ev=>{if(ev.pointerId!==e.pointerId)return;const dw=(ev.clientX-start.x)/rect.width*100,dh=(ev.clientY-start.y)/rect.height*100;item.w=Math.max(4,Math.min(60,100-item.x,Number((start.w+dw).toFixed(4))));item.h=Math.max(4,Math.min(60,100-item.y,Number((start.h+dh).toFixed(4))));zone.style.width=`${item.w}%`;zone.style.height=`${item.h}%`};const up=ev=>{if(ev.pointerId!==e.pointerId)return;handle.removeEventListener('pointermove',move);handle.removeEventListener('pointerup',up);handle.removeEventListener('pointercancel',up)};handle.addEventListener('pointermove',move);handle.addEventListener('pointerup',up);handle.addEventListener('pointercancel',up)})}
function createZone(item){const zone=document.createElement('div');zone.className='ddpe-zone';zone.dataset.id=item.id;zone.style.left=`${item.x}%`;zone.style.top=`${item.y}%`;zone.style.width=`${item.w}%`;zone.style.height=`${item.h}%`;zone.style.zIndex=String(10+(Number(item.layer)||0));if(selectedId===item.id)zone.classList.add('selected');const num=document.createElement('div');num.className='ddpe-zone-number';num.textContent=item.id;zone.appendChild(num);if(item.pic_url){const img=document.createElement('img');img.src=item.pic_url;img.alt=item.label||'';img.style.transform=transformFor(item);zone.appendChild(img)}else{const ph=document.createElement('div');ph.className='ddpe-placeholder';ph.textContent='📷';zone.appendChild(ph)}const handle=document.createElement('div');handle.className='ddpe-resize';zone.appendChild(handle);bindZonePointer(zone,handle,item);overlay.appendChild(zone)}
function moveLayer(index,delta){const target=index+delta;if(target<0||target>=items.length)return;[items[index],items[target]]=[items[target],items[index]];normalizeLayers();renderAll()}
function renderList(){itemList.innerHTML='';noItemsHint.style.display=items.length?'none':'block';items.forEach((item,index)=>{const li=document.createElement('li');li.className='ddpe-item';li.dataset.id=item.id;const badge=document.createElement('div');badge.className='ddpe-badge';badge.textContent=item.id;let thumb;if(item.pic_url){thumb=document.createElement('img');thumb.className='ddpe-thumb';thumb.src=item.pic_url;thumb.alt='';thumb.style.transform=transformFor(item)}else{thumb=document.createElement('div');thumb.className='ddpe-thumb placeholder';thumb.textContent='📷'}const main=document.createElement('div');main.className='ddpe-item-main';const label=document.createElement('input');label.type='text';label.value=item.label||'';label.placeholder='Label (optional)';label.addEventListener('input',()=>item.label=label.value);const file=document.createElement('input');file.type='file';file.accept='image/*';file.addEventListener('change',()=>{if(file.files[0])uploadItem(file.files[0],item.id)});const actions=document.createElement('div');actions.className='ddpe-actions';[['↻','Rotate',()=>{item.rot=((Number(item.rot)||0)+90)%360;renderAll()}],['↔','Flip',()=>{item.flipH=!item.flipH;renderAll()}],['↑','Front',()=>moveLayer(index,1)],['↓','Back',()=>moveLayer(index,-1)]].forEach(([text,title,handler],controlIndex)=>{const b=document.createElement('button');b.type='button';b.className='ddpe-mini';b.textContent=text;b.title=title;if(controlIndex===2&&index===items.length-1)b.disabled=true;if(controlIndex===3&&index===0)b.disabled=true;b.addEventListener('click',handler);actions.appendChild(b)});main.append(label,file,actions);const del=document.createElement('button');del.type='button';del.className='ddpe-delete';del.textContent='×';del.addEventListener('click',()=>{items=items.filter(x=>x.id!==item.id);normalizeLayers();if(selectedId===item.id)selectedId=null;renderAll()});li.append(badge,thumb,main,del);li.addEventListener('click',e=>{if(e.target.tagName!=='INPUT'&&e.target.tagName!=='BUTTON'){selectedId=item.id;renderAll()}});itemList.appendChild(li)})}
function renderAll(){syncOverlay();overlay.innerHTML='';normalizeLayers();items.forEach(createZone);renderList()}
function uploadItem(file,id){const item=items.find(x=>x.id===id);if(!item)return;pendingUploads++;const fd=new FormData();fd.append('action','upload_pic_item');fd.append('image',file);fetch(window.location.href,{method:'POST',body:fd,credentials:'same-origin'}).then(r=>r.json()).then(data=>{if(!data.url)throw new Error(data.error||'Upload failed');item.pic_url=data.url;renderAll()}).catch(err=>alert(err.message||'Image upload failed.')).finally(()=>{pendingUploads=Math.max(0,pendingUploads-1)})}
overlay.addEventListener('pointerdown',function(e){if(e.target!==overlay||!bgImg.naturalWidth)return;e.preventDefault();const rect=overlay.getBoundingClientRect(),w=14,h=12,x=Math.max(0,Math.min(100-w,(e.clientX-rect.left)/rect.width*100-w/2)),y=Math.max(0,Math.min(100-h,(e.clientY-rect.top)/rect.height*100-h/2)),item={id:nextId++,pic_url:'',label:'',x:Number(x.toFixed(4)),y:Number(y.toFixed(4)),w,h,rot:0,flipH:false,layer:items.length};items.push(item);selectedId=item.id;renderAll();requestAnimationFrame(()=>{const input=itemList.querySelector(`[data-id="${item.id}"] input[type=file]`);if(input)input.click()})});
bgFile.addEventListener('change',function(){const file=this.files[0];if(!file)return;const reader=new FileReader();reader.onload=e=>{bgExisting.value='';setBackground(e.target.result)};reader.readAsDataURL(file)});form.addEventListener('submit',function(e){if(pendingUploads>0){e.preventDefault();alert('Please wait for image uploads to finish.');return}if(items.some(item=>!item.pic_url)){e.preventDefault();alert('Every zone needs a picture before saving.');return}normalizeLayers();itemsInput.value=JSON.stringify(items)});window.addEventListener('resize',syncOverlay);if(<?= json_encode((string)($activity['background_image'] ?? '')) ?>){setBackground(<?= json_encode((string)($activity['background_image'] ?? '')) ?>)}else{renderList()}
})();
</script>
<?php
$content = ob_get_clean();
render_activity_editor('Drag & Drop Picture — Editor', '🖼️', $content);
