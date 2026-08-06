<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId=trim((string)($_GET['id']??''));$unit=trim((string)($_GET['unit']??''));$returnTo=trim((string)($_GET['return_to']??''));
function ddpv_h(string $v):string{return htmlspecialchars($v,ENT_QUOTES,'UTF-8');}
function ddpv_items(array $raw):array{$items=[];foreach($raw as $k=>$i){if(!is_array($i))continue;$id=(int)($i['id']??0);$url=trim((string)($i['pic_url']??''));$label=trim((string)($i['label']??''));if($id<=0||($url===''&&$label===''))continue;$items[]=['id'=>$id,'pic_url'=>$url,'label'=>$label,'x'=>max(0,min(96,(float)($i['x']??10))),'y'=>max(0,min(96,(float)($i['y']??10))),'w'=>max(4,min(60,(float)($i['w']??14))),'h'=>max(4,min(60,(float)($i['h']??12))),'rot'=>(((int)($i['rot']??0))%360+360)%360,'flipH'=>!empty($i['flipH']),'layer'=>max(0,(int)($i['layer']??$k))];}usort($items,fn($a,$b)=>$a['layer']<=>$b['layer']);foreach($items as $k=>&$i)$i['layer']=$k;unset($i);return $items;}
function ddpv_payload($raw):array{$d=is_string($raw)?json_decode($raw,true):$raw;if(!is_array($d))$d=[];$blocks=[];if(!empty($d['blocks'])&&is_array($d['blocks']))foreach($d['blocks'] as $k=>$b){if(!is_array($b))continue;$bg=trim((string)($b['background_image']??''));$items=ddpv_items($b['items']??[]);if($bg!==''&&$items)$blocks[]=['id'=>trim((string)($b['id']??''))?:('block_'.($k+1)),'title'=>trim((string)($b['title']??''))?:('Block '.($k+1)),'instructions'=>trim((string)($b['instructions']??'')),'background_image'=>$bg,'items'=>$items];}if(!$blocks){$bg=trim((string)($d['background_image']??''));$items=ddpv_items($d['items']??[]);if($bg!==''&&$items)$blocks[]=['id'=>'block_1','title'=>'Block 1','instructions'=>trim((string)($d['instructions']??'')),'background_image'=>$bg,'items'=>$items];}return['title'=>trim((string)($d['title']??''))?:'Drag & Drop Picture','instructions'=>trim((string)($d['instructions']??'')),'blocks'=>$blocks];}
if($unit===''&&$activityId!==''){$s=$pdo->prepare('SELECT unit_id FROM activities WHERE id=:id LIMIT 1');$s->execute(['id'=>$activityId]);$r=$s->fetch(PDO::FETCH_ASSOC);$unit=$r?(string)($r['unit_id']??''):'';}
$row=null;if($activityId!==''){$s=$pdo->prepare("SELECT id,data FROM activities WHERE id=:id AND type='dragdrop_pic' LIMIT 1");$s->execute(['id'=>$activityId]);$row=$s->fetch(PDO::FETCH_ASSOC);}if(!$row&&$unit!==''){$s=$pdo->prepare("SELECT id,data FROM activities WHERE unit_id=:unit AND type='dragdrop_pic' ORDER BY id ASC LIMIT 1");$s->execute(['unit'=>$unit]);$row=$s->fetch(PDO::FETCH_ASSOC);}if(!$row)die('Activity not configured yet.');
$activity=ddpv_payload($row['data']??null);$activityId=(string)($row['id']??$activityId);$title=$activity['title'];$instructions=$activity['instructions'];$blocks=$activity['blocks'];if(!$blocks)die('Activity not configured yet.');
$totalItems=array_sum(array_map(fn($b)=>count($b['items']),$blocks));
ob_start();
?>
<style>
body,
.activity-wrapper,
.viewer-content,
body.presentation-mode .viewer-content,
body.fullscreen-embedded .viewer-content {
    background: #fff !important;
}

.ddpv-shell {
    max-width: 1100px;
    margin: 0 auto;
    padding: 8px 14px 24px;
    box-sizing: border-box;
    font-family: 'Nunito', 'Segoe UI', sans-serif;
}

