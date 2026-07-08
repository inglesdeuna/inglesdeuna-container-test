<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string)$_GET['unit']) : '';
$returnTo = isset($_GET['return_to']) ? trim((string)$_GET['return_to']) : '';
$isStaff = !empty($_SESSION['academic_logged']) || !empty($_SESSION['admin_logged']);
if ($activityId === '' && $unit === '') die('Activity not specified');

function mzk_default_title(): string { return 'Vocabulary Maze'; }
function mzk_clean_positions($raw): array {
    $out = [];
    if (is_string($raw)) { $d = json_decode($raw, true); $raw = is_array($d) ? $d : []; }
    if (!is_array($raw)) return $out;
    foreach ($raw as $key => $p) {
        if (!preg_match('/^(path|branch)_\d+$/', (string)$key) || !is_array($p)) continue;
        $x = isset($p['x']) ? (float)$p['x'] : null;
        $y = isset($p['y']) ? (float)$p['y'] : null;
        if ($x === null || $y === null || !is_finite($x) || !is_finite($y)) continue;
        $out[(string)$key] = ['x' => $x, 'y' => $y];
    }
    return $out;
}
function mzk_normalize_payload($raw): array {
    $default = ['title'=>mzk_default_title(),'theme'=>'','difficulty'=>'medium','vocabulary_bank'=>[],'path_sequence'=>[],'distractor_branches'=>[],'layout_positions'=>[],'audio_urls'=>[]];
    if ($raw === null || $raw === '') return $default;
    $d = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($d)) return $default;
    $bank = [];
    foreach (($d['vocabulary_bank'] ?? []) as $item) {
        if (!is_array($item)) continue;
        $word = trim((string)($item['word'] ?? ''));
        $img = trim((string)($item['image_url'] ?? ''));
        if ($word === '' && $img === '') continue;
        $bank[] = ['id'=>trim((string)($item['id'] ?? uniqid('mzk_'))) ?: uniqid('mzk_'),'image_url'=>$img,'word'=>$word];
    }
    $bankIds = array_column($bank, 'id');
    $path = [];
    foreach (($d['path_sequence'] ?? []) as $vid) { $vid = trim((string)$vid); if ($vid !== '' && in_array($vid, $bankIds, true)) $path[] = $vid; }
    if (!$path && $bankIds) $path = $bankIds;
    $branches = [];
    foreach (($d['distractor_branches'] ?? []) as $br) {
        if (!is_array($br)) continue;
        $vid = trim((string)($br['vocabulary_id'] ?? ''));
        if ($vid === '' || !in_array($vid, $bankIds, true)) continue;
        $after = max(0, min(max(0, count($path)-1), (int)($br['attach_after_index'] ?? 0)));
        $branches[] = ['attach_after_index'=>$after,'vocabulary_id'=>$vid];
    }
    $audio = [];
    if (is_array($d['audio_urls'] ?? null)) foreach ($d['audio_urls'] as $vid => $url) { $vid=trim((string)$vid); $url=trim((string)$url); if ($vid!=='' && $url!=='') $audio[$vid]=$url; }
    $difficulty = trim((string)($d['difficulty'] ?? 'medium'));
    if (!in_array($difficulty, ['easy','medium','hard'], true)) $difficulty = 'medium';
    return ['title'=>trim((string)($d['title'] ?? '')) ?: mzk_default_title(),'theme'=>trim((string)($d['theme'] ?? '')),'difficulty'=>$difficulty,'vocabulary_bank'=>$bank,'path_sequence'=>$path,'distractor_branches'=>$branches,'layout_positions'=>mzk_clean_positions($d['layout_positions'] ?? []),'audio_urls'=>$audio];
}
function mzk_resolve_unit(PDO $pdo, string $activityId): string { if ($activityId==='') return ''; $s=$pdo->prepare('SELECT unit_id FROM activities WHERE id=:id LIMIT 1'); $s->execute(['id'=>$activityId]); $r=$s->fetch(PDO::FETCH_ASSOC); return $r && isset($r['unit_id']) ? (string)$r['unit_id'] : ''; }
function mzk_load(PDO $pdo, string $activityId, string $unit): array {
    $fallback = array_merge(['id'=>''], mzk_normalize_payload(null));
    $row = null;
    if ($activityId !== '') { $s=$pdo->prepare("SELECT id,data FROM activities WHERE id=:id AND type='maze_kids' LIMIT 1"); $s->execute(['id'=>$activityId]); $row=$s->fetch(PDO::FETCH_ASSOC); }
    if (!$row && $unit !== '') { $s=$pdo->prepare("SELECT id,data FROM activities WHERE unit_id=:unit AND type='maze_kids' ORDER BY id ASC LIMIT 1"); $s->execute(['unit'=>$unit]); $row=$s->fetch(PDO::FETCH_ASSOC); }
    if (!$row) return $fallback;
    return array_merge(['id'=>(string)($row['id'] ?? '')], mzk_normalize_payload($row['data'] ?? null));
}
if ($unit === '' && $activityId !== '') $unit = mzk_resolve_unit($pdo, $activityId);
$activity = mzk_load($pdo, $activityId, $unit);
$viewerTitle = $activity['title'];
if ($activityId === '' && !empty($activity['id'])) $activityId = $activity['id'];
if (count($activity['path_sequence']) === 0) {
    ob_start(); ?><div style="padding:40px;text-align:center;font-family:Nunito,Arial,sans-serif"><h2><?php echo htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8'); ?></h2><p>This maze needs a path sequence before students can play.</p><?php if ($isStaff): ?><a href="editor.php?unit=<?php echo urlencode($unit); ?><?php echo $activityId!==''?'&id='.urlencode($activityId):''; ?>">Configure maze</a><?php endif; ?></div><?php
    $content = ob_get_clean(); render_activity_viewer($viewerTitle, 'Maze', $content); exit;
}
$bankById = [];
foreach ($activity['vocabulary_bank'] as $item) $bankById[$item['id']] = $item;
ob_start();
?>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">
<style>
:root{--mz-orange:#F97316;--mz-purple:#7F77DD;--mz-purple-dark:#534AB7;--mz-purple-soft:#EEEDFE;--mz-muted:#9B94BE;--mz-green:#16a34a;--mz-green-soft:#f0fdf4;--mz-green-dark:#15803d;--mz-red:#ef4444;--mz-red-soft:#fef2f2;--mz-red-light:#FCA5A5;--wall:#CDC7F3}
html,body{width:100%;min-height:100%}
body{margin:0!important;padding:0!important;background:#F8F7FF!important;font-family:'Nunito','Segoe UI',sans-serif!important;overflow-x:hidden}
.activity-wrapper{max-width:100%!important;margin:0!important;padding:0!important;background:transparent!important}
.top-row,.activity-header,.activity-title,.activity-subtitle{display:none!important}
.viewer-content{padding:0!important;margin:0!important;background:transparent!important;border:none!important;box-shadow:none!important;border-radius:0!important;overflow:visible!important}
.mzk-page{width:100%;min-height:calc(100vh - 44px);padding:8px 12px 10px;display:flex;align-items:stretch;justify-content:center;background:#F8F7FF;box-sizing:border-box}
.mzk-app{width:min(1360px,100%);display:flex;flex-direction:column;gap:8px;margin:0 auto}
.mzk-topbar{display:flex;align-items:center;justify-content:space-between;gap:10px;min-height:34px}
.mzk-title-block{display:flex;align-items:center;gap:10px;min-width:0}
.mzk-kicker{display:inline-flex;padding:5px 12px;border-radius:999px;background:#FFF0E6;border:1px solid #FCDDBF;color:#C2580A;font-size:11px;font-weight:900;letter-spacing:.08em;text-transform:uppercase;white-space:nowrap}
.mzk-title{font-family:'Fredoka',sans-serif;font-size:clamp(18px,2vw,28px);color:var(--mz-orange);margin:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mzk-scorebar{display:flex;align-items:center;gap:10px;min-width:min(480px,44vw)}
.mzk-score-label{font-size:12px;font-weight:900;color:var(--mz-purple-dark);white-space:nowrap}
.mzk-track{flex:1;height:10px;background:var(--mz-purple-soft);border-radius:999px;overflow:hidden}
.mzk-fill{height:100%;background:linear-gradient(90deg,var(--mz-orange),var(--mz-purple));border-radius:999px;transition:width .25s}
.mzk-badge{min-width:86px;text-align:center;padding:6px 10px;border-radius:999px;background:var(--mz-purple);color:#fff;font-size:12px;font-weight:900;white-space:nowrap}
.mzk-stage{flex:1;min-height:0;background:#fff;border:1px solid #F0EEF8;border-radius:26px;padding:10px;box-shadow:0 8px 40px rgba(127,119,221,.13);width:100%;box-sizing:border-box;display:flex;flex-direction:column;overflow:hidden}
.mzk-maze-wrap{flex:1;min-height:0;display:flex;align-items:center;justify-content:center;overflow:auto;padding:2px}
.mzk-maze-wrap svg{display:block;width:100%;height:100%;max-width:100%;max-height:calc(100vh - 154px);object-fit:contain}
.mzk-node{cursor:pointer}.mzk-node-circle{fill:#fff;stroke:none}.mzk-node.done .mzk-node-circle{stroke:var(--mz-green);stroke-width:4;fill:var(--mz-green-soft)}.mzk-node.wrong .mzk-node-circle{stroke:var(--mz-red);stroke-width:4;fill:var(--mz-red-soft)}.mzk-node image{pointer-events:none}.mzk-node-label{font-family:'Nunito',sans-serif;font-weight:900;font-size:10px;fill:var(--mz-purple-dark);pointer-events:none}.mzk-node-badge{fill:var(--mz-purple)}.mzk-node-badge-dead{fill:var(--mz-red-light)}.mzk-node-badge-text{font-family:'Fredoka',sans-serif;font-weight:700;font-size:12px;fill:#fff;pointer-events:none}.mzk-node-flag{font-family:'Nunito',sans-serif;font-weight:900;font-size:9px;letter-spacing:.05em}.mzk-node.shake{animation:mzkShake .4s}@keyframes mzkShake{10%,90%{transform:translateX(-2px)}20%,80%{transform:translateX(3px)}30%,50%,70%{transform:translateX(-5px)}40%,60%{transform:translateX(5px)}}
#mzkFeedback{text-align:center;font-size:13px;font-weight:900;min-height:18px;margin:4px 0 0}#mzkFeedback.good{color:var(--mz-green-dark)}#mzkFeedback.bad{color:#b91c1c}
.mzk-completed-screen{display:none}.mzk-completed-screen.active{display:block}
.mzk-controls{border-top:1px solid #F0EEF8;margin-top:8px;padding-top:8px;display:flex;justify-content:center;gap:10px;flex-wrap:wrap;text-align:center}
.mzk-btn{padding:10px 18px;border:none;border-radius:10px;background:var(--mz-purple);color:#fff;cursor:pointer;font-weight:900;font-family:'Nunito',sans-serif;font-size:13px;box-shadow:0 6px 18px rgba(127,119,221,.18)}
.mzk-btn.secondary{background:#F97316}.mzk-btn.next{background:#7F77DD}.mzk-answer-path{fill:none;stroke:#F97316;stroke-width:12;stroke-linecap:round;stroke-linejoin:round;stroke-dasharray:18 12;filter:drop-shadow(0 3px 5px rgba(249,115,22,.35));pointer-events:none}.mzk-answer-dot{fill:#F97316;stroke:#fff;stroke-width:4;pointer-events:none}
@media(max-width:760px){.mzk-page{padding:6px}.mzk-topbar{align-items:stretch;flex-direction:column}.mzk-scorebar{min-width:0;width:100%}.mzk-stage{border-radius:20px;padding:8px}.mzk-maze-wrap svg{max-height:calc(100vh - 206px)}.mzk-title{font-size:18px}.mzk-controls{gap:6px}.mzk-btn{padding:9px 12px}}
</style>
<div class="mzk-page"><div class="mzk-app"><div class="mzk-topbar"><div class="mzk-title-block"><div class="mzk-kicker">Maze</div><h1 class="mzk-title"><?php echo htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8'); ?></h1></div><div class="mzk-scorebar"><span class="mzk-score-label">Score</span><div class="mzk-track"><div class="mzk-fill" id="mzkFill" style="width:0%"></div></div><div class="mzk-badge" id="mzkBadge">0 / <?php echo count($activity['path_sequence']); ?></div></div></div><div class="mzk-stage"><div class="mzk-maze-wrap" id="mzkMazeWrap"></div><div id="mzkFeedback"></div><div class="mzk-completed-screen" id="mzkCompleted"></div><div class="mzk-controls" id="mzkControls"><button class="mzk-btn secondary" type="button" onclick="mzkRestart()">Restart</button><button class="mzk-btn" type="button" onclick="mzkShowAnswers()">Show Answers</button><button class="mzk-btn next" type="button" onclick="mzkGoNext()" id="mzkNextBtn">Next</button></div></div></div></div>
<audio id="mzkTtsAudio" preload="none"></audio><audio id="mzkWinAudio" src="../../hangman/assets/win.mp3" preload="auto"></audio><audio id="mzkLoseAudio" src="../../hangman/assets/lose.mp3" preload="auto"></audio><audio id="mzkWrongAudio" src="../../hangman/assets/wrong.wav" preload="auto"></audio><audio id="mzkDoneAudio" src="../../hangman/assets/WINNING%20CLAPS.mp3" preload="auto"></audio>
<script src="../../core/_activity_feedback.js"></script><script src="maze_layout.js"></script><script>
const MZK_TITLE=<?php echo json_encode($viewerTitle, JSON_UNESCAPED_UNICODE); ?>,MZK_ACTIVITY_ID=<?php echo json_encode($activityId, JSON_UNESCAPED_UNICODE); ?>,MZK_RETURN_TO=<?php echo json_encode($returnTo, JSON_UNESCAPED_UNICODE); ?>,MZK_BANK_BY_ID=<?php echo json_encode($bankById, JSON_UNESCAPED_UNICODE); ?>,MZK_PATH=<?php echo json_encode($activity['path_sequence'], JSON_UNESCAPED_UNICODE); ?>,MZK_BRANCHES=<?php echo json_encode($activity['distractor_branches'], JSON_UNESCAPED_UNICODE); ?>,MZK_LAYOUT_POSITIONS=<?php echo json_encode((object)$activity['layout_positions'], JSON_UNESCAPED_UNICODE); ?>,MZK_AUDIO_URLS=<?php echo json_encode($activity['audio_urls'], JSON_UNESCAPED_UNICODE); ?>,MZK_THEME=<?php echo json_encode($activity['theme'], JSON_UNESCAPED_UNICODE); ?>,MZK_TTS_URL='tts.php';
let mzkNextIndex=0,mzkDone=false,mzkScores=[],mzkLastLayout=null;
function mzkPlay(el){try{if(!el)return;el.pause();el.currentTime=0;el.play();}catch(e){}}
function mzkNodeVocab(node){return MZK_BANK_BY_ID[node.vocabularyId]||{image_url:'',word:''};}
function mzkSetFeedback(text,cls){const el=document.getElementById('mzkFeedback');el.textContent=text;el.className=cls||'';}
function mzkUpdateProgress(value){if(typeof value==='number')mzkNextIndex=value;const total=Math.max(1,MZK_PATH.length),pct=Math.round(mzkNextIndex/total*100);document.getElementById('mzkFill').style.width=Math.max(pct,0)+'%';document.getElementById('mzkBadge').textContent='Score '+mzkNextIndex+' / '+MZK_PATH.length;}
function mzkSyncArrowProgress(value){mzkNextIndex=Math.max(0,Math.min(MZK_PATH.length,value||0));mzkScores=Array(mzkNextIndex).fill(1);mzkUpdateProgress();}
function mzkClearAnswerPath(){const svg=document.querySelector('#mzkMazeWrap svg');if(!svg)return;svg.querySelectorAll('.mzk-answer-path,.mzk-answer-dot').forEach(el=>el.remove());}
function mzkRenderMazeBaseSafe(NS,svg,layout){mzkRenderMazeBase(NS,svg,layout,{wallColor:'var(--wall)',floorColor:'#ffffff',dotColor:'rgba(83,74,183,.10)'});}
function mzkBuildMaze(){const layout=generateMazeLayout(MZK_PATH,MZK_BRANCHES,MZK_LAYOUT_POSITIONS);mzkLastLayout=layout;const NS='http://www.w3.org/2000/svg';const svg=document.createElementNS(NS,'svg');svg.setAttribute('viewBox','0 0 '+layout.width+' '+layout.height);svg.setAttribute('width',layout.width);svg.setAttribute('height',layout.height);svg.setAttribute('preserveAspectRatio','xMidYMid meet');mzkRenderMazeBaseSafe(NS,svg,layout);const R=Math.round(layout.cellSize*0.32);(layout.fillerCells||[]).forEach((cell,i)=>{const g=mzkRenderFillerIcon(NS,MZK_THEME,i,layout.cellSize);g.setAttribute('transform','translate('+cell.x+','+cell.y+')');svg.appendChild(g);});layout.nodes.forEach(node=>{const isEndpoint=node.kind==='start'||node.kind==='home';const vocab=isEndpoint?{image_url:'',word:''}:mzkNodeVocab(node);const g=document.createElementNS(NS,'g');g.setAttribute('class','mzk-node'+(node.kind==='branch'?' branch':'')+(node.kind==='start'?' start':'')+(node.kind==='home'?' end':''));g.dataset.nodeId=node.id;g.setAttribute('transform','translate('+node.x+','+node.y+')');const nodeR=node.kind==='home'?Math.round(R*1.4):R;const circle=document.createElementNS(NS,'circle');circle.setAttribute('class','mzk-node-circle');circle.setAttribute('r',nodeR);g.appendChild(circle);if(isEndpoint){const eIcon=mzkRenderEndpointIcon(NS,node.kind);if(node.kind==='home')eIcon.setAttribute('transform','scale(1.4)');g.appendChild(eIcon);}else if(vocab.image_url){const img=document.createElementNS(NS,'image');img.setAttributeNS('http://www.w3.org/1999/xlink','href',vocab.image_url);img.setAttribute('href',vocab.image_url);img.setAttribute('x',-nodeR+3);img.setAttribute('y',-nodeR+3);img.setAttribute('width',(nodeR-3)*2);img.setAttribute('height',(nodeR-3)*2);img.setAttribute('clip-path','circle('+(nodeR-3)+'px)');img.setAttribute('preserveAspectRatio','xMidYMid slice');g.appendChild(img);}if(!isEndpoint&&vocab.word){const label=document.createElementNS(NS,'text');label.setAttribute('class','mzk-node-label');label.setAttribute('x',0);label.setAttribute('y',R+14);label.setAttribute('text-anchor','middle');label.textContent=vocab.word;g.appendChild(label);}if(!isEndpoint){const bc=document.createElementNS(NS,'circle');bc.setAttribute('class','mzk-node-badge'+(node.kind==='branch'?' mzk-node-badge-dead':''));bc.setAttribute('cx',R-7);bc.setAttribute('cy',-R+7);bc.setAttribute('r',11);g.appendChild(bc);const bt=document.createElementNS(NS,'text');bt.setAttribute('class','mzk-node-badge-text');bt.setAttribute('x',R-7);bt.setAttribute('y',-R+11);bt.setAttribute('text-anchor','middle');bt.textContent=node.kind==='path'?String(node.index+1):'x';g.appendChild(bt);}if(isEndpoint){const flag=document.createElementNS(NS,'text');flag.setAttribute('class','mzk-node-flag');flag.setAttribute('x',0);flag.setAttribute('y',-nodeR-11);flag.setAttribute('text-anchor','middle');flag.setAttribute('fill',node.kind==='start'?'var(--mz-orange)':'var(--mz-green)');flag.textContent=node.kind==='start'?'START':'HOME';g.appendChild(flag);}if(!isEndpoint)g.addEventListener('click',()=>{if(typeof window.mzkNodeTap==='function'&&window.mzkNodeTap(node))return;mzkHandleTap(node,g);});svg.appendChild(g);});const wrap=document.getElementById('mzkMazeWrap');wrap.innerHTML='';wrap.appendChild(svg);}
function mzkHandleTap(node,groupEl){if(mzkDone)return;if(node.kind==='path'&&node.index===mzkNextIndex){groupEl.classList.add('done');mzkPlay(document.getElementById('mzkWinAudio'));mzkSetFeedback('Great job!','good');mzkSpeakWord(node.vocabularyId);mzkScores.push(1);mzkNextIndex++;mzkUpdateProgress();if(mzkNextIndex>=MZK_PATH.length)setTimeout(mzkFinish,500);return;}groupEl.classList.add('wrong');mzkPlay(document.getElementById(node.kind==='branch'?'mzkWrongAudio':'mzkLoseAudio'));mzkSetFeedback(node.kind==='branch'?'Dead end! Restart and try another path.':'Not yet. Follow the path.','bad');groupEl.classList.add('shake');}
function mzkSpeakWord(vocabularyId){const vocab=MZK_BANK_BY_ID[vocabularyId]||{},word=(vocab.word||'').toLowerCase();if(!word)return;if(window.speechSynthesis){window.speechSynthesis.cancel();const u=new SpeechSynthesisUtterance(word);u.rate=0.8;u.pitch=1.05;u.lang='en-US';window.speechSynthesis.speak(u);}}
function mzkFinish(){mzkDone=true;mzkNextIndex=MZK_PATH.length;mzkScores=Array(MZK_PATH.length).fill(1);mzkUpdateProgress();mzkPlay(document.getElementById('mzkDoneAudio'));mzkSetFeedback('Completed!','good');const completedEl=document.getElementById('mzkCompleted');completedEl.classList.add('active');completedEl.innerHTML='';if(window.ActivityFeedback&&window.ActivityFeedback.showCompleted){window.ActivityFeedback.showCompleted({target:completedEl,scores:mzkScores,title:MZK_TITLE,activityType:'Maze',questionCount:MZK_PATH.length,onRetry:mzkRestart});}}
function mzkResetViewerState(){mzkNextIndex=0;mzkDone=false;mzkScores=[];document.getElementById('mzkCompleted').classList.remove('active');document.getElementById('mzkCompleted').innerHTML='';mzkSetFeedback('','');mzkUpdateProgress();}
function mzkRestart(){mzkResetViewerState();mzkBuildMaze();}
function mzkShowAnswers(){const svg=document.querySelector('#mzkMazeWrap svg');if(!svg)return;mzkClearAnswerPath();const layout=mzkLastLayout||generateMazeLayout(MZK_PATH,MZK_BRANCHES,MZK_LAYOUT_POSITIONS);const ordered=[];const start=layout.nodes.find(n=>n.kind==='start');const home=layout.nodes.find(n=>n.kind==='home');if(start)ordered.push(start);layout.nodes.filter(n=>n.kind==='path').sort((a,b)=>a.index-b.index).forEach(n=>ordered.push(n));if(home)ordered.push(home);if(ordered.length<2)return;const NS='http://www.w3.org/2000/svg';const d=ordered.map((n,i)=>(i?'L ':'M ')+n.x+' '+n.y).join(' ');const path=document.createElementNS(NS,'path');path.setAttribute('class','mzk-answer-path');path.setAttribute('d',d);svg.appendChild(path);ordered.forEach(n=>{const dot=document.createElementNS(NS,'circle');dot.setAttribute('class','mzk-answer-dot');dot.setAttribute('cx',n.x);dot.setAttribute('cy',n.y);dot.setAttribute('r',n.kind==='start'||n.kind==='home'?12:8);svg.appendChild(dot);});mzkSetFeedback('Answer path shown. Follow the orange line from START to HOME.','good');}
function mzkGoNext(){const candidates=Array.from(document.querySelectorAll('a,button')).filter(el=>el.id!=='mzkNextBtn');const target=candidates.find(el=>/next|finish unit|continue/i.test((el.textContent||'').trim())&&el.offsetParent!==null);if(target){target.click();return;}if(MZK_RETURN_TO){window.location.href=MZK_RETURN_TO;return;}window.history.back();}
mzkBuildMaze();mzkUpdateProgress();
</script><?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, 'Maze', $content);
