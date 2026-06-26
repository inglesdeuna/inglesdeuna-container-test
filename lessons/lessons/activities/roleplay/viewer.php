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
];
$demoTurns = [[
    'agent' => 'Good evening! Are you ready to order?',
    'hint' => "Good evening! I'd like the pasta, please.",
    'ideal' => "Good evening! I'd like the pasta, please.",
    'criteria' => 'must say same answer as hint',
]];
$blankScene = ['title' => '', 'scenario' => '', 'agentRole' => '', 'studentRole' => '', 'icon' => '🎭', 'level' => ''];
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
                if (isset($parsed['turns']) && is_array($parsed['turns'])) {
                    $loadedTurns = [];
                    foreach ($parsed['turns'] as $turn) {
                        if (!is_array($turn)) continue;
                        $loadedTurns[] = [
                            'agent' => (string)($turn['agent'] ?? $turn['teacherLine'] ?? ''),
                            'hint' => (string)($turn['hint'] ?? $turn['studentLine'] ?? ''),
                            'ideal' => (string)($turn['ideal'] ?? $turn['studentLine'] ?? ''),
                            'criteria' => (string)($turn['criteria'] ?? ''),
                        ];
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
#roleplay-root{height:100%;min-height:0;background:#F8F7FF;font-family:Nunito,system-ui,sans-serif;color:#2F2763}.rp-app{height:100%;min-height:0;display:flex;flex-direction:column;background:#F8F7FF}.rp-top{background:#fff;border-bottom:1px solid #F0EEF8}.rp-title{height:58px;display:grid;place-items:center;font-family:Fredoka,sans-serif;font-size:26px;font-weight:700;color:#F97316}.rp-progress{height:8px;background:#EEEDFE}.rp-progress-fill{height:100%;background:linear-gradient(90deg,#F97316,#7F77DD);transition:width .25s}.rp-sub{height:40px;display:grid;place-items:center;background:#FFF0E6;border-bottom:1px solid #FCDDBF;color:#C2580A;font-weight:900}.rp-scroll,.rp-editor-body{flex:1;min-height:0;overflow:auto;padding:18px}.rp-wrap,.rp-editor-wrap{max-width:960px;margin:0 auto}.rp-card,.rp-edit-card{background:#fff;border:1.5px solid #EDE9FA;border-radius:22px;margin:0 0 18px;box-shadow:0 4px 16px rgba(127,119,221,.07);overflow:hidden}.rp-card.locked{opacity:.45}.rp-card-head{display:flex;align-items:center;gap:12px;padding:18px 22px 10px}.rp-avatar{width:44px;height:44px;border-radius:50%;display:grid;place-items:center;background:#FFF0E6;font-size:24px}.rp-turn-label{font-weight:900;color:#9B8FCC;text-transform:uppercase;letter-spacing:.14em}.rp-turn-label.active{color:#F97316}.rp-block,.rp-said,.rp-model,.rp-improve{margin:0 22px 10px;border-left:5px solid #7F77DD;background:#F1EEFF;border-radius:0 12px 12px 0;padding:10px 16px}.rp-model{border-left-color:#1D9E75;background:#E1F5EE}.rp-improve{border-left-color:#F97316;background:#FFF0E6}.rp-mini{font-size:12px;font-weight:900;color:#7F77DD;text-transform:uppercase;letter-spacing:.08em;margin-bottom:5px}.rp-bubble{font-weight:800;line-height:1.5}.rp-listen{border:1.5px solid #F97316;background:#FFF0E6;color:#C2580A;border-radius:999px;padding:5px 12px;font:900 12px Nunito,sans-serif;cursor:pointer;margin-left:10px}.rp-score-row{display:flex;gap:8px;margin:0 22px 18px 76px;flex-wrap:wrap}.rp-chip{min-width:100px;text-align:center;border:1.5px solid #EDE9FA;border-radius:12px;background:#fff;padding:8px 10px}.rp-chip b{display:block;color:#F97316;font-size:20px}.rp-chip span{display:block;color:#9B8FCC;font-size:12px;font-weight:800}.rp-turn-box{margin:0 22px 14px 76px;border:2px solid #7F77DD;border-radius:18px;padding:14px 18px;background:#fff}.rp-turn-box.disabled{border-color:#EDE9FA;background:#FBFAFF}.rp-say-row{display:flex;align-items:center;gap:16px}.rp-mic{border:1.5px solid #BDB8D8;background:#fff;border-radius:13px;min-width:180px;padding:12px 18px;font-weight:900;font-size:20px;color:#111;cursor:pointer}.rp-mic.listening{background:#7F77DD;color:#fff}.rp-hint{color:#9B8FCC;font-style:italic;font-weight:800}.rp-hidden-input{margin-top:12px;width:100%;border:1.5px solid #DCD7FF;border-radius:12px;padding:10px 12px;font:800 15px Nunito,sans-serif}.rp-actions{margin:0 22px 20px 76px;display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap}.rp-btn{border:1.5px solid #DCD7FF;border-radius:13px;background:#fff;color:#3D3560;padding:12px 24px;font-weight:900;font-size:16px;cursor:pointer;font-family:Nunito,sans-serif}.rp-primary{background:#F97316;color:#fff;border-color:#F97316}.rp-edit-head{border-bottom:1px solid #F0EEF8;padding:16px 22px}.rp-edit-title{font-family:Fredoka,sans-serif;color:#F97316;font-size:22px}.rp-edit-content{padding:18px 22px}.rp-grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px}.rp-grid3{display:grid;grid-template-columns:1fr 1fr 140px;gap:14px}.rp-label{display:block;margin:0 0 6px;color:#9B8FCC;font-weight:900;font-size:12px;text-transform:uppercase;letter-spacing:.08em}.rp-input,.rp-textarea{width:100%;border:1.5px solid #DCD7FF;border-radius:11px;background:#FBFAFF;color:#221A3F;font:800 16px Nunito,sans-serif;padding:10px 14px;outline:none;box-sizing:border-box}.rp-textarea{min-height:90px;resize:vertical;line-height:1.5}.rp-turn-edit{border:1.5px solid #EDE9FA;border-radius:18px;padding:16px;margin-bottom:14px}.rp-remove{float:right;border:1.5px solid #D85A30;color:#D85A30;background:#fff;border-radius:9px;padding:5px 10px;font-weight:900;cursor:pointer}.rp-savebar{position:sticky;bottom:0;display:grid;grid-template-columns:auto 1fr auto;gap:14px;align-items:center;background:#F8F7FF;padding:18px 0 0}.rp-status{text-align:center;color:#7F77DD;font-weight:900}.rp-complete{display:grid;place-items:center;min-height:100%;padding:28px}.rp-complete-card{text-align:center;background:#fff;border:1.5px solid #EDE9FA;border-radius:24px;padding:42px;max-width:520px}@media(max-width:760px){.rp-score-row,.rp-turn-box,.rp-actions{margin-left:22px}.rp-grid2,.rp-grid3,.rp-savebar{grid-template-columns:1fr}.rp-say-row{flex-direction:column;align-items:flex-start}.rp-mic{width:100%}}
</style>
<div id="roleplay-root"></div>
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
let view=window.RP_ALLOW_EDITOR?'editor':'player',completed=0,answers=[],scores=[],checked=[],activeInput='',status='',saving=false,pronunciationScores=[];
function h(v){return String(v??'').replace(/[&<>"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]))}
function uid(){return 'rp_'+Math.random().toString(36).slice(2,9)+'_'+Date.now()}
function normScene(s,blank){s=s||{};if(blank)return{title:String(s.title||''),scenario:String(s.scenario||s.description||''),agentRole:String(s.agentRole||''),studentRole:String(s.studentRole||''),icon:String(s.icon||'🎭'),level:String(s.level||'')};return{title:String(s.title||'Roleplay'),scenario:String(s.scenario||s.description||'At the Restaurant'),agentRole:String(s.agentRole||'Waiter'),studentRole:String(s.studentRole||'Customer'),icon:String(s.icon||'🎭'),level:String(s.level||'B1')}}
function normTurn(t){t=t||{};return{id:String(t.id||uid()),agent:String(t.agent||t.teacherLine||''),hint:String(t.hint||t.studentLine||''),ideal:String(t.ideal||t.studentLine||''),criteria:String(t.criteria||'')}}
function normTurns(a,blank){const out=(Array.isArray(a)?a:[]).map(normTurn);if(out.length)return out;if(blank)return[normTurn({})];return[normTurn({agent:'Good evening! Are you ready to order?',hint:"Good evening! I'd like the pasta, please.",ideal:"Good evening! I'd like the pasta, please.",criteria:'must say same answer as hint'})]}
function words(s){return String(s||'').toLowerCase().replace(/[^a-z0-9\s]/g,' ').split(/\s+/).filter(w=>w.length>1)}
function sim(a,b){const aw=words(a),bw=words(b);if(!aw.length||!bw.length)return 0;const set=new Set(aw);let m=0;bw.forEach(w=>{if(set.has(w))m++});return m/Math.max(aw.length,bw.length)}
function exactMode(t,ans){const c=String(t.criteria||'').toLowerCase();if(c.includes('same answer as hint')||c.includes('same as hint')||c.includes('exactly as hint')||c.includes('must say hint')||c.includes('repeat the hint'))return true;if(sim(t.hint,t.ideal)>=.85&&words(t.hint).length>=3)return true;if(ans&&sim(ans,t.hint)>=.95&&words(t.hint).length>=3)return true;return false}
function grammarScore(ans){const raw=String(ans||'').trim(),w=words(raw);let s=35;if(w.length>=3)s+=25;if(/^[A-Z]/.test(raw))s+=10;if(/[.!?]$/.test(raw))s+=10;if(/\b(i|you|he|she|we|they|it|there|this|that|the|a|an)\b/i.test(raw))s+=10;if(/\b(am|is|are|was|were|do|does|did|have|has|had|can|could|will|would|like|want|need|go|eat|drink|order|cut|hurt)\b/i.test(raw))s+=10;if(/\b(\w+)\s+\1\b/i.test(raw))s-=15;if(w.length<2)s-=25;return Math.max(0,Math.min(100,s))}
function scoreTurn(ans,t,pron){const fixed=exactMode(t,ans);const expected=fixed?(t.hint||''):(t.ideal||t.hint||'');const aw=words(ans),ew=words(expected),aset=new Set(aw);let matched=0;ew.forEach(w=>{if(aset.has(w))matched++});const wordScore=ew.length?Math.round(matched/ew.length*100):(ans.trim()?70:0);const grammar=fixed?null:grammarScore(ans);const pronunciation=typeof pron==='number'?Math.max(0,Math.min(100,Math.round(pron))):Math.round(wordScore*.85+(fixed?100:grammar)*.15);const total=fixed?Math.round((wordScore+pronunciation)/2):Math.round((wordScore+pronunciation+grammar)/3);let improve=fixed?'Fixed answer mode: grammar is not counted. Target answer comes from the hint. ':'Free answer mode: grammar is counted. Target answer comes from the model sentence. ';improve+='Words matched: '+matched+'/'+ew.length+'. Pronunciation: '+pronunciation+'%. ';if(!fixed)improve+='Grammar: '+grammar+'%. ';improve+='Target: '+(expected||'No target configured.');return{wordScore,pronunciation,grammar,overallPct:total,matched,totalWords:ew.length,exactMode:fixed,improve}}
function speakAgent(i){const text=turns[i]?.agent||'';if(!text.trim())return;if(!('speechSynthesis'in window)){alert('Text to speech is not supported in this browser.');return}speechSynthesis.cancel();const u=new SpeechSynthesisUtterance(text);u.lang='en-US';u.rate=.92;speechSynthesis.speak(u)}
function header(){const pct=turns.length?Math.round(completed/turns.length*100):0;const sub=[scene.scenario||scene.title||'Roleplay',[scene.agentRole,scene.studentRole].filter(Boolean).join(' / ')].filter(Boolean).join(' · ');return'<div class="rp-top"><div class="rp-title">Roleplay</div><div class="rp-progress"><div class="rp-progress-fill" style="width:'+pct+'%"></div></div><div class="rp-sub">'+h(sub)+'</div></div>'}
function chips(sc){if(!sc)return'';return'<div class="rp-chip"><b>'+h(sc.wordScore)+'%</b><span>Words</span></div><div class="rp-chip"><b>'+h(sc.pronunciation)+'%</b><span>Pronunciation</span></div>'+(sc.exactMode?'':'<div class="rp-chip"><b>'+h(sc.grammar)+'%</b><span>Grammar</span></div>')+'<div class="rp-chip"><b>'+h(sc.overallPct)+'%</b><span>Total</span></div>'}
function player(){return'<div class="rp-app">'+header()+'<div class="rp-scroll"><div class="rp-wrap">'+turns.map(turnCard).join('')+'</div></div></div>'}
function turnCard(t,i){const done=i<completed,active=i===completed,locked=i>completed,ans=answers[i]||'',sc=scores[i]||null,isChecked=checked[i];return'<section class="rp-card '+(locked?'locked':'')+'"><div class="rp-card-head"><div class="rp-avatar">👨‍⚕️</div><div class="rp-turn-label '+(active?'active':'')+'">TURN '+(i+1)+' · '+(done?'✓ completed':active?'active':'🔒 locked')+'</div></div><div class="rp-block"><div class="rp-mini">'+h(scene.agentRole||'Agent')+'<button type="button" class="rp-listen" data-action="listen-agent" data-index="'+i+'">🔊 Listen</button></div><div class="rp-bubble">'+h(t.agent||'...')+'</div></div>'+(done||isChecked?'<div class="rp-said"><div class="rp-mini">You said</div><div>'+h(ans||activeInput)+'</div></div><div class="rp-model"><div class="rp-mini" style="color:#1D9E75">Target answer</div><div>'+h(sc&&sc.exactMode?(t.hint||''):(t.ideal||t.hint||'No target configured.'))+'</div></div><div class="rp-improve"><div class="rp-mini" style="color:#F97316">Feedback</div><div>'+h(sc?sc.improve:'')+'</div></div><div class="rp-score-row">'+chips(sc)+'</div>':'')+(active?'<div class="rp-turn-box"><div class="rp-say-row"><button type="button" class="rp-mic" data-action="mic" data-index="'+i+'">🎙 Now say it</button><span class="rp-hint">Hint: '+h(t.hint||'Answer naturally')+'</span></div><textarea class="rp-hidden-input" data-answer="1" placeholder="Speech will appear here. You can also type...">'+h(activeInput)+'</textarea></div><div class="rp-actions"><button type="button" class="rp-btn" data-action="check-answer">Check answer</button><button type="button" class="rp-btn rp-primary" data-action="next">'+(i>=turns.length-1?'Finish':'Next')+'</button></div>':'')+(locked?'<div class="rp-turn-box disabled"><span class="rp-hint">Complete the previous turn to unlock this one.</span></div>':'')+'</section>'}
function complete(){const avg=scores.length?Math.round(scores.reduce((a,s)=>a+(s.overallPct||0),0)/scores.length):0;return'<div class="rp-app">'+header()+'<div class="rp-complete"><div class="rp-complete-card"><div style="font-size:58px">✅</div><h1 style="font-family:Fredoka,sans-serif;color:#F97316;margin:8px 0">Roleplay Complete!</h1><p style="font-weight:900;color:#7F77DD">Score: '+avg+'%</p><button type="button" class="rp-btn rp-primary" data-action="restart">Try again</button></div></div></div>'}
function editor(){return'<div class="rp-app"><div class="rp-top"><div class="rp-title">Roleplay Editor</div></div><div class="rp-editor-body"><div class="rp-editor-wrap"><section class="rp-edit-card"><div class="rp-edit-head"><div class="rp-edit-title">Roleplay settings</div></div><div class="rp-edit-content"><div class="rp-grid3"><div><label class="rp-label">Activity title</label><input class="rp-input" data-scene="title" value="'+h(scene.title)+'"></div><div><label class="rp-label">Scenario</label><input class="rp-input" data-scene="scenario" value="'+h(scene.scenario)+'"></div><div><label class="rp-label">Level</label><input class="rp-input" data-scene="level" value="'+h(scene.level)+'"></div></div><div class="rp-grid2" style="margin-top:14px"><div><label class="rp-label">Agent role</label><input class="rp-input" data-scene="agentRole" value="'+h(scene.agentRole)+'"></div><div><label class="rp-label">Student role</label><input class="rp-input" data-scene="studentRole" value="'+h(scene.studentRole)+'"></div></div></div></section><section class="rp-edit-card"><div class="rp-edit-head"><div class="rp-edit-title">Conversation turns</div></div><div class="rp-edit-content">'+turns.map((t,i)=>'<div class="rp-turn-edit"><button type="button" class="rp-remove" data-action="remove-turn" data-index="'+i+'">Remove</button><div class="rp-turn-label active">Turn '+(i+1)+'</div><label class="rp-label">Agent line</label><textarea class="rp-textarea" data-turn="'+i+'" data-prop="agent">'+h(t.agent)+'</textarea><div class="rp-grid2" style="margin-top:12px"><div><label class="rp-label">Student hint</label><textarea class="rp-textarea" data-turn="'+i+'" data-prop="hint">'+h(t.hint)+'</textarea></div><div><label class="rp-label">Model sentence / ideal answer</label><textarea class="rp-textarea" data-turn="'+i+'" data-prop="ideal">'+h(t.ideal)+'</textarea></div></div><label class="rp-label" style="margin-top:12px">Criteria</label><input class="rp-input" data-turn="'+i+'" data-prop="criteria" placeholder="Example: must say same answer as hint" value="'+h(t.criteria)+'"></div>').join('')+'<button type="button" class="rp-btn" data-action="add-turn">+ Add turn</button></div></section><div class="rp-savebar"><button type="button" class="rp-btn" data-action="preview">Preview as student</button><div class="rp-status">'+h(status)+'</div><button type="button" class="rp-btn rp-primary" data-action="save" '+(saving?'disabled':'')+'>'+(saving?'Saving...':'Save roleplay')+'</button></div></div></div></div>'}
function render(){const sc=root.querySelector('.rp-scroll,.rp-editor-body'),top=sc?sc.scrollTop:0;root.innerHTML=view==='editor'?editor():(view==='complete'?complete():player());const sc2=root.querySelector('.rp-scroll,.rp-editor-body');if(sc2)sc2.scrollTop=top}
function doCheck(){const i=completed;if(!activeInput.trim()){alert('Please say or type your answer first.');return false}answers[i]=activeInput.trim();scores[i]=scoreTurn(activeInput,turns[i]||{},pronunciationScores[i]);checked[i]=true;render();return true}
function next(){const i=completed;if(!checked[i]&&!doCheck())return;completed=Math.min(turns.length,completed+1);activeInput='';if(completed>=turns.length)view='complete';render()}
function mic(i){const SR=window.SpeechRecognition||window.webkitSpeechRecognition;if(!SR){alert('Speech recognition is not supported in this browser. You can type your answer.');return}const rec=new SR();rec.lang='en-US';rec.continuous=false;rec.interimResults=false;const btn=root.querySelector('[data-action="mic"][data-index="'+i+'"]'),box=root.querySelector('[data-answer="1"]');if(btn){btn.classList.add('listening');btn.textContent='🎙 Listening...'}rec.onresult=e=>{const alt=e.results[0][0];activeInput=alt.transcript;pronunciationScores[i]=typeof alt.confidence==='number'?Math.round(alt.confidence*100):undefined;if(box)box.value=activeInput};rec.onend=rec.onerror=()=>{if(btn){btn.classList.remove('listening');btn.textContent='🎙 Now say it'}};rec.start()}
root.addEventListener('input',e=>{const el=e.target;if(el.dataset.scene){scene[el.dataset.scene]=el.value;return}if(el.dataset.turn){const i=Number(el.dataset.turn);turns[i]=Object.assign({},turns[i]||normTurn({}),{[el.dataset.prop]:el.value});return}if(el.dataset.answer){activeInput=el.value;pronunciationScores[completed]=undefined;checked[completed]=false}});
root.addEventListener('click',async e=>{const b=e.target.closest('[data-action]');if(!b)return;e.preventDefault();const a=b.dataset.action;if(a==='listen-agent')speakAgent(Number(b.dataset.index));if(a==='mic')mic(Number(b.dataset.index));if(a==='check-answer')doCheck();if(a==='next')next();if(a==='restart'){view='player';completed=0;answers=[];scores=[];checked=[];activeInput='';pronunciationScores=[];render()}if(a==='preview'){view='player';completed=0;answers=[];scores=[];checked=[];activeInput='';pronunciationScores=[];render()}if(a==='add-turn'){turns.push({id:uid(),agent:'',hint:'',ideal:'',criteria:''});render()}if(a==='remove-turn'){turns=turns.filter((_,i)=>i!==Number(b.dataset.index));if(!turns.length)turns=[normTurn({})];render()}if(a==='save'){if(!window.RP_ACTIVITY_ID){status='No activity ID - cannot save.';render();return}saving=true;status='Saving...';render();try{const resp=await fetch('save.php',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({id:window.RP_ACTIVITY_ID,scene,turns})});const json=await resp.json().catch(()=>({}));if(!resp.ok||!json.ok)throw new Error(json.error||('HTTP '+resp.status));window.RP_HAS_SAVED_PAYLOAD=true;status='Saved successfully'}catch(err){status='Could not save: '+err.message}finally{saving=false;render()}}});
try{render()}catch(err){console.error('[roleplay] render error',err);root.innerHTML='<div style="padding:20px">Roleplay could not render. Check console.</div>'}
})();
</script>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '🎭', $content);
