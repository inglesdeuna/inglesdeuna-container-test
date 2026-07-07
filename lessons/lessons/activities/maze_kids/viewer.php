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
    $content = ob_get_clean(); render_activity_viewer($viewerTitle, '🧩', $content); exit;
}
$bankById = [];
foreach ($activity['vocabulary_bank'] as $item) $bankById[$item['id']] = $item;
ob_start();
?>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">
<style>
:root{--mz-orange:#F97316;--mz-purple:#7F77DD;--mz-purple-dark:#534AB7;--mz-purple-soft:#EEEDFE;--mz-muted:#9B94BE;--mz-green:#16a34a;--mz-green-soft:#f0fdf4;--mz-green-dark:#15803d;--mz-red:#ef4444;--mz-red-soft:#fef2f2;--mz-red-light:#FCA5A5;--wall:#CDC7F3}html,body{width:100%;min-height:100%}body{margin:0!important;padding:0!important;background:#fff!important;font-family:'Nunito','Segoe UI',sans-serif!important}.activity-wrapper{max-width:100%!important;margin:0!important;padding:0!important;background:transparent!important}.top-row,.activity-header,.activity-title,.activity-subtitle{display:none!important}.viewer-content{padding:0!important;margin:0!important;background:transparent!important;border:none!important;box-shadow:none!important;border-radius:0!important}.mzk-page{width:100%;min-height:calc(100vh - 54px);padding:12px 18px;display:flex;align-items:flex-start;justify-content:center;background:#F8F7FF;box-sizing:border-box}.mzk-app{width:min(1040px,100%);margin:0 auto}.mzk-hero{text-align:center;margin-bottom:8px}.mzk-kicker{display:inline-flex;padding:5px 12px;border-radius:999px;background:#FFF0E6;border:1px solid #FCDDBF;color:#C2580A;font-size:11px;font-weight:900;letter-spacing:.08em;text-transform:uppercase;margin-bottom:5px}.mzk-hero h1{font-family:'Fredoka',sans-serif;font-size:clamp(20px,3vw,32px);color:var(--mz-orange);margin:0}.mzk-hero p{font-size:13px;font-weight:800;color:var(--mz-muted);margin:4px 0 0}.mzk-progress{display:flex;align-items:center;gap:10px;margin-bottom:8px}.mzk-track{flex:1;height:9px;background:var(--mz-purple-soft);border-radius:999px;overflow:hidden}.mzk-fill{height:100%;background:linear-gradient(90deg,var(--mz-orange),var(--mz-purple));border-radius:999px;transition:width .35s}.mzk-badge{min-width:70px;text-align:center;padding:5px 9px;border-radius:999px;background:var(--mz-purple);color:#fff;font-size:12px;font-weight:900}.mzk-stage{background:#fff;border:1px solid #F0EEF8;border-radius:28px;padding:12px;box-shadow:0 8px 40px rgba(127,119,221,.13);width:100%;box-sizing:border-box;overflow:auto}.mzk-maze-wrap{display:flex;justify-content:center}.mzk-maze-wrap svg{display:block;width:100%;max-width:980px;height:auto;max-height:calc(100vh - 235px)}.mzk-node{cursor:pointer}.mzk-node-circle{fill:#fff;stroke:var(--mz-purple);stroke-width:3}.mzk-node.branch .mzk-node-circle{stroke:var(--mz-red-light)}.mzk-node.start .mzk-node-circle{stroke:var(--mz-orange);stroke-width:4}.mzk-node.end .mzk-node-circle{stroke:var(--mz-green);stroke-width:4}.mzk-node.done .mzk-node-circle{stroke:var(--mz-green);fill:var(--mz-green-soft)}.mzk-node.wrong .mzk-node-circle{stroke:var(--mz-red);fill:var(--mz-red-soft)}.mzk-node image{pointer-events:none}.mzk-node-label{font-family:'Nunito',sans-serif;font-weight:900;font-size:10px;fill:var(--mz-purple-dark);pointer-events:none}.mzk-node-badge{fill:var(--mz-purple)}.mzk-node-badge-dead{fill:var(--mz-red-light)}.mzk-node-badge-text{font-family:'Fredoka',sans-serif;font-weight:700;font-size:12px;fill:#fff;pointer-events:none}.mzk-node-flag{font-family:'Nunito',sans-serif;font-weight:900;font-size:9px;letter-spacing:.05em}.mzk-node.shake{animation:mzkShake .4s}@keyframes mzkShake{10%,90%{transform:translateX(-2px)}20%,80%{transform:translateX(3px)}30%,50%,70%{transform:translateX(-5px)}40%,60%{transform:translateX(5px)}}#mzkFeedback{text-align:center;font-size:13px;font-weight:900;min-height:18px;margin-top:6px}#mzkFeedback.good{color:var(--mz-green-dark)}#mzkFeedback.bad{color:#b91c1c}.mzk-completed-screen{display:none}.mzk-completed-screen.active{display:block}.mzk-controls{border-top:1px solid #F0EEF8;margin-top:10px;padding-top:10px;text-align:center}.mzk-btn{padding:10px 18px;border:none;border-radius:8px;background:var(--mz-purple);color:#fff;cursor:pointer;font-weight:900;font-family:'Nunito',sans-serif;font-size:13px;box-shadow:0 6px 18px rgba(127,119,221,.18)}@media(max-width:760px){.mzk-page{padding:10px}.mzk-stage{border-radius:22px}.mzk-maze-wrap svg{max-height:none}}
</style>
<div class="mzk-page"><div class="mzk-app"><div class="mzk-hero"><div class="mzk-kicker">Maze</div><h1><?php echo htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8'); ?></h1><p>Tap the pictures in order to find your way through the maze!</p></div><div class="mzk-progress"><div class="mzk-track"><div class="mzk-fill" id="mzkFill" style="width:0%"></div></div><div class="mzk-badge" id="mzkBadge">0 / <?php echo count($activity['path_sequence']); ?></div></div><div class="mzk-stage"><div class="mzk-maze-wrap" id="mzkMazeWrap"></div><div id="mzkFeedback"></div><div class="mzk-completed-screen" id="mzkCompleted"></div><div class="mzk-controls" id="mzkControls"><button class="mzk-btn" type="button" onclick="mzkRestart()">Start Over</button></div></div></div></div>
<audio id="mzkTtsAudio" preload="none"></audio><audio id="mzkWinAudio" src="../../hangman/assets/win.mp3" preload="auto"></audio><audio id="mzkLoseAudio" src="../../hangman/assets/lose.mp3" preload="auto"></audio><audio id="mzkDoneAudio" src="../../hangman/assets/win (1).mp3" preload="auto"></audio>
<script src="../../core/_activity_feedback.js"></script><script src="maze_layout.js"></script><script>
const MZK_TITLE=<?php echo json_encode($viewerTitle, JSON_UNESCAPED_UNICODE); ?>,MZK_ACTIVITY_ID=<?php echo json_encode($activityId, JSON_UNESCAPED_UNICODE); ?>,MZK_RETURN_TO=<?php echo json_encode($returnTo, JSON_UNESCAPED_UNICODE); ?>,MZK_BANK_BY_ID=<?php echo json_encode($bankById, JSON_UNESCAPED_UNICODE); ?>,MZK_PATH=<?php echo json_encode($activity['path_sequence'], JSON_UNESCAPED_UNICODE); ?>,MZK_BRANCHES=<?php echo json_encode($activity['distractor_branches'], JSON_UNESCAPED_UNICODE); ?>,MZK_LAYOUT_POSITIONS=<?php echo json_encode((object)$activity['layout_positions'], JSON_UNESCAPED_UNICODE); ?>,MZK_AUDIO_URLS=<?php echo json_encode($activity['audio_urls'], JSON_UNESCAPED_UNICODE); ?>,MZK_TTS_URL='tts.php';
let mzkNextIndex=0,mzkDone=false,mzkScores=[];function mzkPlay(el){try{el.pause();el.currentTime=0;el.play();}catch(e){}}function mzkNodeVocab(node){return MZK_BANK_BY_ID[node.vocabularyId]||{image_url:'',word:''};}
function mzkBuildMaze(){const layout=generateMazeLayout(MZK_PATH,MZK_BRANCHES);const NS='http://www.w3.org/2000/svg';const svg=document.createElementNS(NS,'svg');svg.setAttribute('viewBox','0 0 '+layout.width+' '+layout.height);svg.setAttribute('width',layout.width);svg.setAttribute('height',layout.height);mzkRenderMazeBase(NS,svg,layout,{wallColor:'var(--wall)',floorColor:'#ffffff',dotColor:'rgba(83,74,183,.10)'});const R=Math.round(layout.cellSize*0.32);layout.nodes.forEach(node=>{const isEndpoint=node.kind==='start'||node.kind==='home';const vocab=isEndpoint?{image_url:'',word:''}:mzkNodeVocab(node);const g=document.createElementNS(NS,'g');g.setAttribute('class','mzk-node'+(node.kind==='branch'?' branch':'')+(node.kind==='start'?' start':'')+(node.kind==='home'?' end':''));g.setAttribute('transform','translate('+node.x+','+node.y+')');const circle=document.createElementNS(NS,'circle');circle.setAttribute('class','mzk-node-circle');circle.setAttribute('r',R);g.appendChild(circle);if(isEndpoint){g.appendChild(mzkRenderEndpointIcon(NS,node.kind));}else if(vocab.image_url){const img=document.createElementNS(NS,'image');img.setAttributeNS('http://www.w3.org/1999/xlink','href',vocab.image_url);img.setAttribute('href',vocab.image_url);img.setAttribute('x',-R+5);img.setAttribute('y',-R+5);img.setAttribute('width',(R-5)*2);img.setAttribute('height',(R-5)*2);img.setAttribute('clip-path','circle('+(R-5)+'px)');img.setAttribute('preserveAspectRatio','xMidYMid slice');g.appendChild(img);}if(!isEndpoint&&vocab.word){const label=document.createElementNS(NS,'text');label.setAttribute('class','mzk-node-label');label.setAttribute('x',0);label.setAttribute('y',R+14);label.setAttribute('text-anchor','middle');label.textContent=vocab.word;g.appendChild(label);}if(!isEndpoint){const bc=document.createElementNS(NS,'circle');bc.setAttribute('class','mzk-node-badge'+(node.kind==='branch'?' mzk-node-badge-dead':''));bc.setAttribute('cx',R-7);bc.setAttribute('cy',-R+7);bc.setAttribute('r',11);g.appendChild(bc);const bt=document.createElementNS(NS,'text');bt.setAttribute('class','mzk-node-badge-text');bt.setAttribute('x',R-7);bt.setAttribute('y',-R+11);bt.setAttribute('text-anchor','middle');bt.textContent=node.kind==='path'?String(node.index+1):'x';g.appendChild(bt);}if(isEndpoint){const flag=document.createElementNS(NS,'text');flag.setAttribute('class','mzk-node-flag');flag.setAttribute('x',0);flag.setAttribute('y',-R-11);flag.setAttribute('text-anchor','middle');flag.setAttribute('fill',node.kind==='start'?'var(--mz-orange)':'var(--mz-green)');flag.textContent=node.kind==='start'?'START':'HOME';g.appendChild(flag);}if(!isEndpoint)g.addEventListener('click',()=>mzkHandleTap(node,g));svg.appendChild(g);});const wrap=document.getElementById('mzkMazeWrap');wrap.innerHTML='';wrap.appendChild(svg);}
function mzkSetFeedback(text,cls){const el=document.getElementById('mzkFeedback');el.textContent=text;el.className=cls||'';}function mzkUpdateProgress(){const total=MZK_PATH.length,pct=Math.round(mzkNextIndex/total*100);document.getElementById('mzkFill').style.width=Math.max(pct,4)+'%';document.getElementById('mzkBadge').textContent=mzkNextIndex+' / '+total;}function mzkHandleTap(node,groupEl){if(mzkDone)return;if(node.kind==='path'&&node.index===mzkNextIndex){groupEl.classList.add('done');mzkPlay(document.getElementById('mzkWinAudio'));mzkSetFeedback('Great job!','good');mzkSpeakWord(node.vocabularyId);mzkScores.push(1);mzkNextIndex++;mzkUpdateProgress();if(mzkNextIndex>=MZK_PATH.length)setTimeout(mzkFinish,500);return;}groupEl.classList.add('wrong');mzkPlay(document.getElementById('mzkLoseAudio'));mzkSetFeedback(node.kind==='branch'?'Dead end! Try another path.':'Not yet. Follow the numbers in order.','bad');groupEl.classList.add('shake');setTimeout(()=>{groupEl.classList.remove('shake');groupEl.classList.remove('wrong');},450);}function mzkSpeakWord(vocabularyId){const vocab=MZK_BANK_BY_ID[vocabularyId]||{},word=(vocab.word||'').toLowerCase();if(!word)return;if(window.speechSynthesis){window.speechSynthesis.cancel();const u=new SpeechSynthesisUtterance(word);u.rate=0.8;u.pitch=1.05;u.lang='en-US';window.speechSynthesis.speak(u);}}
function mzkFinish(){mzkDone=true;mzkPlay(document.getElementById('mzkDoneAudio'));document.getElementById('mzkControls').style.display='none';mzkSetFeedback('','');const completedEl=document.getElementById('mzkCompleted');completedEl.classList.add('active');completedEl.innerHTML='';window.ActivityFeedback.showCompleted({target:completedEl,scores:mzkScores,title:MZK_TITLE,activityType:'Maze',questionCount:MZK_PATH.length,onRetry:mzkRestart});}
function mzkRestart(){mzkNextIndex=0;mzkDone=false;mzkScores=[];document.getElementById('mzkControls').style.display='';document.getElementById('mzkCompleted').classList.remove('active');document.getElementById('mzkCompleted').innerHTML='';mzkSetFeedback('','');mzkUpdateProgress();mzkBuildMaze();}mzkBuildMaze();mzkUpdateProgress();
</script><?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '🧩', $content);
