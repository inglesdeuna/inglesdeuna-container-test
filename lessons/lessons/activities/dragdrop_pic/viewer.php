<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = trim((string)($_GET['id'] ?? ''));
$unit       = trim((string)($_GET['unit'] ?? ''));
$returnTo   = trim((string)($_GET['return_to'] ?? ''));

function ddpv_h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function ddpv_payload($raw): array {
    $default = [
        'title' => 'Drag & Drop Picture',
        'instructions' => '',
        'background_image' => '',
        'items' => [],
    ];

    $data = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($data)) return $default;

    $items = [];
    foreach (($data['items'] ?? []) as $index => $item) {
        if (!is_array($item)) continue;
        $id = (int)($item['id'] ?? 0);
        $picUrl = trim((string)($item['pic_url'] ?? ''));
        if ($id <= 0 || $picUrl === '') continue;

        $items[] = [
            'id' => $id,
            'pic_url' => $picUrl,
            'label' => trim((string)($item['label'] ?? '')),
            'x' => max(0, min(96, (float)($item['x'] ?? 10))),
            'y' => max(0, min(96, (float)($item['y'] ?? 10))),
            'w' => max(4, min(60, (float)($item['w'] ?? 14))),
            'h' => max(4, min(60, (float)($item['h'] ?? 12))),
            'rot' => ((int)($item['rot'] ?? 0) % 360 + 360) % 360,
            'flipH' => !empty($item['flipH']),
            'layer' => max(0, (int)($item['layer'] ?? $index)),
        ];
    }

    usort($items, static fn(array $a, array $b): int => $a['layer'] <=> $b['layer']);
    foreach ($items as $index => &$item) $item['layer'] = $index;
    unset($item);

    return [
        'title' => trim((string)($data['title'] ?? '')) ?: 'Drag & Drop Picture',
        'instructions' => trim((string)($data['instructions'] ?? '')),
        'background_image' => trim((string)($data['background_image'] ?? '')),
        'items' => $items,
    ];
}