.ddpv-block-head {
    max-width: 1100px;
    margin: 0 auto 8px;
    padding: 4px 18px;
    text-align: center;
}
.ddpv-progress {
    margin-bottom: 3px;
    color: #7F77DD;
    font-family: 'Nunito', sans-serif;
    font-size: 11px;
    font-weight: 800;
}
.ddpv-block-head h3 {
    margin: 0 0 4px;
    color: #F97316;
    font-family: 'Fredoka', 'Trebuchet MS', sans-serif;
    font-size: clamp(22px, 2.5vw, 32px);
    line-height: 1.08;
    font-weight: 700;
}
.ddpv-block-head p {
    margin: 0;
    color: #9B94BE;
    font-family: 'Nunito', sans-serif;
    font-size: clamp(12px, 1.2vw, 15px);
    font-weight: 600;
}

.ddpv-toolbar {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    flex-wrap: wrap;
    margin: 0 0 8px;
}
.ddpv-btn {
    height: 34px;
    min-width: 34px;
    padding: 0 12px;
    border: 2px solid #7F77DD;
    border-radius: 8px;
    background: #fff;
    color: #7F77DD;
    font-family: 'Nunito', sans-serif;
    font-size: 12px;
    font-weight: 800;
    line-height: 1;
    cursor: pointer;
    transition: background .12s, color .12s, transform .12s, filter .12s;
}
.ddpv-btn:hover {
    filter: brightness(1.05);
    transform: translateY(-1px);
}
#ddpvZoomOut,
#ddpvZoomIn {
    width: 34px;
    padding: 0;
    border-radius: 50%;
    font-size: 18px;
    font-weight: 900;
}
#ddpvZoomReset {
    min-width: 38px;
    padding: 0 3px;
    border-color: transparent;
    color: #9B94BE;
    font-size: 11px;
}
.ddpv-btn.primary {
    border-color: #7F77DD;
    background: #7F77DD;
    color: #fff;
}
.ddpv-btn.warn {
    border-color: #F97316;
    color: #F97316;
}
.ddpv-btn:disabled {
    opacity: .45;
}

