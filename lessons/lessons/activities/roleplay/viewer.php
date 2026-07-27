<?php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$mode = isset($_GET['mode']) ? trim((string) $_GET['mode']) : '';
$source = isset($_GET['source']) ? trim((string) $_GET['source']) : '';
$returnTo = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';

$isEditorAuth = !empty($_SESSION['admin_logged'])
    || !empty($_SESSION['academic_logged'])
    || !empty($_SESSION['teacher_logged'])
    || !empty($_SESSION['teacher_id'])
    || !empty($_SESSION['teacher_username'])
    || !empty($_SESSION['academic_id'])
    || !empty($_SESSION['admin_id']);
$isCreatorSource = in_array(strtolower($source), ['creator', 'create', 'editor', 'teacher'], true);

if ($mode === 'edit' && !$isEditorAuth && !$isCreatorSource) {
    header('Location: /lessons/lessons/academic/login.php');
    exit;
}

$allowEditor = ($mode === 'edit' || $isCreatorSource) && ($isEditorAuth || $isCreatorSource);

$demoScene = [
    'title' => 'Roleplay',
    'scenario' => 'At the Restaurant',
    'agentRole' => 'Waiter',
    'studentRole' => 'Customer',
    'icon' => '🎭',
    'level' => 'B1',
    'teacherVoiceId' => 'nzFihrBIvB34imQBuxub',
];
$demoTurns = [[
    'agent' => 'Good evening! Are you ready to order?',
    'hint' => "Good evening! I'd like the pasta, please.",
    'ideal' => "Good evening! I'd like the pasta, please.",
    'criteria' => 'must say same answer as hint',
]];
$blankScene = ['title' => '', 'scenario' => '', 'agentRole' => '', 'studentRole' => '', 'icon' => '🎭', 'level' => '', 'teacherVoiceId' => 'nzFihrBIvB34imQBuxub'];
$blankTurns = [['agent' => '', 'hint' => '', 'ideal' => '', 'criteria' => '']];

$savedScene = $allowEditor ? $blankScene : $demoScene;
$savedTurns = $allowEditor ? $blankTurns : $demoTurns;
$hasSavedPayload = false;