if ($unit === '' && $activityId !== '') {
    $stmt = $pdo->prepare('SELECT unit_id FROM activities WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $unit = $row ? (string)($row['unit_id'] ?? '') : '';
}

$row = null;
if ($activityId !== '') {
    $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE id = :id AND type = 'dragdrop_pic' LIMIT 1");
    $stmt->execute(['id' => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
}
if (!$row && $unit !== '') {
    $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE unit_id = :unit AND type = 'dragdrop_pic' ORDER BY id ASC LIMIT 1");
    $stmt->execute(['unit' => $unit]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
}
if (!$row) die('Activity not configured yet.');

$activity = ddpv_payload($row['data'] ?? null);
$activityId = (string)($row['id'] ?? $activityId);
$title = $activity['title'];
$instructions = $activity['instructions'];
$background = $activity['background_image'];
$items = $activity['items'];

if ($background === '' || !$items) die('Activity not configured yet.');

ob_start();
?>
<style>
body,.activity-wrapper,.viewer-content,body.presentation-mode .viewer-content,body.fullscreen-embedded .viewer-content{background:#fff!important}
.ddpv-shell{max-width:1080px;margin:0 auto;padding:0 14px 24px;box-sizing:border-box}.act-header{max-width:1080px!important;margin:0 auto 8px!important;padding:8px 14px!important;text-align:center!important;background:transparent!important;border:0!important;box-shadow:none!important}.act-header h2{margin:0 0 4px!important;color:#F97316!important;font-size:clamp(20px,3vw,34px)!important}.act-header p{margin:0!important;color:#8d86b3!important;font-size:clamp(12px,1.3vw,15px)!important}
.ddpv-toolbar{display:flex;align-items:center;justify-content:center;gap:8px;flex-wrap:wrap;margin:0 0 10px}.ddpv-btn{border:2px solid #7F77DD;background:#fff;color:#6158b7;border-radius:999px;min-width:38px;height:38px;padding:0 13px;font-weight:800;cursor:pointer}.ddpv-btn.primary{background:#7F77DD;color:#fff}.ddpv-btn.warn{border-color:#F97316;color:#F97316}.ddpv-btn:disabled{opacity:.45;cursor:not-allowed}.ddpv-feedback{min-height:24px;text-align:center;font-weight:800;font-size:14px}.ddpv-feedback.good{color:#15803d}.ddpv-feedback.bad{color:#dc2626}
.ddpv-stage-wrap{overflow:auto;-webkit-overflow-scrolling:touch;padding:4px 4px 10px}.ddpv-zoom-target{transform-origin:top center;transition:transform .15s ease}.ddpv-stage{display:flex;justify-content:center;align-items:flex-start}.ddpv-canvas{position:relative;display:inline-block;line-height:0;touch-action:none}.ddpv-bg{display:block;max-width:100%;max-height:calc(100vh - 300px);width:auto;height:auto;border-radius:18px;box-shadow:0 10px 28px rgba(15,23,42,.14);pointer-events:none;user-select:none;-webkit-user-drag:none}.ddpv-zone{position:absolute;box-sizing:border-box;border-radius:10px;touch-action:none;cursor:pointer;transition:outline-color .14s,background .14s,transform .14s}.ddpv-zone.over{outline:3px solid #F97316;outline-offset:-2px;background:rgba(249,115,22,.16);transform:scale(1.025)}.ddpv-zone.wrong{outline:3px solid #dc2626;outline-offset:-2px;background:rgba(220,38,38,.14);animation:ddpvShake .35s ease}.ddpv-zone.filled{pointer-events:none}.ddpv-placed{position:absolute;object-fit:contain;display:block;pointer-events:none;user-select:none;transform-origin:center;border-radius:6px}.ddpv-bank{display:flex;flex-wrap:wrap;justify-content:center;gap:10px;min-height:92px;margin-top:10px;padding:8px;border:1px solid #ede9fa;background:#faf9ff;border-radius:16px}.ddpv-chip{width:92px;min-height:92px;display:flex;align-items:center;justify-content:center;border:2px solid #7F77DD;border-radius:14px;background:#fff;box-shadow:0 3px 10px rgba(127,119,221,.16);cursor:grab;touch-action:none;user-select:none;transition:opacity .12s,transform .12s,border-color .12s}.ddpv-chip:active{cursor:grabbing}.ddpv-chip.selected{border-color:#F97316;box-shadow:0 0 0 3px rgba(249,115,22,.2);transform:translateY(-3px)}.ddpv-chip.dragging{opacity:.25}.ddpv-chip img{width:84px;height:84px;object-fit:contain;pointer-events:none;user-select:none}.ddpv-drag-image{position:fixed;z-index:100000;pointer-events:none;width:96px;height:96px;object-fit:contain;filter:drop-shadow(0 10px 15px rgba(0,0,0,.3));transform:translate(-50%,-50%) scale(1.08)}.ddpv-completed{margin-top:12px}.ddpv-hidden{display:none!important}@keyframes ddpvShake{0%,100%{transform:translateX(0)}25%{transform:translateX(-7px)}75%{transform:translateX(7px)}}
@media(max-width:700px){.ddpv-shell{padding-left:6px;padding-right:6px}.ddpv-bg{max-height:none;max-width:none;width:min(94vw,760px)}.ddpv-chip{width:76px;min-height:76px}.ddpv-chip img{width:68px;height:68px}}
</style>
<div class="ddpv-shell">
    <div class="ddpv-toolbar" id="ddpvToolbar">
        <button type="button" class="ddpv-btn" id="ddpvZoomOut" aria-label="Zoom out">−</button>
        <button type="button" class="ddpv-btn" id="ddpvZoomReset">100%</button>
        <button type="button" class="ddpv-btn" id="ddpvZoomIn" aria-label="Zoom in">+</button>
        <button type="button" class="ddpv-btn warn" id="ddpvShow">Show Answer</button>
        <button type="button" class="ddpv-btn primary" id="ddpvRetry">Retry</button>
    </div>
    <div class="ddpv-feedback" id="ddpvFeedback" aria-live="polite"></div>
    <div class="ddpv-stage-wrap" id="ddpvStageWrap">
        <div class="ddpv-zoom-target" id="ddpvZoomTarget">
            <div class="ddpv-stage">
                <div class="ddpv-canvas" id="ddpvCanvas">
                    <img src="<?= ddpv_h($background) ?>" alt="Activity scene" class="ddpv-bg" id="ddpvBg">
                    <?php foreach ($items as $item): ?>
                        <div class="ddpv-zone" data-id="<?= (int)$item['id'] ?>" style="left:<?= (float)$item['x'] ?>%;top:<?= (float)$item['y'] ?>%;width:<?= (float)$item['w'] ?>%;height:<?= (float)$item['h'] ?>%;z-index:<?= 10 + (int)$item['layer'] ?>"></div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="ddpv-bank" id="ddpvBank"></div>
    <div class="ddpv-completed" id="ddpvCompleted"></div>
</div>
<script>
(function(){'use strict';
const ITEMS=<?= json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const ACTIVITY_ID=<?= json_encode($activityId) ?>;
const RETURN_TO=<?= json_encode($returnTo) ?>;
const TITLE=<?= json_encode($title, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const canvas=document.getElementById('ddpvCanvas'),bank=document.getElementById('ddpvBank'),feedback=document.getElementById('ddpvFeedback'),completed=document.getElementById('ddpvCompleted'),toolbar=document.getElementById('ddpvToolbar'),zoomTarget=document.getElementById('ddpvZoomTarget'),zoomReset=document.getElementById('ddpvZoomReset');
let selectedChip=null,dragState=null,zoom=1,finished=false,showedAnswers=false,wrongAttempts=0,correctPlacements=0,attemptedIds=new Set();
function shuffle(list){const copy=list.slice();for(let i=copy.length-1;i>0;i--){const j=Math.floor(Math.random()*(i+1));[copy[i],copy[j]]=[copy[j],copy[i]]}return copy}
function transformFor(item){return `rotate(${Number(item.rot)||0}deg) scaleX(${item.flipH?-1:1})`}
function itemById(id){return ITEMS.find(item=>String(item.id)===String(id))}
function zoneById(id){return canvas.querySelector(`.ddpv-zone[data-id="${id}"]`)}
function setFeedback(text,type){feedback.textContent=text||'';feedback.className='ddpv-feedback'+(type?` ${type}`:'')}
function clearSelection(){if(selectedChip)selectedChip.classList.remove('selected');selectedChip=null}
function selectChip(chip){clearSelection();selectedChip=chip;chip.classList.add('selected')}
function buildBank(){bank.innerHTML='';shuffle(ITEMS).forEach(item=>{const chip=document.createElement('button');chip.type='button';chip.className='ddpv-chip';chip.dataset.id=item.id;chip.setAttribute('aria-label',item.label||`Picture ${item.id}`);const img=document.createElement('img');img.src=item.pic_url;img.alt=item.label||'';img.style.transform=transformFor(item);chip.appendChild(img);chip.addEventListener('click',e=>{e.preventDefault();if(finished)return;selectChip(chip)});chip.addEventListener('pointerdown',startDrag);bank.appendChild(chip)})}
function startDrag(e){if(finished||e.button>0)return;const chip=e.currentTarget;e.preventDefault();selectChip(chip);chip.classList.add('dragging');chip.setPointerCapture(e.pointerId);const item=itemById(chip.dataset.id),ghost=document.createElement('img');ghost.className='ddpv-drag-image';ghost.src=item.pic_url;ghost.alt='';ghost.style.transform=`translate(-50%,-50%) scale(1.08) ${transformFor(item)}`;document.body.appendChild(ghost);dragState={pointerId:e.pointerId,chip,ghost,moved:false,startX:e.clientX,startY:e.clientY};moveGhost(e);chip.addEventListener('pointermove',dragMove);chip.addEventListener('pointerup',dragEnd);chip.addEventListener('pointercancel',dragEnd)}
function moveGhost(e){if(!dragState)return;dragState.ghost.style.left=`${e.clientX}px`;dragState.ghost.style.top=`${e.clientY}px`}
function dragMove(e){if(!dragState||e.pointerId!==dragState.pointerId)return;e.preventDefault();moveGhost(e);if(Math.hypot(e.clientX-dragState.startX,e.clientY-dragState.startY)>5)dragState.moved=true;document.querySelectorAll('.ddpv-zone.over').forEach(z=>z.classList.remove('over'));const zone=document.elementFromPoint(e.clientX,e.clientY)?.closest('.ddpv-zone');if(zone&&!zone.classList.contains('filled'))zone.classList.add('over')}
function dragEnd(e){if(!dragState||e.pointerId!==dragState.pointerId)return;e.preventDefault();const state=dragState;state.chip.removeEventListener('pointermove',dragMove);state.chip.removeEventListener('pointerup',dragEnd);state.chip.removeEventListener('pointercancel',dragEnd);state.chip.classList.remove('dragging');state.ghost.remove();document.querySelectorAll('.ddpv-zone.over').forEach(z=>z.classList.remove('over'));const zone=document.elementFromPoint(e.clientX,e.clientY)?.closest('.ddpv-zone');dragState=null;if(zone&&!zone.classList.contains('filled'))attemptDrop(zone,state.chip);else if(state.moved)setFeedback('Drop the picture on a matching place.','bad')}
function placeImage(zone,item){const img=document.createElement('img');img.src=item.pic_url;img.alt=item.label||'';img.className='ddpv-placed';img.dataset.zoneId=zone.dataset.id;img.style.left=zone.style.left;img.style.top=zone.style.top;img.style.width=zone.style.width;img.style.height=zone.style.height;img.style.zIndex=String(100+(Number(item.layer)||0));img.style.transform=transformFor(item);canvas.appendChild(img);zone.classList.add('filled')}
function attemptDrop(zone,chip){if(finished)return;const chipId=chip.dataset.id,zoneId=zone.dataset.id;attemptedIds.add(String(chipId));if(String(chipId)===String(zoneId)){const item=itemById(chipId);placeImage(zone,item);chip.remove();clearSelection();correctPlacements++;setFeedback('Correct!','good');if(correctPlacements===ITEMS.length)setTimeout(finishActivity,350)}else{wrongAttempts++;zone.classList.add('wrong');setFeedback('Try a different spot.','bad');setTimeout(()=>zone.classList.remove('wrong'),450)}}
document.querySelectorAll('.ddpv-zone').forEach(zone=>{zone.addEventListener('pointerup',e=>{if(finished||dragState||!selectedChip||zone.classList.contains('filled'))return;e.preventDefault();attemptDrop(zone,selectedChip)})});
function showAnswers(){if(finished)return;showedAnswers=true;finished=true;clearSelection();ITEMS.forEach(item=>{const zone=zoneById(item.id);if(zone&&!zone.classList.contains('filled'))placeImage(zone,item)});bank.innerHTML='';setFeedback('Answers shown.','bad');setTimeout(finishActivity,350)}
function scoreData(){const total=ITEMS.length;if(showedAnswers)return{scores:Array(total).fill(0),pct:0,errors:Math.max(total,wrongAttempts)};const correctWithoutMistake=Math.max(0,total-Math.min(total,wrongAttempts));const scores=Array.from({length:total},(_,index)=>index<correctWithoutMistake?1:0);return{scores,pct:Math.round(correctWithoutMistake/total*100),errors:wrongAttempts}}
async function finishActivity(){if(completed.dataset.rendered==='1')return;finished=true;completed.dataset.rendered='1';toolbar.style.display='none';const result=scoreData();if(window.ActivityFeedback&&typeof window.ActivityFeedback.showCompleted==='function'){window.ActivityFeedback.showCompleted({target:completed,scores:result.scores,title:TITLE,activityType:'Drag & Drop Picture',questionCount:ITEMS.length,onRetry:restart})}else{completed.innerHTML=`<div style="text-align:center;padding:22px;font-weight:800">Completed — ${result.pct}%</div>`}if(ACTIVITY_ID&&RETURN_TO){const joiner=RETURN_TO.includes('?')?'&':'?';const saveUrl=RETURN_TO+joiner+'activity_percent='+result.pct+'&activity_errors='+result.errors+'&activity_total='+ITEMS.length+'&activity_id='+encodeURIComponent(ACTIVITY_ID)+'&activity_type=dragdrop_pic';try{await fetch(saveUrl,{method:'GET',credentials:'same-origin',cache:'no-store'})}catch(error){console.warn('Score persistence failed',error)}}}
function restart(){finished=false;showedAnswers=false;wrongAttempts=0;correctPlacements=0;attemptedIds=new Set();completed.dataset.rendered='0';completed.innerHTML='';toolbar.style.display='flex';setFeedback('','');clearSelection();canvas.querySelectorAll('.ddpv-placed').forEach(img=>img.remove());canvas.querySelectorAll('.ddpv-zone').forEach(zone=>zone.classList.remove('filled','wrong','over'));buildBank()}
function applyZoom(){zoomTarget.style.transform=`scale(${zoom})`;zoomReset.textContent=`${Math.round(zoom*100)}%`}
document.getElementById('ddpvZoomIn').addEventListener('click',()=>{zoom=Math.min(2,Number((zoom+.1).toFixed(2)));applyZoom()});document.getElementById('ddpvZoomOut').addEventListener('click',()=>{zoom=Math.max(.6,Number((zoom-.1).toFixed(2)));applyZoom()});zoomReset.addEventListener('click',()=>{zoom=1;applyZoom()});document.getElementById('ddpvShow').addEventListener('click',showAnswers);document.getElementById('ddpvRetry').addEventListener('click',restart);
buildBank();applyZoom();
})();
</script>
<?php
$content = ob_get_clean();
render_activity_viewer($title, '🖼️', $content);