.ddpv-feedback {
    min-height: 24px;
    margin: 2px 0;
    text-align: center;
    font-family: 'Nunito', sans-serif;
    font-size: clamp(14px, 1.4vw, 18px);
    font-weight: 800;
}
.ddpv-feedback.good { color: #15803d; }
.ddpv-feedback.bad { color: #dc2626; }

.ddpv-stage-wrap {
    overflow: auto;
    -webkit-overflow-scrolling: touch;
    padding: 0 4px 6px;
}
.ddpv-zoom-target {
    transform-origin: top center;
    transition: transform .15s ease;
}
.ddpv-stage {
    display: flex;
    justify-content: center;
    align-items: flex-start;
}
.ddpv-canvas {
    position: relative;
    display: inline-block;
    max-width: 100%;
    line-height: 0;
    touch-action: none;
}
.ddpv-bg {
    display: block;
    max-width: 100%;
    max-height: calc(100vh - 250px);
    width: auto;
    height: auto;
    border-radius: 16px;
    box-shadow: 0 10px 28px rgba(15, 23, 42, .13);
    pointer-events: none;
    user-select: none;
}

.ddpv-zone {
    position: absolute;
    box-sizing: border-box;
    border: 2.5px solid #7F77DD;
    border-radius: 6px;
    background: rgba(127, 119, 221, .30);
    cursor: pointer;
    touch-action: none;
    transition: background .18s, border-color .18s, transform .15s;
}
.ddpv-zone.over {
    border-color: #5b52d1;
    background: rgba(127, 119, 221, .55);
    outline: none;
    transform: scale(1.04);
}
.ddpv-zone.wrong {
    border-color: #dc2626;
    background: rgba(254, 226, 226, .80);
    outline: none;
    animation: ddk-shake .4s ease;
}
.ddpv-zone.filled {
    border-color: #7c3aed;
    background: #fff;
    pointer-events: none;
}

.ddpv-placed {
    position: absolute;
    display: block;
    object-fit: contain;
    pointer-events: none;
    user-select: none;
    transform-origin: center;
}
.ddpv-placed.is-label {
    display: flex;
    align-items: center;
    justify-content: center;
    box-sizing: border-box;
    padding: 4px;
    border: 0;
    background: transparent;
    color: #4c1d95;
    font-family: 'Fredoka', 'Trebuchet MS', sans-serif;
    font-size: clamp(11px, 1.3vw, 18px);
    line-height: 1.15;
    font-weight: 700;
    text-align: center;
    overflow-wrap: normal;
    word-break: normal;
    hyphens: none;
}

.ddpv-bank {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 10px;
    min-height: 48px;
    margin: 8px 0;
    padding: 0;
    border: 0;
    background: transparent;
}
.ddpv-chip {
    width: 92px;
    min-height: 92px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    box-sizing: border-box;
    border: 2.5px solid #7c3aed;
    border-radius: 8px;
    background: #fff;
    color: #4c1d95;
    box-shadow: 0 2px 8px rgba(127, 119, 221, .15);
    cursor: grab;
    touch-action: none;
    user-select: none;
    transition: filter .12s, box-shadow .12s;
}
.ddpv-chip:hover {
    filter: brightness(1.06);
    box-shadow: 0 4px 14px rgba(127, 119, 221, .28);
}
.ddpv-chip.selected {
    border-color: #F97316;
    background: #FFF0E6;
    box-shadow: 0 0 0 3px rgba(249, 115, 22, .20);
}
.ddpv-chip.dragging {
    opacity: .35;
}
.ddpv-chip img {
    width: 84px;
    height: 84px;
    object-fit: contain;
    pointer-events: none;
}
.ddpv-chip.is-label {
    width: auto;
    min-width: 0;
    min-height: 48px;
    max-width: none;
    padding: 10px 20px;
}
.ddpv-chip-label {
    padding: 0;
    color: #4c1d95;
    font-family: 'Fredoka', 'Trebuchet MS', sans-serif;
    font-size: clamp(16px, 1.7vw, 22px);
    line-height: 1;
    font-weight: 700;
    text-align: center;
    white-space: normal;
    overflow-wrap: normal;
    word-break: normal;
    hyphens: none;
    pointer-events: none;
}

.ddpv-drag-image {
    position: fixed;
    z-index: 100000;
    width: 96px;
    height: 96px;
    object-fit: contain;
    pointer-events: none;
    filter: drop-shadow(0 10px 15px rgba(0, 0, 0, .30));
}
.ddpv-drag-image.is-label {
    width: auto;
    min-width: 92px;
    height: auto;
    min-height: 48px;
    display: flex;
    align-items: center;
    justify-content: center;
    box-sizing: border-box;
    padding: 10px 20px;
    border: 2.5px solid #7c3aed;
    border-radius: 8px;
    background: #fff;
    color: #4c1d95;
    font-family: 'Fredoka', 'Trebuchet MS', sans-serif;
    font-size: clamp(16px, 1.7vw, 22px);
    line-height: 1;
    font-weight: 700;
    text-align: center;
    white-space: normal;
    overflow-wrap: normal;
    word-break: normal;
    hyphens: none;
}

.ddpv-next-wrap {
    margin-top: 8px;
    text-align: center;
}
.ddpv-next-wrap .ddpv-btn {
    min-width: 140px;
    padding: 0 22px;
}
.ddpv-completed {
    margin-top: 10px;
}

@keyframes ddk-shake {
    0%, 100% { transform: translateX(0); }
    20% { transform: translateX(-6px); }
    60% { transform: translateX(6px); }
    80% { transform: translateX(-3px); }
}

@media (max-width: 700px) {
    .ddpv-shell {
        padding: 4px 6px 18px;
    }
    .ddpv-block-head {
        padding-left: 8px;
        padding-right: 8px;
    }
    .ddpv-bg {
        max-width: none;
        max-height: none;
        width: min(94vw, 760px);
    }
    .ddpv-chip {
        width: 76px;
        min-height: 76px;
    }
    .ddpv-chip img {
        width: 68px;
        height: 68px;
    }
    .ddpv-chip.is-label {
        width: auto;
        min-width: 0;
        min-height: 42px;
        padding: 9px 14px;
    }
    .ddpv-chip-label,
    .ddpv-drag-image.is-label {
        font-size: 15px;
    }
    .ddpv-bank {
        gap: 7px;
    }
}

body.presentation-mode .ddpv-shell,
body.fullscreen-embedded .ddpv-shell {
    max-width: 1100px;
    height: 100%;
    padding: 8mm;
}
body.presentation-mode .ddpv-bg,
body.fullscreen-embedded .ddpv-bg {
    max-height: calc(100vh - 250px);
}
body.presentation-mode .ddpv-block-head h3,
body.fullscreen-embedded .ddpv-block-head h3 {
    font-size: clamp(24px, 2.5vw, 34px);
}
body.presentation-mode .ddpv-chip.is-label,
body.fullscreen-embedded .ddpv-chip.is-label {
    padding: 11px 22px;
    border-width: 3px;
}
body.presentation-mode .ddpv-chip-label,
body.fullscreen-embedded .ddpv-chip-label,
body.presentation-mode .ddpv-drag-image.is-label,
body.fullscreen-embedded .ddpv-drag-image.is-label {
    font-size: clamp(18px, 1.8vw, 24px);
}
body.presentation-mode .ddpv-zone,
body.fullscreen-embedded .ddpv-zone {
    border-width: 3px;
}

/* Let's Classify-aligned activity shell and authorized result flow */
.ddpv-page{width:100%;padding:clamp(8px,1.2vw,16px);box-sizing:border-box;background:#fff}
.ddpv-topbar{height:24px;display:flex;align-items:center;justify-content:center}
.ddpv-topbar-title{font-size:11px;font-weight:900;color:#9B94BE;letter-spacing:.1em;text-transform:uppercase}
.ddpv-hero{text-align:center;margin-bottom:8px}
.ddpv-kicker{display:inline-flex;align-items:center;justify-content:center;padding:6px 13px;border-radius:999px;background:#FFF0E6;border:1px solid #FCDDBF;color:#C2580A;font-size:11px;font-weight:900;letter-spacing:.08em;text-transform:uppercase;margin-bottom:7px}
.ddpv-hero h1{font-family:'Fredoka','Trebuchet MS',sans-serif;font-size:clamp(32px,3.9vw,46px);font-weight:700;color:#F97316;margin:0;line-height:1.02}
.ddpv-hero p{font-size:clamp(13px,1.35vw,16px);font-weight:800;color:#9B94BE;margin:6px 0 0}
.ddpv-card{background:#fff;border:1px solid #F0EEF8;border-radius:22px;padding:12px 14px;box-shadow:0 8px 36px rgba(127,119,221,.11);box-sizing:border-box}
.ddpv-card .ddpv-toolbar{justify-content:flex-end;padding:0 4px 8px;margin:0}
.ddpv-card .ddpv-block-head{margin:0 auto 4px;padding:0 12px}
.ddpv-card .ddpv-block-head h3:empty,.ddpv-card .ddpv-block-head p:empty{display:none}
.ddpv-play-area{display:block}
.ddpv-score-grid{display:none;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:10px}.ddpv-score-grid.visible{display:grid}
.ddpv-score-card{background:#FAFAFE;border:1px solid #EDE9FA;border-radius:14px;padding:12px;text-align:center}
.ddpv-score-num{font-family:'Fredoka',sans-serif;font-weight:700;font-size:26px;line-height:1}
.ddpv-score-num.correct{color:#16a34a}.ddpv-score-num.wrong{color:#ef4444}.ddpv-score-num.percent{color:#7F77DD}
.ddpv-score-label{margin-top:5px;font-size:10px;font-weight:900;color:#9B94BE;text-transform:uppercase;letter-spacing:.08em}
.ddpv-controls{border-top:1px solid #F0EEF8;margin-top:12px;padding-top:12px;text-align:center;display:flex;align-items:center;justify-content:center;gap:10px;flex-wrap:wrap}
.ddpv-controls .ddpv-btn{height:auto;min-width:90px;padding:12px 22px;border:none;border-radius:8px;color:#fff;font-size:13px;font-weight:900}
.ddpv-controls .ddpv-btn.check,.ddpv-controls .ddpv-btn.finish{background:#F97316;box-shadow:0 6px 18px rgba(249,115,22,.22)}
.ddpv-controls .ddpv-btn.secondary{background:#7F77DD;box-shadow:0 6px 18px rgba(127,119,221,.18)}
.ddpv-controls .ddpv-btn:disabled{opacity:.5;pointer-events:none}
.ddpv-completed{display:none;text-align:center;padding:28px 12px;max-width:520px;margin:0 auto}.ddpv-completed.active{display:block}
.ddpv-completed-icon{font-size:28px;line-height:1;margin-bottom:8px;color:#1E1B3A}
.ddpv-completed-title{margin:0 0 6px;color:#F97316;font-family:'Fredoka',sans-serif;font-size:34px;font-weight:700}
.ddpv-completed-text{color:#9B94BE;font-size:14px;font-weight:800;line-height:1.5;margin:0}
.ddpv-completed-score{font-size:15px;font-weight:900;color:#534AB7;margin:6px 0 14px}
.ddpv-completed-actions{display:flex;gap:10px;justify-content:center;flex-wrap:wrap}
.ddpv-completed-btn{display:inline-flex;align-items:center;justify-content:center;padding:11px 22px;border:none;border-radius:8px;cursor:pointer;min-width:120px;font-weight:700;font-size:14px;line-height:1;background:#7F77DD;color:#fff;box-shadow:0 6px 18px rgba(127,119,221,.18)}
@media(max-width:660px){.ddpv-score-grid{grid-template-columns:1fr}.ddpv-controls{flex-direction:column}.ddpv-controls .ddpv-btn{width:100%}}
</style>

<div class="ddpv-page">
  <div class="ddpv-shell">
    <div class="ddpv-topbar"><span class="ddpv-topbar-title">Drag and Drop Kids</span></div>
    <div class="ddpv-hero">
      <div class="ddpv-kicker">Activity</div>
      <h1><?=ddpv_h(preg_match('/^Drag\s*&\s*Drop Picture$/i',$title)?'Drag and Drop':$title)?></h1>
      <?php if($instructions!==''):?><p><?=ddpv_h($instructions)?></p><?php endif;?>
    </div>
    <div class="ddpv-card" id="ddpvCard">
      <div class="ddpv-toolbar" id="ddpvToolbar">
        <button type="button" class="ddpv-btn" id="ddpvZoomOut">−</button>
        <button type="button" class="ddpv-btn" id="ddpvZoomReset">100%</button>
        <button type="button" class="ddpv-btn" id="ddpvZoomIn">+</button>
      </div>
      <div class="ddpv-block-head" id="ddpvBlockHead">
        <div class="ddpv-progress" id="ddpvProgress"></div>
        <h3 id="ddpvBlockTitle"></h3><p id="ddpvBlockInstructions"></p>
      </div>
      <div class="ddpv-feedback" id="ddpvFeedback" aria-live="polite"></div>
      <div class="ddpv-play-area" id="ddpvPlayArea">
        <div class="ddpv-stage-wrap"><div class="ddpv-zoom-target" id="ddpvZoomTarget"><div class="ddpv-stage"><div class="ddpv-canvas" id="ddpvCanvas"></div></div></div></div>
        <div class="ddpv-bank" id="ddpvBank"></div>
      </div>
      <div class="ddpv-score-grid" id="ddpvScoreGrid">
        <div class="ddpv-score-card"><div class="ddpv-score-num correct" id="ddpvScoreCorrect">0</div><div class="ddpv-score-label">Correct</div></div>
        <div class="ddpv-score-card"><div class="ddpv-score-num wrong" id="ddpvScoreWrong">0</div><div class="ddpv-score-label">Wrong</div></div>
        <div class="ddpv-score-card"><div class="ddpv-score-num percent" id="ddpvScorePercent">0%</div><div class="ddpv-score-label">Score</div></div>
      </div>
      <div class="ddpv-controls" id="ddpvControls">
        <button type="button" class="ddpv-btn check" id="ddpvCheck">Check</button>
        <button type="button" class="ddpv-btn secondary" id="ddpvShow">Show Answer</button>
        <button type="button" class="ddpv-btn secondary" id="ddpvRetry">Retry</button>
        <button type="button" class="ddpv-btn finish" id="ddpvNext" style="display:none">Finish</button>
      </div>
      <div class="ddpv-completed" id="ddpvCompleted">
        <div class="ddpv-completed-icon">OK</div>
        <h2 class="ddpv-completed-title" id="ddpvCompletedTitle">Drag and Drop</h2>
        <div class="ddpv-score-grid visible" style="max-width:360px;margin:0 auto 12px">
          <div class="ddpv-score-card"><div class="ddpv-score-num correct" id="ddpvCompletedCorrect">0</div><div class="ddpv-score-label">Correct</div></div>
          <div class="ddpv-score-card"><div class="ddpv-score-num wrong" id="ddpvCompletedWrong">0</div><div class="ddpv-score-label">Wrong</div></div>
          <div class="ddpv-score-card"><div class="ddpv-score-num percent" id="ddpvCompletedPercent">0%</div><div class="ddpv-score-label">Score</div></div>
        </div>
        <p class="ddpv-completed-text">You've completed this activity. Great job!</p>
        <p class="ddpv-completed-score" id="ddpvCompletedScore"></p>
        <div class="ddpv-completed-actions"><button type="button" class="ddpv-completed-btn" id="ddpvCompletedRestart">Restart</button></div>
      </div>
    </div>
  </div>
</div>
<script>
(function(){'use strict';
const BLOCKS=<?=json_encode($blocks,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>,ACTIVITY_ID=<?=json_encode($activityId)?>,RETURN_TO=<?=json_encode($returnTo)?>,TITLE=<?=json_encode($title,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>,TOTAL_ITEMS=<?=$totalItems?>;
const canvas=document.getElementById('ddpvCanvas'),bank=document.getElementById('ddpvBank'),feedback=document.getElementById('ddpvFeedback'),completed=document.getElementById('ddpvCompleted'),toolbar=document.getElementById('ddpvToolbar'),zoomTarget=document.getElementById('ddpvZoomTarget'),zoomReset=document.getElementById('ddpvZoomReset'),nextBtn=document.getElementById('ddpvNext'),checkBtn=document.getElementById('ddpvCheck'),controls=document.getElementById('ddpvControls'),playArea=document.getElementById('ddpvPlayArea'),blockHead=document.getElementById('ddpvBlockHead'),scoreGrid=document.getElementById('ddpvScoreGrid'),scoreCorrect=document.getElementById('ddpvScoreCorrect'),scoreWrong=document.getElementById('ddpvScoreWrong'),scorePercent=document.getElementById('ddpvScorePercent'),completedTitle=document.getElementById('ddpvCompletedTitle'),completedCorrect=document.getElementById('ddpvCompletedCorrect'),completedWrong=document.getElementById('ddpvCompletedWrong'),completedPercent=document.getElementById('ddpvCompletedPercent'),completedScore=document.getElementById('ddpvCompletedScore'),progress=document.getElementById('ddpvProgress'),blockTitle=document.getElementById('ddpvBlockTitle'),blockInstructions=document.getElementById('ddpvBlockInstructions');
let blockIndex=0,selectedChip=null,dragState=null,zoom=1,finished=false,blockFinished=false,showedAnswers=false,wrongAttempts=0,blockCorrect=0,blockMistakes=0,scores=[];
function activityTitle(){const t=String(TITLE||'').trim();return /^Drag\s*&\s*Drop Picture$/i.test(t)?'Drag and Drop':(t||'Drag and Drop')}function current(){return BLOCKS[blockIndex]}function items(){return current().items}function shuffle(a){a=a.slice();for(let i=a.length-1;i>0;i--){const j=Math.floor(Math.random()*(i+1));[a[i],a[j]]=[a[j],a[i]]}return a}function tr(i){return`rotate(${Number(i.rot)||0}deg) scaleX(${i.flipH?-1:1})`}function itemById(id){return items().find(i=>String(i.id)===String(id))}function zoneById(id){return canvas.querySelector(`.ddpv-zone[data-id="${id}"]`)}function setFeedback(t,c){feedback.textContent=t||'';feedback.className='ddpv-feedback'+(c?' '+c:'')}function clearSelection(){if(selectedChip)selectedChip.classList.remove('selected');selectedChip=null}function selectChip(c){clearSelection();selectedChip=c;c.classList.add('selected')}
function renderBlock(){
    blockFinished=false;blockCorrect=0;blockMistakes=0;clearSelection();setFeedback('','');
    nextBtn.style.display='none';checkBtn.disabled=false;scoreGrid.classList.remove('visible');
    progress.textContent='Block '+(blockIndex+1)+' of '+BLOCKS.length;
    const visibleTitle=String(current().title||'').trim();blockTitle.textContent=/^Block\s+\d+$/i.test(visibleTitle)?'':visibleTitle;
    blockInstructions.textContent=current().instructions||'';canvas.innerHTML='';
    const bg=document.createElement('img');bg.className='ddpv-bg';bg.src=current().background_image;bg.alt='Activity scene';canvas.appendChild(bg);
    items().forEach(i=>{const z=document.createElement('div');z.className='ddpv-zone';z.dataset.id=i.id;Object.assign(z.style,{left:i.x+'%',top:i.y+'%',width:i.w+'%',height:i.h+'%',zIndex:String(10+i.layer)});z.addEventListener('pointerup',e=>{if(finished||blockFinished||dragState||!selectedChip||z.classList.contains('filled'))return;e.preventDefault();attemptDrop(z,selectedChip)});canvas.appendChild(z)});buildBank()
}
function buildBank(){bank.innerHTML='';shuffle(items()).forEach(i=>{const c=document.createElement('button');c.type='button';c.className='ddpv-chip'+(i.pic_url?'':' is-label');c.dataset.id=i.id;const token=document.createElement(i.pic_url?'img':'span');if(i.pic_url){token.src=i.pic_url;token.alt=i.label||'';token.style.transform=tr(i)}else{token.className='ddpv-chip-label';token.textContent=i.label}c.appendChild(token);c.onclick=e=>{e.preventDefault();if(!finished&&!blockFinished)selectChip(c)};c.addEventListener('pointerdown',startDrag);bank.appendChild(c)})}
function startDrag(e){if(finished||blockFinished||e.button>0)return;const chip=e.currentTarget;e.preventDefault();selectChip(chip);chip.classList.add('dragging');chip.setPointerCapture(e.pointerId);const item=itemById(chip.dataset.id),ghost=document.createElement(item.pic_url?'img':'div');ghost.className='ddpv-drag-image'+(item.pic_url?'':' is-label');if(item.pic_url){ghost.src=item.pic_url;ghost.style.transform=`translate(-50%,-50%) scale(1.08) ${tr(item)}`}else{ghost.textContent=item.label;ghost.style.transform='translate(-50%,-50%) scale(1.08)'};document.body.appendChild(ghost);dragState={pointerId:e.pointerId,chip,ghost,startX:e.clientX,startY:e.clientY,moved:false};moveGhost(e);chip.addEventListener('pointermove',dragMove);chip.addEventListener('pointerup',dragEnd);chip.addEventListener('pointercancel',dragEnd)}function moveGhost(e){if(!dragState)return;dragState.ghost.style.left=e.clientX+'px';dragState.ghost.style.top=e.clientY+'px'}function dragMove(e){if(!dragState||e.pointerId!==dragState.pointerId)return;e.preventDefault();moveGhost(e);if(Math.hypot(e.clientX-dragState.startX,e.clientY-dragState.startY)>5)dragState.moved=true;document.querySelectorAll('.ddpv-zone.over').forEach(z=>z.classList.remove('over'));const z=document.elementFromPoint(e.clientX,e.clientY)?.closest('.ddpv-zone');if(z&&!z.classList.contains('filled'))z.classList.add('over')}function dragEnd(e){if(!dragState||e.pointerId!==dragState.pointerId)return;e.preventDefault();const s=dragState;s.chip.removeEventListener('pointermove',dragMove);s.chip.removeEventListener('pointerup',dragEnd);s.chip.removeEventListener('pointercancel',dragEnd);s.chip.classList.remove('dragging');s.ghost.remove();document.querySelectorAll('.ddpv-zone.over').forEach(z=>z.classList.remove('over'));const z=document.elementFromPoint(e.clientX,e.clientY)?.closest('.ddpv-zone');dragState=null;if(z&&!z.classList.contains('filled'))attemptDrop(z,s.chip);else if(s.moved)setFeedback('Drop the item on a matching place.','bad')}
function placeImage(z,i){const placed=document.createElement(i.pic_url?'img':'div');placed.className='ddpv-placed'+(i.pic_url?'':' is-label');if(i.pic_url){placed.src=i.pic_url;placed.alt=i.label||'';placed.style.transform=tr(i)}else{placed.textContent=i.label}Object.assign(placed.style,{left:z.style.left,top:z.style.top,width:z.style.width,height:z.style.height,zIndex:String(100+(Number(i.layer)||0))});canvas.appendChild(placed);z.classList.add('filled')}
function attemptDrop(z,c){if(finished||blockFinished)return;if(String(c.dataset.id)===String(z.dataset.id)){placeImage(z,itemById(c.dataset.id));c.remove();clearSelection();blockCorrect++;setFeedback(blockCorrect===items().length?'All items placed. Press Check to see your score.':'Correct!','good')}else{wrongAttempts++;blockMistakes++;z.classList.add('wrong');setFeedback('Try a different spot.','bad');setTimeout(()=>z.classList.remove('wrong'),450)}}
function completeBlock(){
    if(finished||blockFinished)return;if(blockCorrect<items().length){alert('Place all items before checking!');return}
    blockFinished=true;const good=Math.max(0,items().length-Math.min(items().length,blockMistakes)),wrong=items().length-good,pct=items().length?Math.round(good/items().length*100):0;
    for(let i=0;i<items().length;i++)scores.push(i<good?1:0);
    bank.innerHTML='';scoreCorrect.textContent=good;scoreWrong.textContent=wrong;scorePercent.textContent=pct+'%';scoreGrid.classList.add('visible');checkBtn.disabled=true;
    nextBtn.textContent=blockIndex<BLOCKS.length-1?'Next Block':'Finish';nextBtn.style.display='inline-flex';setFeedback(blockIndex<BLOCKS.length-1?'Score ready. Continue to the next block.':'Score ready. Press Finish to complete the activity.','good')
}
function showAnswers(){if(finished||blockFinished)return;showedAnswers=true;items().forEach(i=>{const z=zoneById(i.id);if(z&&!z.classList.contains('filled'))placeImage(z,i)});bank.innerHTML='';clearSelection();blockCorrect=items().length;blockMistakes=Math.max(blockMistakes,items().length);wrongAttempts+=items().length;setFeedback('Answers shown. Press Check to see your score.','bad')}
function restart(){blockIndex=0;finished=false;blockFinished=false;showedAnswers=false;wrongAttempts=0;scores=[];completed.dataset.rendered='0';completed.classList.remove('active');playArea.style.display='';toolbar.style.display='flex';blockHead.style.display='';feedback.style.display='';controls.style.display='flex';scoreGrid.style.display='';nextBtn.style.display='none';renderBlock();applyZoom()}
async function finishActivity(){
    if(completed.dataset.rendered==='1')return;finished=true;completed.dataset.rendered='1';
    const correct=scores.reduce((a,b)=>a+b,0),wrong=Math.max(0,TOTAL_ITEMS-correct),pct=TOTAL_ITEMS?Math.round(correct/TOTAL_ITEMS*100):0;
    playArea.style.display='none';toolbar.style.display='none';blockHead.style.display='none';feedback.style.display='none';scoreGrid.style.display='none';controls.style.display='none';
    completedTitle.textContent=activityTitle();completedCorrect.textContent=correct;completedWrong.textContent=wrong;completedPercent.textContent=pct+'%';completedScore.textContent=correct+' correct · '+wrong+' wrong · '+pct+'%';completed.classList.add('active');
    if(ACTIVITY_ID&&RETURN_TO){const j=RETURN_TO.includes('?')?'&':'?',u=RETURN_TO+j+'activity_percent='+pct+'&activity_errors='+wrongAttempts+'&activity_total='+TOTAL_ITEMS+'&activity_id='+encodeURIComponent(ACTIVITY_ID)+'&activity_type=dragdrop_pic';try{await fetch(u,{method:'GET',credentials:'same-origin',cache:'no-store'})}catch(e){console.warn('Score persistence failed',e)}}
}
function applyZoom(){zoomTarget.style.transform=`scale(${zoom})`;zoomReset.textContent=Math.round(zoom*100)+'%'}
nextBtn.onclick=()=>{if(!blockFinished)return;if(blockIndex<BLOCKS.length-1){blockIndex++;renderBlock()}else finishActivity()};checkBtn.onclick=completeBlock;document.getElementById('ddpvZoomIn').onclick=()=>{zoom=Math.min(2,Number((zoom+.1).toFixed(2)));applyZoom()};document.getElementById('ddpvZoomOut').onclick=()=>{zoom=Math.max(.6,Number((zoom-.1).toFixed(2)));applyZoom()};zoomReset.onclick=()=>{zoom=1;applyZoom()};document.getElementById('ddpvShow').onclick=showAnswers;document.getElementById('ddpvRetry').onclick=restart;document.getElementById('ddpvCompletedRestart').onclick=restart;renderBlock();applyZoom();
})();
</script>
<?php $content=ob_get_clean();render_activity_viewer($title,'🖼️',$content);