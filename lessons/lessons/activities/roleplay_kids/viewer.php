<?php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$mode       = isset($_GET['mode']) ? trim((string) $_GET['mode']) : '';
$returnTo   = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';
$source     = isset($_GET['source']) ? trim((string) $_GET['source']) : '';

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

$defaultScene = [
  'title' => 'Roleplay Kids',
  'scenario' => 'At the Restaurant',
  'agentRole' => 'Waiter',
  'studentRole' => 'Customer',
  'icon' => '🌟',
  'level' => 'A1',
  'teacherAvatarId' => 'TEACHER',
  'studentAvatarId' => 'ANGIE',
  'teacherVoiceId' => 'nzFihrBIvB34imQBuxub'
];
$defaultTurns = [[
  'agent' => 'Good evening! Are you ready to order?',
  'hint' => "Say hello and say what food you want.",
  'ideal' => "Good evening! I'd like the pasta, please.",
  'criteria' => 'Greeting and polite food order.'
], [
  'agent' => 'Would you like something to drink?',
  'hint' => 'Ask for a drink politely.',
  'ideal' => "Yes, I'd like water, please.",
  'criteria' => 'Polite drink order.'
], [
  'agent' => 'Will that be all for you tonight?',
  'hint' => 'Finish your order politely.',
  'ideal' => 'Yes, that is all. Thank you.',
  'criteria' => 'Clear polite ending.'
]];

$savedScene = $defaultScene;
$savedTurns = $defaultTurns;

try {
  if ($activityId !== '') {
    $stmt = $pdo->prepare("SELECT data FROM activities WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['data'])) {
      $parsed = json_decode((string) $row['data'], true);
      if (is_array($parsed)) {
        if (isset($parsed['scene']) && is_array($parsed['scene'])) {
          $savedScene = array_merge($defaultScene, $parsed['scene']);
          if (empty($savedScene['teacherVoiceId'])) $savedScene['teacherVoiceId'] = 'nzFihrBIvB34imQBuxub';
        }
        if (isset($parsed['turns']) && is_array($parsed['turns']) && count($parsed['turns']) > 0) {
          $savedTurns = [];
          foreach ($parsed['turns'] as $turn) {
            if (!is_array($turn)) continue;
            $savedTurns[] = [
              'agent'    => (string)($turn['agent'] ?? $turn['teacherLine'] ?? ''),
              'hint'     => (string)($turn['hint'] ?? $turn['studentLine'] ?? ''),
              'ideal'    => (string)($turn['ideal'] ?? $turn['studentLine'] ?? ''),
              'criteria' => (string)($turn['criteria'] ?? '')
            ];
          }
          if (count($savedTurns) === 0) $savedTurns = $defaultTurns;
        }
      }
    }
  }
} catch (Throwable $e) {
  error_log('[roleplay_kids/viewer] load error: ' . $e->getMessage());
}

$viewerTitle = trim((string)($savedScene['title'] ?? '')) !== '' ? (string)$savedScene['title'] : 'Roleplay Kids';
error_log('[roleplay_kids/viewer] id=' . $activityId . ' mode=' . $mode . ' source=' . $source . ' editor=' . ($allowEditor ? 'yes' : 'no') . ' turns=' . count($savedTurns));