try {
    if ($activityId !== '') {
        $stmt = $pdo->prepare('SELECT data FROM activities WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $rawData = $row ? trim((string)($row['data'] ?? '')) : '';
        if ($rawData !== '' && $rawData !== '{}' && $rawData !== '[]' && strtolower($rawData) !== 'null') {
            $parsed = json_decode($rawData, true);
            if (is_array($parsed)) {
                $hasSavedPayload = true;
                $baseScene = $allowEditor ? $blankScene : $demoScene;
                $savedScene = isset($parsed['scene']) && is_array($parsed['scene']) ? array_merge($baseScene, $parsed['scene']) : $baseScene;
                $turnSource = null;
                foreach (['turns', 'dialogue', 'dialogs', 'lines', 'items'] as $turnKey) {
                    if (isset($parsed[$turnKey]) && is_array($parsed[$turnKey])) {
                        $turnSource = $parsed[$turnKey];
                        break;
                    }
                }
                if (is_array($turnSource)) {
                    $loadedTurns = [];
                    foreach ($turnSource as $turn) {
                        if (!is_array($turn)) continue;
                        $loadedTurn = $turn;
                        $loadedTurn = array_merge($loadedTurn, [
                            'agent' => (string)($turn['agent'] ?? $turn['teacherLine'] ?? ''),
                            'hint' => (string)($turn['hint'] ?? $turn['studentLine'] ?? ''),
                            'ideal' => (string)($turn['ideal'] ?? $turn['studentLine'] ?? ''),
                            'criteria' => (string)($turn['criteria'] ?? ''),
                        ]);
                        $loadedTurns[] = $loadedTurn;
                    }
                    if ($loadedTurns) $savedTurns = $loadedTurns;
                    elseif ($allowEditor) $savedTurns = $blankTurns;
                }
            }
        }
    }
} catch (Throwable $e) {
    error_log('[roleplay/viewer] load error: ' . $e->getMessage());
}

$viewerTitle = trim((string)($savedScene['title'] ?? '')) !== '' ? (string)$savedScene['title'] : 'Roleplay';

ob_start();
?>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@400;600;700&family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<style>
#roleplay-root{height:100%;min-height:0;background:#F8F7FF;font-family:Nunito,system-ui,sans-serif;color:#2F2763}.rp-app{height:100%;min-height:0;display:flex;flex-direction:column;background:#F8F7FF}.rp-top{background:#fff;border-bottom:1px solid #F0EEF8}.rp-title{height:58px;display:grid;place-items:center;font-family:Fredoka,sans-serif;font-size:26px;font-weight:700;color:#F97316}.rp-progress{height:8px;background:#EEEDFE}.rp-progress-fill{height:100%;background:linear-gradient(90deg,#F97316,#7F77DD);transition:width .25s}.rp-sub{height:40px;display:grid;place-items:center;background:#FFF0E6;border-bottom:1px solid #FCDDBF;color:#C2580A;font-weight:900}.rp-scroll,.rp-editor-body{flex:1;min-height:0;overflow:auto;padding:18px}.rp-wrap,.rp-editor-wrap{max-width:960px;margin:0 auto}.rp-card{margin:0 0 12px}.rp-card.locked{opacity:.45}.rp-block,.rp-said{margin:0 0 10px;max-width:82%;border-left:5px solid #7F77DD;background:#F1EEFF;border-radius:0 12px 12px 0;padding:10px 16px}.rp-said{margin-left:auto;border-left:0;border-right:5px solid #F97316;background:#FFF0E6;border-radius:12px 0 0 12px}.rp-mini{font-size:12px;font-weight:900;color:#7F77DD;text-transform:uppercase;letter-spacing:.08em;margin-bottom:5px}.rp-said .rp-mini{color:#C2580A}.rp-bubble{font-weight:800;line-height:1.5}.rp-listen{border:1.5px solid #F97316;background:#FFF0E6;color:#C2580A;border-radius:999px;padding:5px 12px;font:900 12px Nunito,sans-serif;cursor:pointer;margin-left:10px}.rp-turn-box{margin:0 0 14px;border:2px solid #7F77DD;border-radius:18px;padding:14px 18px;background:#fff}.rp-say-row{display:flex;align-items:center;gap:16px}.rp-mic{border:1.5px solid #BDB8D8;background:#fff;border-radius:13px;min-width:180px;padding:12px 18px;font-weight:900;font-size:20px;color:#111;cursor:pointer}.rp-mic.listening{background:#7F77DD;color:#fff}.rp-hidden-input{margin-top:12px;width:100%;border:1.5px solid #DCD7FF;border-radius:12px;padding:10px 12px;font:800 15px Nunito,sans-serif}.rp-actions{margin:0 0 20px;display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap}.rp-btn{border:1.5px solid #DCD7FF;border-radius:13px;background:#fff;color:#3D3560;padding:12px 24px;font-weight:900;font-size:16px;cursor:pointer;font-family:Nunito,sans-serif}.rp-primary{background:#F97316;color:#fff;border-color:#F97316}.rp-hint{display:none;margin-top:10px;padding:10px 12px;border-left:4px solid #7F77DD;border-radius:0 10px 10px 0;background:#F1EEFF;color:#3D3560;font-weight:800}.rp-hint.visible{display:block}.rp-edit-head{border-bottom:1px solid #F0EEF8;padding:16px 22px}.rp-edit-title{font-family:Fredoka,sans-serif;color:#F97316;font-size:22px}.rp-edit-content{padding:18px 22px}.rp-grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px}.rp-grid3{display:grid;grid-template-columns:1fr 1fr 140px;gap:14px}.rp-label{display:block;margin:0 0 6px;color:#9B8FCC;font-weight:900;font-size:12px;text-transform:uppercase;letter-spacing:.08em}.rp-input,.rp-textarea{width:100%;border:1.5px solid #DCD7FF;border-radius:11px;background:#FBFAFF;color:#221A3F;font:800 16px Nunito,sans-serif;padding:10px 14px;outline:none;box-sizing:border-box}.rp-textarea{min-height:90px;resize:vertical;line-height:1.5}.rp-turn-edit{border:1.5px solid #EDE9FA;border-radius:18px;padding:16px;margin-bottom:14px}.rp-remove{float:right;border:1.5px solid #D85A30;color:#D85A30;background:#fff;border-radius:9px;padding:5px 10px;font-weight:900;cursor:pointer}.rp-savebar{position:sticky;bottom:0;display:grid;grid-template-columns:auto 1fr auto;gap:14px;align-items:center;background:#F8F7FF;padding:18px 0 0}.rp-status{text-align:center;color:#7F77DD;font-weight:900}.rp-complete{display:grid;place-items:center;min-height:100%;padding:28px}.rp-complete-card{text-align:center;background:#fff;border:1.5px solid #EDE9FA;border-radius:24px;padding:42px;max-width:520px}.rp-final-score-grid{display:none;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:12px}.rp-final-score-grid.visible{display:grid}.rp-final-score-card{background:#FAFAFE;border:1px solid #EDE9FA;border-radius:14px;padding:12px;text-align:center}.rp-final-score-num{font-family:Fredoka,sans-serif;font-size:24px;line-height:1;font-weight:600;color:#7F77DD}.rp-final-score-lbl{margin-top:3px;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#bbb}.rp-completed-icon{font-size:30px;line-height:1;margin-bottom:6px}.rp-completed-title{margin:0;color:#F97316;font-family:Fredoka,sans-serif;font-size:32px;font-weight:700}.rp-completed-text,.rp-final-score-text{color:#9B94BE;font-size:14px;font-weight:700}.rp-completed-button{border:none;border-radius:10px;color:#fff;min-width:128px;padding:11px 20px;font-size:14px;font-weight:700;font-family:Nunito,sans-serif;cursor:pointer;background:#7F77DD}@media(max-width:760px){.rp-block,.rp-said{max-width:92%}.rp-turn-box,.rp-actions{margin-left:0}.rp-grid2,.rp-grid3,.rp-savebar{grid-template-columns:1fr}.rp-say-row{flex-direction:column;align-items:flex-start}.rp-mic{width:100%}.rp-final-score-grid{grid-template-columns:1fr 1fr 1fr}}
</style>
<div id="roleplay-root" data-az-zoom></div>
<script>
window.RP_ACTIVITY_ID=<?= json_encode($activityId, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;
window.RP_RETURN_TO=<?= json_encode($returnTo, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;
window.RP_SAVED_SCENE=<?= json_encode($savedScene, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;
window.RP_SAVED_TURNS=<?= json_encode($savedTurns, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;
window.RP_ALLOW_EDITOR=<?= json_encode($allowEditor) ?>;
window.RP_HAS_SAVED_PAYLOAD=<?= json_encode($hasSavedPayload) ?>;
(function(){
const root=document.getElementById('roleplay-root');
let scene=normScene(window.RP_SAVED_SCENE||{},window.RP_ALLOW_EDITOR&&!window.RP_HAS_SAVED_PAYLOAD);
let turns=normTurns(window.RP_SAVED_TURNS||[],window.RP_ALLOW_EDITOR&&!window.RP_HAS_SAVED_PAYLOAD);
let view=window.RP_ALLOW_EDITOR?'editor':'player',completed=0,answers=[],scores=[],checked=[],activeInput='',status='',saving=false,pronunciationScores=[],hintOpen=[],turnsReduced=false;
function h(v){return String(v??'').replace(/[&<>"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]))}
function uid(){return 'rp_'+Math.random().toString(36).slice(2,9)+'_'+Date.now()}
function normScene(s,blank){s=s||{};const voice=String(s.teacherVoiceId||'nzFihrBIvB34imQBuxub');if(blank)return{title:String(s.title||''),scenario:String(s.scenario||s.description||''),agentRole:String(s.agentRole||''),studentRole:String(s.studentRole||''),icon:String(s.icon||'🎭'),level:String(s.level||''),teacherVoiceId:voice};return{title:String(s.title||'Roleplay'),scenario:String(s.scenario||s.description||'At the Restaurant'),agentRole:String(s.agentRole||'Waiter'),studentRole:String(s.studentRole||'Customer'),icon:String(s.icon||'🎭'),level:String(s.level||'B1'),teacherVoiceId:voice}}
function normTurn(t){t=t||{};const mode=String(t.mode||'').toLowerCase()==='clue'?'clue':(String(t.mode||'').toLowerCase()==='exact'?'exact':'');return{...t,id:String(t.id||uid()),agent:String(t.agent||t.teacherLine||''),hint:String(t.hint||t.studentLine||''),ideal:String(t.ideal||t.studentLine||''),criteria:String(t.criteria||''),mode:mode}}
function normTurns(a,blank){const out=(Array.isArray(a)?a:[]).map(normTurn);if(out.length)return out;if(blank)return[normTurn({})];return[normTurn({agent:'Good evening! Are you ready to order?',hint:"Good evening! I'd like the pasta, please.",ideal:"Good evening! I'd like the pasta, please.",criteria:'must say same answer as hint'})]}
function words(s){return String(s||'').toLowerCase().replace(/[^a-z0-9\s]/g,' ').split(/\s+/).filter(Boolean)}
function sim(a,b){const aw=words(a),bw=words(b);if(!aw.length||!bw.length)return 0;const set=new Set(aw);let m=0;bw.forEach(w=>{if(set.has(w))m++});return m/Math.max(aw.length,bw.length)}
function exactMode(t,ans){if(String(t.mode||'').toLowerCase()==='exact')return true;if(String(t.mode||'').toLowerCase()==='clue')return false;const c=String(t.criteria||'').toLowerCase();if(c.includes('same answer as hint')||c.includes('same as hint')||c.includes('exactly as hint')||c.includes('must say hint')||c.includes('repeat the hint'))return true;if(sim(t.hint,t.ideal)>=.85&&words(t.hint).length>=3)return true;if(ans&&sim(ans,t.hint)>=.95&&words(t.hint).length>=3)return true;return false}
function grammarScore(ans){const raw=String(ans||'').trim(),w=words(raw);let s=35;if(w.length>=3)s+=25;if(/^[A-Z]/.test(raw))s+=10;if(/[.!?]$/.test(raw))s+=10;if(/\b(i|you|he|she|we|they|it|there|this|that|the|a|an)\b/i.test(raw))s+=10;if(/\b(am|is|are|was|were|do|does|did|have|has|had|can|could|will|would|like|want|need|go|eat|drink|order|cut|hurt)\b/i.test(raw))s+=10;if(/\b(\w+)\s+\1\b/i.test(raw))s-=15;if(w.length<2)s-=25;return Math.max(0,Math.min(100,s))}
function scoreTurn(ans,t,pron){const fixed=exactMode(t,ans),hintWords=words(t.hint||''),aw=words(ans),expected=fixed?(t.hint||''):(t.ideal||t.hint||''),ew=words(expected),freq={};aw.forEach(w=>{freq[w]=(freq[w]||0)+1});let matched=0;ew.forEach(w=>{if(freq[w]>0){matched++;freq[w]--}});if(fixed){const pct=hintWords.length?Math.round((matched/hintWords.length)*100):0;return{overallPct:pct,exactMode:true,matched:matched,totalWords:hintWords.length,grammar:null,vocabulary:null,pronunciation:null}}const grammar=grammarScore(ans);const required=words((t.criteria||'')+' '+(t.ideal||'')).filter(w=>w.length>2);const relevant=new Set(required);const responseWords=new Set(aw);let vocabHits=0;relevant.forEach(w=>{if(responseWords.has(w))vocabHits++});const vocabulary=relevant.size?Math.round(vocabHits/relevant.size*100):Math.min(100,Math.round((aw.length/8)*100));const pronunciation=typeof pron==='number'?Math.max(0,Math.min(100,Math.round(pron))):Math.round((grammar+vocabulary)/2);return{overallPct:Math.round((grammar+vocabulary+pronunciation)/3),exactMode:false,matched:vocabHits,totalWords:relevant.size,grammar:grammar,vocabulary:vocabulary,pronunciation:pronunciation}}
const VOICES={josh:{label:'Josh',id:'nzFihrBIvB34imQBuxub'},lily:{label:'Lily',id:'NoOVOzCQFLOvtsMoNcdT'},candy:{label:'Candy',id:'Nggzl2QAXh3OijoXD116'}};function voiceIdToLabel(id){const found=Object.keys(VOICES).find(k=>VOICES[k].id===id);return found||'josh'}function voiceSelectHtml(){const current=voiceIdToLabel(scene.teacherVoiceId);return'<label class="rp-label">Listen voice - ElevenLabs</label><select class="rp-input" data-scene="teacherVoiceId">'+Object.keys(VOICES).map(k=>'<option value="'+h(VOICES[k].id)+'" '+(current===k?'selected':'')+'>'+h(VOICES[k].label)+'</option>').join('')+'</select>'}
async function speakAgent(i){const text=turns[i]?.agent||'';if(!text.trim())return;try{if('speechSynthesis'in window)speechSynthesis.cancel();const form=new FormData();form.append('text',text);form.append('voice_id',scene.teacherVoiceId||VOICES.josh.id);const resp=await fetch('tts.php',{method:'POST',body:form,credentials:'same-origin'});if(!resp.ok)throw new Error('ElevenLabs audio failed');const audio=new Audio(URL.createObjectURL(await resp.blob()));audio.onended=()=>URL.revokeObjectURL(audio.src);await audio.play()}catch(err){console.error('[roleplay] ElevenLabs listen failed',err);if('speechSynthesis'in window){const u=new SpeechSynthesisUtterance(text);u.lang='en-US';u.rate=.92;speechSynthesis.speak(u)}else alert('Audio is not available in this browser.')}}
function header(){const pct=turns.length?Math.round(completed/turns.length*100):0;const sub=[scene.scenario||scene.title||'Roleplay',[scene.agentRole,scene.studentRole].filter(Boolean).join(' / ')].filter(Boolean).join(' · ');return'<div class="rp-top"><div class="rp-title">Roleplay</div><div class="rp-progress"><div class="rp-progress-fill" style="width:'+pct+'%"></div></div><div class="rp-sub">'+h(sub)+'</div></div>'}
function player(){return'<div class="rp-app">'+header()+'<div class="rp-scroll"><div class="rp-wrap">'+turns.map(turnCard).join('')+'</div></div></div>'}
function turnCard(t,i){const done=i<completed,active=i===completed,locked=i>completed,ans=answers[i]||'',isChecked=checked[i],hintVisible=hintOpen[i];return'<section class="rp-card '+(locked?'locked':'')+'"><div class="rp-block"><div class="rp-mini">'+h(scene.agentRole||'Agent')+'<button type="button" class="rp-listen" data-action="listen-agent" data-index="'+i+'">🔊 Listen</button></div><div class="rp-bubble">'+h(t.agent||'...')+'</div></div>'+(done||isChecked?'<div class="rp-said"><div class="rp-mini">'+h(scene.studentRole||'Student')+'</div><div class="rp-bubble">'+h(ans||activeInput)+'</div></div>':'')+(active?'<div class="rp-turn-box"><div class="rp-say-row"><button type="button" class="rp-mic" data-action="mic" data-index="'+i+'">🎙 Now say it</button><button type="button" class="rp-btn" data-action="hint" data-index="'+i+'">Hint</button></div><div class="rp-hint '+(hintVisible?'visible':'')+'">'+h(t.hint||'No hint configured.')+'</div><textarea class="rp-hidden-input" data-answer="1" placeholder="Speech will appear here. You can also type...">'+h(activeInput)+'</textarea></div><div class="rp-actions"><button type="button" class="rp-btn" data-action="check-answer">Send message</button><button type="button" class="rp-btn rp-primary" data-action="next">'+(i>=turns.length-1?'Finish':'Continue')+'</button></div>':'')+'</section>'}
function complete(){let correct=scores.filter(s=>s&&s.overallPct>=80).length,wrong=turns.length-correct,pct=turns.length?Math.round(scores.reduce((sum,s)=>sum+(s?s.overallPct:0),0)/turns.length):0;return'<div class="rp-app">'+header()+'<div class="rp-scroll"><div class="rp-wrap"><div class="rp-complete"><div class="rp-complete-card"><div class="rp-completed-icon">✅</div><h2 class="rp-completed-title">All Done!</h2><p class="rp-completed-text">You\'ve completed '+h(scene.title||'this activity')+'. Great job practicing speaking.</p><div class="rp-final-score-grid visible"><div class="rp-final-score-card"><div class="rp-final-score-num">'+correct+'</div><div class="rp-final-score-lbl">Correct</div></div><div class="rp-final-score-card"><div class="rp-final-score-num">'+wrong+'</div><div class="rp-final-score-lbl">Wrong</div></div><div class="rp-final-score-card"><div class="rp-final-score-num">'+pct+'%</div><div class="rp-final-score-lbl">Score</div></div></div><p class="rp-final-score-text">'+correct+' correct · '+wrong+' wrong · '+pct+'%</p><button type="button" class="rp-completed-button" data-action="restart">Restart</button></div></div></div></div></div>'}
function editor(){return'<div class="rp-app"><div class="rp-top"><div class="rp-title">Roleplay Editor</div></div><div class="rp-editor-body"><div class="rp-editor-wrap"><section class="rp-edit-card"><div class="rp-edit-head"><div class="rp-edit-title">Roleplay settings</div></div><div class="rp-edit-content"><div class="rp-grid3"><div><label class="rp-label">Activity title</label><input class="rp-input" data-scene="title" value="'+h(scene.title)+'"></div><div><label class="rp-label">Scenario</label><input class="rp-input" data-scene="scenario" value="'+h(scene.scenario)+'"></div><div><label class="rp-label">Level</label><input class="rp-input" data-scene="level" value="'+h(scene.level)+'"></div></div><div class="rp-grid2" style="margin-top:14px"><div><label class="rp-label">Agent role</label><input class="rp-input" data-scene="agentRole" value="'+h(scene.agentRole)+'"></div><div><label class="rp-label">Student role</label><input class="rp-input" data-scene="studentRole" value="'+h(scene.studentRole)+'"></div></div><div style="margin-top:14px">'+voiceSelectHtml()+'</div></div></section><section class="rp-edit-card"><div class="rp-edit-head"><div class="rp-edit-title">Conversation turns</div></div><div class="rp-edit-content">'+turns.map((t,i)=>'<div class="rp-turn-edit"><button type="button" class="rp-remove" data-action="remove-turn" data-index="'+i+'">Remove</button><div class="rp-turn-label active">Turn '+(i+1)+'</div><label class="rp-label">Agent line</label><textarea class="rp-textarea" data-turn="'+i+'" data-prop="agent">'+h(t.agent)+'</textarea><div class="rp-grid2" style="margin-top:12px"><div><label class="rp-label">Student hint</label><textarea class="rp-textarea" data-turn="'+i+'" data-prop="hint">'+h(t.hint)+'</textarea></div><div><label class="rp-label">Model sentence / ideal answer</label><textarea class="rp-textarea" data-turn="'+i+'" data-prop="ideal">'+h(t.ideal)+'</textarea></div></div><div class="rp-grid2" style="margin-top:12px"><div><label class="rp-label">Response mode</label><select class="rp-input" data-turn="'+i+'" data-prop="mode"><option value="" '+(!t.mode?'selected':'')+'>Auto-detect</option><option value="exact" '+(t.mode==='exact'?'selected':'')+'>Exact Hint</option><option value="clue" '+(t.mode==='clue'?'selected':'')+'>Free response from clue</option></select></div><div><label class="rp-label">Criteria</label><input class="rp-input" data-turn="'+i+'" data-prop="criteria" placeholder="Example: mention the booking date" value="'+h(t.criteria)+'"></div></div></div>').join('')+'<button type="button" class="rp-btn" data-action="add-turn">+ Add turn</button></div></section><div class="rp-savebar"><button type="button" class="rp-btn" data-action="preview">Preview as student</button><div class="rp-status">'+h(status)+'</div><button type="button" class="rp-btn rp-primary" data-action="save" '+(saving?'disabled':'')+'>'+(saving?'Saving...':'Save roleplay')+'</button></div></div></div></div>'}
function render(){const sc=root.querySelector('.rp-scroll,.rp-editor-body'),top=sc?sc.scrollTop:0;root.innerHTML=view==='editor'?editor():(view==='complete'?complete():player());const sc2=root.querySelector('.rp-scroll,.rp-editor-body');if(sc2)sc2.scrollTop=top}
function doCheck(){const i=completed;if(!activeInput.trim()){alert('Please say or type your answer first.');return false}answers[i]=activeInput.trim();scores[i]=scoreTurn(activeInput,turns[i]||{},pronunciationScores[i]);checked[i]=true;render();return true}
function next(){const i=completed;if(!checked[i]&&!doCheck())return;completed=Math.min(turns.length,completed+1);activeInput='';if(completed>=turns.length)view='complete';render()}
function mic(i){const SR=window.SpeechRecognition||window.webkitSpeechRecognition;if(!SR){alert('Speech recognition is not supported in this browser. You can type your answer.');return}const rec=new SR();rec.lang='en-US';rec.continuous=false;rec.interimResults=false;const btn=root.querySelector('[data-action="mic"][data-index="'+i+'"]'),box=root.querySelector('[data-answer="1"]');if(btn){btn.classList.add('listening');btn.textContent='🎙 Listening...'}rec.onresult=e=>{const alt=e.results[0][0];activeInput=alt.transcript;pronunciationScores[i]=typeof alt.confidence==='number'?Math.round(alt.confidence*100):undefined;if(box)box.value=activeInput};rec.onend=rec.onerror=()=>{if(btn){btn.classList.remove('listening');btn.textContent='🎙 Now say it'}};rec.start()}
root.addEventListener('input',e=>{const el=e.target;if(el.dataset.scene){scene[el.dataset.scene]=el.value;return}if(el.dataset.turn){const i=Number(el.dataset.turn);turns[i]=Object.assign({},turns[i]||normTurn({}),{[el.dataset.prop]:el.value});return}if(el.dataset.answer){activeInput=el.value;pronunciationScores[completed]=undefined;checked[completed]=false}});
root.addEventListener('click',async e=>{const b=e.target.closest('[data-action]');if(!b)return;e.preventDefault();const a=b.dataset.action;if(a==='listen-agent')speakAgent(Number(b.dataset.index));if(a==='mic')mic(Number(b.dataset.index));if(a==='hint'){const i=Number(b.dataset.index);hintOpen[i]=!hintOpen[i];render()}if(a==='check-answer')doCheck();if(a==='next')next();if(a==='restart'){view='player';completed=0;answers=[];scores=[];checked=[];activeInput='';pronunciationScores=[];hintOpen=[];render()}if(a==='preview'){view='player';completed=0;answers=[];scores=[];checked=[];activeInput='';pronunciationScores=[];hintOpen=[];render()}if(a==='add-turn'){turns.push({id:uid(),agent:'',hint:'',ideal:'',criteria:''});render()}if(a==='remove-turn'){turns=turns.filter((_,i)=>i!==Number(b.dataset.index));turnsReduced=true;if(!turns.length)turns=[normTurn({})];render()}if(a==='save'){if(!window.RP_ACTIVITY_ID){status='No activity ID - cannot save.';render();return}saving=true;status='Saving...';render();try{const resp=await fetch('save.php',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({id:window.RP_ACTIVITY_ID,scene,turns,allow_turn_reduction:turnsReduced})});const json=await resp.json().catch(()=>({}));if(!resp.ok||!json.ok)throw new Error(json.error||('HTTP '+resp.status));window.RP_HAS_SAVED_PAYLOAD=true;status='Saved successfully'}catch(err){status='Could not save: '+err.message}finally{saving=false;render()}}});
try{render()}catch(err){console.error('[roleplay] render error',err);root.innerHTML='<div style="padding:20px">Roleplay could not render. Check console.</div>'}
})();
</script>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '🎭', $content);