ob_start();
?>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@400;600;700&family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<style>
#roleplay-kids-root{height:100%;min-height:0;background:#FFF9F3;font-family:Nunito,system-ui,sans-serif;color:#2F2763}.rk-app{height:100%;min-height:0;display:flex;flex-direction:column;background:linear-gradient(180deg,#FFF9F3 0%,#F8F7FF 100%)}.rk-top{flex:0 0 auto;background:#fff;border-bottom:1px solid #F0EEF8}.rk-title{height:58px;display:grid;place-items:center;font-family:Fredoka,sans-serif;font-size:27px;font-weight:700;color:#F97316}.rk-progress{height:10px;background:#EEEDFE;overflow:hidden}.rk-progress-fill{height:100%;background:linear-gradient(90deg,#F97316,#FDBA74,#7F77DD);transition:width .25s ease}.rk-sub{height:42px;display:grid;place-items:center;background:#FFF0E6;border-bottom:1px solid #FCDDBF;color:#C2580A;font-weight:900;letter-spacing:.03em}.rk-scroll{flex:1;min-height:0;overflow:auto;padding:18px}.rk-wrap{max-width:940px;margin:0 auto}.rk-card{background:#fff;border:2px solid #EDE9FA;border-radius:26px;margin:0 0 18px;box-shadow:0 7px 20px rgba(127,119,221,.08);overflow:hidden}.rk-card.locked{opacity:.45}.rk-head{display:flex;align-items:center;gap:12px;padding:18px 22px 10px}.rk-avatar{width:58px;height:58px;border-radius:50%;display:grid;place-items:center;background:#fff;border:3px solid #FFE8B8;box-shadow:0 0 14px rgba(249,115,22,.25);overflow:hidden;flex:0 0 auto}.rk-avatar svg{width:100%;height:100%;display:block}.rk-turn-label{font-weight:900;color:#9B8FCC;text-transform:uppercase;letter-spacing:.14em}.rk-turn-label.active{color:#F97316}.rk-block{margin:0 22px 12px 92px;border-left:6px solid #7F77DD;background:#F1EEFF;border-radius:0 16px 16px 0;padding:12px 16px}.rk-mini{font-size:12px;font-weight:900;color:#7F77DD;text-transform:uppercase;letter-spacing:.08em;margin-bottom:5px}.rk-bubble{font-weight:900;line-height:1.5;color:#2F2763}.rk-said{margin:0 22px 10px 92px;border-left:6px solid #7F77DD;background:#F1EEFF;border-radius:0 16px 16px 0;padding:10px 16px}.rk-model{margin:0 22px 10px 92px;border-left:6px solid #1D9E75;background:#E1F5EE;border-radius:0 16px 16px 0;padding:10px 16px}.rk-improve{margin:0 22px 10px 92px;border-left:6px solid #F97316;background:#FFF0E6;border-radius:0 16px 16px 0;padding:10px 16px}.rk-score-row{display:flex;gap:8px;margin:0 22px 18px 92px}.rk-chip{min-width:80px;text-align:center;border:2px solid #EDE9FA;border-radius:16px;background:#fff;padding:8px 10px}.rk-chip b{display:block;color:#F97316;font-size:22px}.rk-chip span{display:block;color:#9B8FCC;font-size:12px;font-weight:900}.rk-turn-box{margin:0 22px 14px 92px;border:3px solid #7F77DD;border-radius:22px;padding:14px 18px;background:#fff}.rk-turn-box.disabled{border-color:#EDE9FA;background:#FBFAFF}.rk-say-row{display:flex;align-items:center;gap:16px}.rk-mic{border:2px solid #BDB8D8;background:#fff;border-radius:18px;min-width:190px;padding:14px 20px;font-weight:900;font-size:21px;color:#111;cursor:pointer}.rk-mic.listening{background:#7F77DD;color:#fff;border-color:#7F77DD}.rk-hint{color:#9B8FCC;font-style:italic;font-weight:900}.rk-hidden-input{margin-top:12px;width:100%;border:2px solid #DCD7FF;border-radius:14px;padding:11px 13px;font:900 15px Nunito,sans-serif}.rk-actions{margin:0 22px 20px 92px;display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap}.rk-btn{border:2px solid #DCD7FF;border-radius:16px;background:#fff;color:#3D3560;padding:12px 24px;font-weight:900;font-size:16px;cursor:pointer;font-family:Nunito,sans-serif}.rk-primary{background:#F97316;color:#fff;border-color:#F97316}.rk-picker{background:#fff;border:2px solid #EDE9FA;border-radius:24px;padding:18px;margin-bottom:18px}.rk-picker-title{font-family:Fredoka,sans-serif;color:#F97316;font-size:22px;margin-bottom:12px;text-align:center}.rk-avatar-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(88px,1fr));gap:12px}.rk-avatar-choice{border:2px solid #EDE9FA;background:#fff;border-radius:18px;padding:8px;cursor:pointer;text-align:center;font-weight:900;color:#7F77DD}.rk-avatar-choice.active{border-color:#F97316;background:#FFF0E6;color:#F97316}.rk-avatar-choice .rk-avatar{width:68px;height:68px;margin:0 auto 6px}.rk-editor-body{flex:1;min-height:0;overflow:auto;padding:22px}.rk-editor-wrap{max-width:1020px;margin:0 auto}.rk-edit-card{background:#fff;border:2px solid #EDE9FA;border-radius:24px;margin-bottom:18px;overflow:hidden}.rk-edit-head{display:flex;align-items:center;gap:12px;border-bottom:1px solid #F0EEF8;padding:16px 22px}.rk-edit-title{font-family:Fredoka,sans-serif;color:#F97316;font-size:22px}.rk-edit-content{padding:18px 22px}.rk-grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px}.rk-grid3{display:grid;grid-template-columns:1fr 1fr 140px;gap:14px}.rk-label{display:block;margin:0 0 6px;color:#9B8FCC;font-weight:900;font-size:12px;text-transform:uppercase;letter-spacing:.08em}.rk-input,.rk-textarea{width:100%;border:2px solid #DCD7FF;border-radius:13px;background:#FBFAFF;color:#221A3F;font:900 16px Nunito,sans-serif;padding:10px 14px;outline:none;box-sizing:border-box}.rk-textarea{min-height:90px;resize:vertical;line-height:1.5}.rk-turn-edit{border:2px solid #EDE9FA;border-radius:20px;padding:16px;margin-bottom:14px}.rk-remove{float:right;border:2px solid #D85A30;color:#D85A30;background:#fff;border-radius:12px;padding:5px 10px;font-weight:900;cursor:pointer}.rk-savebar{position:sticky;bottom:0;display:grid;grid-template-columns:auto 1fr auto;gap:14px;align-items:center;background:#F8F7FF;padding:18px 0 0}.rk-status{text-align:center;color:#7F77DD;font-weight:900}.rk-complete{display:grid;place-items:center;min-height:100%;padding:28px}.rk-complete-card{text-align:center;background:#fff;border:2px solid #EDE9FA;border-radius:28px;padding:42px;max-width:540px;box-shadow:0 8px 26px rgba(127,119,221,.10)}@media(max-width:760px){.rk-scroll{padding:12px}.rk-block,.rk-said,.rk-model,.rk-improve,.rk-score-row,.rk-turn-box,.rk-actions{margin-left:22px}.rk-grid2,.rk-grid3,.rk-savebar{grid-template-columns:1fr}.rk-say-row{flex-direction:column;align-items:flex-start}.rk-mic{width:100%}}
</style>
<div id="roleplay-kids-root"></div>
<script>
window.RK_ACTIVITY_ID=<?= json_encode($activityId, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
window.RK_RETURN_TO=<?= json_encode($returnTo, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
window.RK_SAVED_SCENE=<?= json_encode($savedScene, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
window.RK_SAVED_TURNS=<?= json_encode($savedTurns, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
window.RK_ALLOW_EDITOR=<?= json_encode($allowEditor) ?>;
(function(){
const root=document.getElementById('roleplay-kids-root');const AV=[['ANGIE','#FFD21F','#FF5BA8','👧'],['ANY','#1F1F2E','#70E000','👧🏾'],['BENNY','#111827','#FF6B00','👦🏾'],['JAYJAY','#FFE56B','#19A974','👦🏼'],['JESUS','#0F172A','#EF4444','👦'],['JOHN','#6B3A20','#F97316','👦'],['LEEANN','#111827','#22C55E','👧🏻'],['MARYJAY','#FF7A00','#3B82F6','👧'],['NELLA','#F59E0B','#FF7AB6','👧🏼'],['TEACHER','#9B5A2E','#7F77DD','👩‍🏫'],['VICTOR','#4B1D1D','#F97316','👦🏽'],['VIOLET','#FF2D8D','#22C55E','👧🏻']];let scene=normScene(window.RK_SAVED_SCENE||{});let turns=normTurns(window.RK_SAVED_TURNS||[]);let view=window.RK_ALLOW_EDITOR?'editor':'avatar';let completed=0;let answers=[];let scores=[];let shownAnswers=[];let activeInput='';let status='';let saving=false;
function h(v){return String(v??'').replace(/[&<>"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]))}function uid(){return 'rk_'+Math.random().toString(36).slice(2,9)+'_'+Date.now()}function av(id){return AV.find(a=>a[0]===id)||AV[0]}function avSvg(id){const a=av(id);return '<svg viewBox="0 0 100 100" aria-hidden="true"><defs><radialGradient id="g'+a[0]+'" cx="50%" cy="50%"><stop offset="0%" stop-color="#fff"/><stop offset="80%" stop-color="#fff"/><stop offset="100%" stop-color="#FFE8B8"/></radialGradient></defs><circle cx="50" cy="50" r="48" fill="url(#g'+a[0]+')" stroke="#FFD77A" stroke-width="3"/><circle cx="50" cy="45" r="27" fill="#F3B083"/><path d="M23 35q27-24 54 0v16q-9-18-27-18T23 51z" fill="'+a[1]+'"/><circle cx="40" cy="44" r="3"/><circle cx="60" cy="44" r="3"/><path d="M38 58q12 10 24 0" fill="none" stroke="#111" stroke-width="3" stroke-linecap="round"/><path d="M25 82q25-20 50 0" fill="'+a[2]+'"/><text x="50" y="95" text-anchor="middle" font-size="16">'+a[3]+'</text></svg>'}function normScene(s){s=s||{};return{title:String(s.title||'Roleplay Kids'),scenario:String(s.scenario||s.description||'At the Restaurant'),agentRole:String(s.agentRole||'Waiter'),studentRole:String(s.studentRole||'Customer'),icon:String(s.icon||'🌟'),level:String(s.level||'A1'),teacherAvatarId:String(s.teacherAvatarId||'TEACHER'),studentAvatarId:String(s.studentAvatarId||'ANGIE'),teacherVoiceId:String(s.teacherVoiceId||'nzFihrBIvB34imQBuxub')}}function normTurn(t){t=t||{};return{id:String(t.id||uid()),agent:String(t.agent||t.teacherLine||''),hint:String(t.hint||t.studentLine||''),ideal:String(t.ideal||t.studentLine||''),criteria:String(t.criteria||'')}}function normTurns(a){const out=(Array.isArray(a)?a:[]).map(normTurn);return out.length?out:[normTurn({agent:'Good evening! Are you ready to order?',hint:'Say hello and say what food you want.',ideal:"Good evening! I'd like the pasta, please.",criteria:'Greeting and polite food order.'}),normTurn({agent:'Would you like something to drink?',hint:'Ask for a drink politely.',ideal:"Yes, I'd like water, please.",criteria:'Polite drink order.'})]}function header(){const pct=turns.length?Math.round((completed/turns.length)*100):0;return '<div class="rk-top"><div class="rk-title">Roleplay Kids</div><div class="rk-progress"><div class="rk-progress-fill" style="width:'+pct+'%"></div></div><div class="rk-sub">'+h(scene.scenario||scene.title)+' · '+h(scene.agentRole)+' / '+h(scene.studentRole)+'</div></div>'}
function cleanWords(s){return String(s||'').toLowerCase().replace(/[^a-z0-9\s]/g,' ').split(/\s+/).filter(w=>w.length>1)}function scoreTurn(ans,ideal){const aw=cleanWords(ans),iw=cleanWords(ideal);const aset=new Set(aw);let matched=0;iw.forEach(w=>{if(aset.has(w))matched++});const accuracy=iw.length?Math.round((matched/iw.length)*10):7;const understood=ans.trim().length>=5&&aw.length>=2;const fluency=Math.max(1,Math.min(10,understood?Math.round(6+Math.min(4,aw.length/4)):3));const vocab=Math.max(1,Math.min(10,Math.round((new Set(aw).size/Math.max(3,iw.length))*10)));const overall=Math.round((accuracy+fluency+vocab)/3);let improve='Model answer: '+(ideal||'No model answer configured.');if(!understood)improve='Try a complete answer. '+improve;else if(accuracy<6)improve='Good try! Use more words from the model sentence. '+improve;else improve='Great speaking! '+improve;return{accuracy,fluency,vocab,overall,matched,total:iw.length,improve}}
function avatarPick(){return '<div class="rk-app">'+header()+'<div class="rk-scroll"><div class="rk-wrap"><div class="rk-picker"><div class="rk-picker-title">Choose your avatar</div><div class="rk-avatar-grid">'+AV.filter(a=>a[0]!=='TEACHER').map(a=>'<button type="button" class="rk-avatar-choice '+(scene.studentAvatarId===a[0]?'active':'')+'" data-action="choose-avatar" data-id="'+a[0]+'"><div class="rk-avatar">'+avSvg(a[0])+'</div>'+h(a[0])+'</button>').join('')+'</div><div style="text-align:center;margin-top:18px"><button type="button" class="rk-btn rk-primary" data-action="start">Start roleplay</button></div></div></div></div></div>'}
function player(){return '<div class="rk-app">'+header()+'<div class="rk-scroll"><div class="rk-wrap">'+turns.map((t,i)=>turnCard(t,i)).join('')+'</div></div></div>'}function turnCard(t,i){const isDone=i<completed,active=i===completed,locked=i>completed,ans=answers[i]||'',sc=scores[i]||null,show=shownAnswers[i];return '<section class="rk-card '+(locked?'locked':'')+'"><div class="rk-head"><div class="rk-avatar">'+avSvg(scene.teacherAvatarId)+'</div><div class="rk-turn-label '+(active?'active':'')+'">TURN '+(i+1)+' · '+(isDone?'✓ completed':active?'active':'🔒 locked')+'</div></div><div class="rk-block"><div class="rk-mini">'+h(scene.agentRole)+'</div><div class="rk-bubble">'+h(t.agent||'...')+'</div></div>'+(isDone?'<div class="rk-said"><div class="rk-mini">You said</div><div>'+h(ans)+'</div></div><div class="rk-model"><div class="rk-mini" style="color:#1D9E75">Model answer</div><div>'+h(t.ideal||'No model answer configured.')+'</div></div><div class="rk-improve"><div class="rk-mini" style="color:#F97316">Feedback</div><div>'+h(sc?sc.improve:'Good work!')+'</div></div><div class="rk-score-row"><div class="rk-chip"><b>'+h(sc?sc.fluency:0)+'</b><span>Fluency</span></div><div class="rk-chip"><b>'+h(sc?sc.accuracy:0)+'</b><span>Accuracy</span></div><div class="rk-chip"><b>'+h(sc?sc.vocab:0)+'</b><span>Vocab</span></div></div>':'')+(active?'<div class="rk-turn-box"><div class="rk-say-row"><div class="rk-avatar">'+avSvg(scene.studentAvatarId)+'</div><button type="button" class="rk-mic" data-action="mic" data-index="'+i+'">🎙 Now say it</button><span class="rk-hint">Hint: '+h(t.hint||'Answer naturally')+'</span></div><textarea class="rk-hidden-input" data-answer="1" placeholder="Speech will appear here. You can also type...">'+h(activeInput)+'</textarea></div>'+(show?'<div class="rk-model"><div class="rk-mini" style="color:#1D9E75">Model answer</div><div>'+h(t.ideal||'No model answer configured.')+'</div></div>':'')+'<div class="rk-actions"><button type="button" class="rk-btn" data-action="show-answer">Show answer</button><button type="button" class="rk-btn rk-primary" data-action="next">'+(i>=turns.length-1?'Finish':'Next')+'</button></div>':'')+(locked?'<div class="rk-turn-box disabled"><span class="rk-hint">Finish the previous turn to unlock this one.</span></div>':'')+'</section>'}
function complete(){const avg=scores.length?Math.round(scores.reduce((a,s)=>a+(s.overall||0),0)/scores.length*10):0;return '<div class="rk-app">'+header()+'<div class="rk-complete"><div class="rk-complete-card"><div class="rk-avatar" style="width:96px;height:96px;margin:0 auto 12px">'+avSvg(scene.studentAvatarId)+'</div><h1 style="font-family:Fredoka,sans-serif;color:#F97316">Great job!</h1><p style="font-weight:900;color:#7F77DD;margin:12px 0 20px">Score: '+avg+'%</p><button type="button" class="rk-btn rk-primary" data-action="restart">Try again</button></div></div></div>'}
function editor(){return '<div class="rk-app"><div class="rk-top"><div class="rk-title">Roleplay Kids Editor</div></div><div class="rk-editor-body"><div class="rk-editor-wrap"><section class="rk-edit-card"><div class="rk-edit-head"><div class="rk-edit-title">Kids roleplay settings</div></div><div class="rk-edit-content"><div class="rk-grid3"><div><label class="rk-label">Title</label><input class="rk-input" data-scene="title" value="'+h(scene.title)+'"></div><div><label class="rk-label">Scenario</label><input class="rk-input" data-scene="scenario" value="'+h(scene.scenario)+'"></div><div><label class="rk-label">Level</label><input class="rk-input" data-scene="level" value="'+h(scene.level)+'"></div></div><div class="rk-grid2" style="margin-top:14px"><div><label class="rk-label">Agent role</label><input class="rk-input" data-scene="agentRole" value="'+h(scene.agentRole)+'"></div><div><label class="rk-label">Student role</label><input class="rk-input" data-scene="studentRole" value="'+h(scene.studentRole)+'"></div></div><div style="margin-top:14px"><label class="rk-label">Teacher avatar</label><div class="rk-avatar-grid">'+AV.map(a=>'<button type="button" class="rk-avatar-choice '+(scene.teacherAvatarId===a[0]?'active':'')+'" data-action="teacher-avatar" data-id="'+a[0]+'"><div class="rk-avatar">'+avSvg(a[0])+'</div>'+h(a[0])+'</button>').join('')+'</div></div></div></section><section class="rk-edit-card"><div class="rk-edit-head"><div class="rk-edit-title">Conversation turns</div></div><div class="rk-edit-content">'+turns.map((t,i)=>'<div class="rk-turn-edit"><button type="button" class="rk-remove" data-action="remove-turn" data-index="'+i+'">Remove</button><div class="rk-turn-label active">Turn '+(i+1)+'</div><label class="rk-label">Agent line</label><textarea class="rk-textarea" data-turn="'+i+'" data-prop="agent">'+h(t.agent)+'</textarea><div class="rk-grid2" style="margin-top:12px"><div><label class="rk-label">Student hint</label><textarea class="rk-textarea" data-turn="'+i+'" data-prop="hint">'+h(t.hint)+'</textarea></div><div><label class="rk-label">Model sentence</label><textarea class="rk-textarea" data-turn="'+i+'" data-prop="ideal">'+h(t.ideal)+'</textarea></div></div><label class="rk-label" style="margin-top:12px">Criteria</label><input class="rk-input" data-turn="'+i+'" data-prop="criteria" value="'+h(t.criteria)+'"></div>').join('')+'<button type="button" class="rk-btn" data-action="add-turn">+ Add turn</button></div></section><div class="rk-savebar"><button type="button" class="rk-btn" data-action="preview">Preview as student</button><div class="rk-status">'+h(status)+'</div><button type="button" class="rk-btn rk-primary" data-action="save" '+(saving?'disabled':'')+'>'+(saving?'Saving...':'Save kids roleplay')+'</button></div></div></div></div>'}
function render(){if(!root)return;const sc=root.querySelector('.rk-scroll,.rk-editor-body');const top=sc?sc.scrollTop:0;root.innerHTML=view==='editor'?editor():(view==='avatar'?avatarPick():(view==='complete'?complete():player()));const sc2=root.querySelector('.rk-scroll,.rk-editor-body');if(sc2)sc2.scrollTop=top}
function next(){const i=completed;if(!activeInput.trim()&&!shownAnswers[i]){alert('Say/type your answer or tap Show answer first.');return}answers[i]=activeInput.trim()||'(Used Show answer)';scores[i]=scoreTurn(activeInput,turns[i]?turns[i].ideal:'');completed=Math.min(turns.length,completed+1);activeInput='';if(completed>=turns.length)view='complete';render()}function startMic(i){const SR=window.SpeechRecognition||window.webkitSpeechRecognition;if(!SR){alert('Speech recognition is not supported in this browser. You can type your answer.');return}const rec=new SR();rec.lang='en-US';rec.continuous=false;rec.interimResults=false;const btn=root.querySelector('[data-action="mic"][data-index="'+i+'"]');const box=root.querySelector('[data-answer="1"]');if(btn){btn.classList.add('listening');btn.textContent='🎙 Listening...'}rec.onresult=e=>{activeInput=e.results[0][0].transcript;if(box)box.value=activeInput};rec.onerror=()=>{if(btn){btn.classList.remove('listening');btn.textContent='🎙 Now say it'}};rec.onend=()=>{if(btn){btn.classList.remove('listening');btn.textContent='🎙 Now say it'}};rec.start()}
root.addEventListener('input',e=>{const el=e.target;if(el.dataset.scene){scene[el.dataset.scene]=el.value;render();return}if(el.dataset.turn){const i=Number(el.dataset.turn);turns[i]=Object.assign({},turns[i],{[el.dataset.prop]:el.value});render();return}if(el.dataset.answer){activeInput=el.value}});root.addEventListener('click',async e=>{const btn=e.target.closest('[data-action]');if(!btn)return;e.preventDefault();const a=btn.dataset.action;if(a==='choose-avatar'){scene.studentAvatarId=btn.dataset.id;render()}if(a==='teacher-avatar'){scene.teacherAvatarId=btn.dataset.id;render()}if(a==='start'){view='player';render()}if(a==='mic')startMic(Number(btn.dataset.index));if(a==='show-answer'){shownAnswers[completed]=true;render()}if(a==='next')next();if(a==='restart'){view='avatar';completed=0;answers=[];scores=[];shownAnswers=[];activeInput='';render()}if(a==='preview'){view='avatar';completed=0;answers=[];scores=[];shownAnswers=[];activeInput='';render()}if(a==='add-turn'){turns.push({id:uid(),agent:'',hint:'',ideal:'',criteria:''});render()}if(a==='remove-turn'){turns=turns.filter((_,i)=>i!==Number(btn.dataset.index));if(!turns.length)turns=normTurns([]);render()}if(a==='save'){if(!window.RK_ACTIVITY_ID){status='No activity ID - cannot save.';render();return}saving=true;status='Saving...';render();try{const resp=await fetch('save.php',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({id:window.RK_ACTIVITY_ID,scene:scene,turns:turns})});const json=await resp.json().catch(()=>({}));if(!resp.ok||!json.ok)throw new Error(json.error||('HTTP '+resp.status));status='Saved successfully'}catch(err){status='Could not save: '+err.message}finally{saving=false;render()}}});try{render()}catch(err){console.error('[roleplay kids] render error',err);root.innerHTML='<div style="padding:20px">Roleplay Kids could not render. Check console.</div>'}
})();
</script>
<?php
$content = ob_get_clean();
error_log('[roleplay_kids/viewer] html length=' . strlen($content));
render_activity_viewer($viewerTitle, '🌟', $content);
