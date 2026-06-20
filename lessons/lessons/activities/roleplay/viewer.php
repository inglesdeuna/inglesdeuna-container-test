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
  'title' => 'Roleplay',
  'scenario' => 'At the Restaurant',
  'agentRole' => 'Waiter',
  'studentRole' => 'Customer',
  'icon' => '🎭',
  'level' => 'B1'
];
$defaultTurns = [[
  'agent' => 'Good evening! Are you ready to order?',
  'hint' => "Greet the waiter and say what you'd like to eat.",
  'ideal' => "Good evening! I'd like the pasta please.",
  'criteria' => 'Greeting, polite request, food item.'
], [
  'agent' => 'Would you like something to drink?',
  'hint' => 'Order a drink politely.',
  'ideal' => "Yes, I'd like a glass of water, please.",
  'criteria' => 'Polite drink order.'
], [
  'agent' => 'Will that be all for you tonight?',
  'hint' => 'Answer politely and finish the order.',
  'ideal' => 'Yes, that will be all. Thank you.',
  'criteria' => 'Clear closing response.'
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
  error_log('[roleplay/viewer] load error: ' . $e->getMessage());
}

$viewerTitle = trim((string)($savedScene['title'] ?? '')) !== '' ? (string)$savedScene['title'] : 'Roleplay';
error_log('[roleplay/viewer] id=' . $activityId . ' mode=' . $mode . ' source=' . $source . ' editor=' . ($allowEditor ? 'yes' : 'no') . ' turns=' . count($savedTurns));

ob_start();
?>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@400;600&family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<style>
#roleplay-root{height:100%;min-height:0;background:#F8F7FF;font-family:Nunito,system-ui,sans-serif;color:#2F2763}.rp-app{height:100%;min-height:0;display:flex;flex-direction:column;background:#F8F7FF}.rp-top{flex:0 0 auto;background:#fff;border-bottom:1px solid #F0EEF8}.rp-title{height:58px;display:grid;place-items:center;font-family:Fredoka,sans-serif;font-size:26px;font-weight:600;letter-spacing:.03em;color:#F97316}.rp-progress{height:8px;background:#EEEDFE;overflow:hidden}.rp-progress-fill{height:100%;background:linear-gradient(90deg,#F97316 0%,#F97316 55%,#7F77DD 100%);transition:width .25s ease}.rp-sub{height:40px;display:grid;place-items:center;background:#FFF0E6;border-bottom:1px solid #FCDDBF;color:#C2580A;font-weight:900;letter-spacing:.03em}.rp-scroll{flex:1;min-height:0;overflow:auto;padding:18px}.rp-wrap{max-width:930px;margin:0 auto}.rp-card{background:#fff;border:1.5px solid #EDE9FA;border-radius:22px;margin:0 0 18px;box-shadow:0 4px 16px rgba(127,119,221,.07);overflow:hidden}.rp-card.locked{opacity:.45}.rp-card-head{display:flex;align-items:center;gap:12px;padding:18px 22px 10px}.rp-turn-label{font-weight:900;color:#9B8FCC;text-transform:uppercase;letter-spacing:.14em}.rp-turn-label.active{color:#F97316}.rp-avatar{width:44px;height:44px;border-radius:50%;display:grid;place-items:center;background:#FFF0E6;font-size:24px;flex:0 0 auto}.rp-block{margin:0 22px 12px;border-left:5px solid #7F77DD;background:#F1EEFF;border-radius:0 12px 12px 0;padding:12px 16px}.rp-mini{font-size:12px;font-weight:900;color:#7F77DD;text-transform:uppercase;letter-spacing:.08em;margin-bottom:5px}.rp-bubble{font-weight:800;line-height:1.5;color:#2F2763}.rp-said{margin:0 22px 10px;border-left:5px solid #7F77DD;background:#F1EEFF;border-radius:0 12px 12px 0;padding:10px 16px}.rp-improve{margin:0 22px 10px;border-left:5px solid #F97316;background:#FFF0E6;border-radius:0 12px 12px 0;padding:10px 16px}.rp-score-row{display:flex;gap:8px;margin:0 22px 18px 76px}.rp-chip{min-width:70px;text-align:center;border:1.5px solid #EDE9FA;border-radius:12px;background:#fff;padding:8px 10px}.rp-chip b{display:block;color:#F97316;font-size:20px}.rp-chip span{display:block;color:#9B8FCC;font-size:12px;font-weight:800}.rp-turn-box{margin:0 22px 20px 76px;border:2px solid #7F77DD;border-radius:18px;padding:14px 18px;background:#fff}.rp-turn-box.disabled{border-color:#EDE9FA;background:#FBFAFF}.rp-say-row{display:flex;align-items:center;gap:16px}.rp-mic{border:1.5px solid #BDB8D8;background:#fff;border-radius:13px;min-width:180px;padding:12px 18px;font-weight:900;font-size:20px;color:#111;cursor:pointer}.rp-mic.listening{background:#7F77DD;color:#fff;border-color:#7F77DD}.rp-hint{color:#9B8FCC;font-style:italic;font-weight:800}.rp-hidden-input{margin-top:12px;width:100%;border:1.5px solid #DCD7FF;border-radius:12px;padding:10px 12px;font:800 15px Nunito,sans-serif}.rp-editor-body{flex:1;min-height:0;overflow:auto;padding:22px}.rp-editor-wrap{max-width:1020px;margin:0 auto}.rp-edit-card{background:#fff;border:1.5px solid #EDE9FA;border-radius:22px;margin-bottom:18px;overflow:hidden}.rp-edit-head{display:flex;align-items:center;gap:12px;border-bottom:1px solid #F0EEF8;padding:16px 22px}.rp-edit-title{font-family:Fredoka,sans-serif;color:#F97316;font-size:22px}.rp-edit-content{padding:18px 22px}.rp-grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px}.rp-grid3{display:grid;grid-template-columns:1fr 1fr 140px;gap:14px}.rp-label{display:block;margin:0 0 6px;color:#9B8FCC;font-weight:900;font-size:12px;text-transform:uppercase;letter-spacing:.08em}.rp-input,.rp-textarea{width:100%;border:1.5px solid #DCD7FF;border-radius:11px;background:#FBFAFF;color:#221A3F;font:800 16px Nunito,sans-serif;padding:10px 14px;outline:none;box-sizing:border-box}.rp-textarea{min-height:90px;resize:vertical;line-height:1.5}.rp-turn-edit{border:1.5px solid #EDE9FA;border-radius:18px;padding:16px;margin-bottom:14px}.rp-remove{float:right;border:1.5px solid #D85A30;color:#D85A30;background:#fff;border-radius:9px;padding:5px 10px;font-weight:900;cursor:pointer}.rp-btn{border:1.5px solid #DCD7FF;border-radius:13px;background:#fff;color:#3D3560;padding:12px 24px;font-weight:900;font-size:16px;cursor:pointer;font-family:Nunito,sans-serif}.rp-primary{background:#F97316;color:#fff;border-color:#F97316}.rp-savebar{position:sticky;bottom:0;display:grid;grid-template-columns:auto 1fr auto;gap:14px;align-items:center;background:#F8F7FF;padding:18px 0 0}.rp-status{text-align:center;color:#7F77DD;font-weight:900}@media(max-width:760px){.rp-scroll{padding:12px}.rp-score-row,.rp-turn-box{margin-left:22px}.rp-grid2,.rp-grid3,.rp-savebar{grid-template-columns:1fr}.rp-say-row{flex-direction:column;align-items:flex-start}.rp-mic{width:100%}}
</style>
<div id="roleplay-root"></div>
<script>
window.RP_ACTIVITY_ID=<?= json_encode($activityId, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
window.RP_RETURN_TO=<?= json_encode($returnTo, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
window.RP_SAVED_SCENE=<?= json_encode($savedScene, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
window.RP_SAVED_TURNS=<?= json_encode($savedTurns, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
window.RP_ALLOW_EDITOR=<?= json_encode($allowEditor) ?>;
(function(){
const root=document.getElementById('roleplay-root');let scene=normScene(window.RP_SAVED_SCENE||{});let turns=normTurns(window.RP_SAVED_TURNS||[]);let view=window.RP_ALLOW_EDITOR?'editor':'player';let completed=0;let answers=[];let scores=[];let activeInput='';let listening=-1;let status='';let saving=false;
function h(v){return String(v??'').replace(/[&<>"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]))}function uid(){return 'rp_'+Math.random().toString(36).slice(2,9)+'_'+Date.now()}function normScene(s){s=s||{};return{title:String(s.title||'Roleplay'),scenario:String(s.scenario||s.description||'At the Restaurant'),agentRole:String(s.agentRole||'Waiter'),studentRole:String(s.studentRole||'Customer'),icon:String(s.icon||'🎭'),level:String(s.level||'B1')}}function normTurn(t){t=t||{};return{id:String(t.id||uid()),agent:String(t.agent||t.teacherLine||''),hint:String(t.hint||t.studentLine||''),ideal:String(t.ideal||t.studentLine||''),criteria:String(t.criteria||'')}}function normTurns(a){const out=(Array.isArray(a)?a:[]).map(normTurn);return out.length?out:[normTurn({agent:'Good evening! Are you ready to order?',hint:"Greet the waiter and say what you'd like to eat.",ideal:"Good evening! I'd like the pasta please.",criteria:'Polite greeting and food order.'}),normTurn({agent:'Would you like something to drink?',hint:'Order a drink politely.',ideal:"Yes, I'd like a glass of water, please.",criteria:'Polite drink order.'})]}function header(){const pct=turns.length?Math.round((completed/turns.length)*100):0;return '<div class="rp-top"><div class="rp-title">Roleplay</div><div class="rp-progress"><div class="rp-progress-fill" style="width:'+pct+'%"></div></div><div class="rp-sub">'+h(scene.scenario||scene.title)+' · '+h(scene.agentRole)+' / '+h(scene.studentRole)+'</div></div>'}
function words(s){return String(s||'').toLowerCase().replace(/[^a-z0-9\s]/g,'').split(/\s+/).filter(Boolean)}function grade(ans,ideal){const aw=words(ans),iw=words(ideal);let match=0;iw.forEach(w=>{if(aw.includes(w))match++});let base=iw.length?Math.round((match/iw.length)*100):70;let flu=Math.min(10,Math.max(6,Math.round((ans.length>20?8:6)+(ans.length>45?1:0))));let acc=Math.min(10,Math.max(6,Math.round(base/12)+2));let voc=Math.min(10,Math.max(6,Math.round(new Set(aw).size/3)));return{flu:flu,acc:acc,voc:voc,improve:base>=75?'Great! Try also adding one more natural detail.':'Good try. Use a complete polite sentence closer to the model answer.'}}
function player(){return '<div class="rp-app">'+header()+'<div class="rp-scroll"><div class="rp-wrap">'+turns.map((t,i)=>turnCard(t,i)).join('')+'</div></div></div>'}function turnCard(t,i){const isDone=i<completed,active=i===completed,locked=i>completed,ans=answers[i]||'',sc=scores[i]||null;return '<section class="rp-card '+(locked?'locked':'')+'"><div class="rp-card-head"><div class="rp-avatar">👨‍🍳</div><div class="rp-turn-label '+(active?'active':'')+'">TURN '+(i+1)+' · '+(isDone?'✓ completed':active?'active':'🔒 locked')+'</div></div><div class="rp-block"><div class="rp-mini">'+h(scene.agentRole)+'</div><div class="rp-bubble">'+h(t.agent||'...')+'</div></div>'+(isDone?'<div class="rp-said"><div class="rp-mini">You said</div><div>'+h(ans)+'</div></div><div class="rp-improve"><div class="rp-mini" style="color:#F97316">Improvement</div><div>'+h(sc?sc.improve:'Great! Keep practicing.')+'</div></div><div class="rp-score-row"><div class="rp-chip"><b>'+h(sc?sc.flu:8)+'</b><span>Fluency</span></div><div class="rp-chip"><b>'+h(sc?sc.acc:8)+'</b><span>Accuracy</span></div><div class="rp-chip"><b>'+h(sc?sc.voc:8)+'</b><span>Vocab</span></div></div>':'')+(active?'<div class="rp-turn-box"><div class="rp-say-row"><button class="rp-mic '+(listening===i?'listening':'')+'" data-action="mic" data-index="'+i+'">🎙 Now say it</button><span class="rp-hint">Hint: '+h(t.hint||'Answer naturally')+'</span></div><textarea class="rp-hidden-input" data-answer="1" placeholder="Speech will appear here. You can also type...">'+h(activeInput)+'</textarea><div style="margin-top:12px;display:flex;justify-content:flex-end"><button class="rp-btn rp-primary" data-action="submit">Submit turn</button></div></div>':'')+(locked?'<div class="rp-turn-box disabled"><span class="rp-hint">Complete the previous turn to unlock this one.</span></div>':'')+'</section>'}
function editor(){return '<div class="rp-app"><div class="rp-top"><div class="rp-title">Roleplay Editor</div></div><div class="rp-editor-body"><div class="rp-editor-wrap"><section class="rp-edit-card"><div class="rp-edit-head"><div class="rp-edit-title">Roleplay settings</div></div><div class="rp-edit-content"><div class="rp-grid3"><div><label class="rp-label">Activity title</label><input class="rp-input" data-scene="title" value="'+h(scene.title)+'"></div><div><label class="rp-label">Scenario</label><input class="rp-input" data-scene="scenario" value="'+h(scene.scenario)+'"></div><div><label class="rp-label">Level</label><input class="rp-input" data-scene="level" value="'+h(scene.level)+'"></div></div><div class="rp-grid2" style="margin-top:14px"><div><label class="rp-label">Agent role</label><input class="rp-input" data-scene="agentRole" value="'+h(scene.agentRole)+'"></div><div><label class="rp-label">Student role</label><input class="rp-input" data-scene="studentRole" value="'+h(scene.studentRole)+'"></div></div></div></section><section class="rp-edit-card"><div class="rp-edit-head"><div class="rp-edit-title">Conversation turns</div></div><div class="rp-edit-content">'+turns.map((t,i)=>'<div class="rp-turn-edit"><button class="rp-remove" data-action="remove-turn" data-index="'+i+'">Remove</button><div class="rp-turn-label active">Turn '+(i+1)+'</div><label class="rp-label">Agent line</label><textarea class="rp-textarea" data-turn="'+i+'" data-prop="agent">'+h(t.agent)+'</textarea><div class="rp-grid2" style="margin-top:12px"><div><label class="rp-label">Student hint</label><textarea class="rp-textarea" data-turn="'+i+'" data-prop="hint">'+h(t.hint)+'</textarea></div><div><label class="rp-label">Ideal answer</label><textarea class="rp-textarea" data-turn="'+i+'" data-prop="ideal">'+h(t.ideal)+'</textarea></div></div><label class="rp-label" style="margin-top:12px">Criteria</label><input class="rp-input" data-turn="'+i+'" data-prop="criteria" value="'+h(t.criteria)+'"></div>').join('')+'<button class="rp-btn" data-action="add-turn">+ Add turn</button></div></section><div class="rp-savebar"><button class="rp-btn" data-action="preview">Preview as student</button><div class="rp-status">'+h(status)+'</div><button class="rp-btn rp-primary" data-action="save" '+(saving?'disabled':'')+'>'+(saving?'Saving...':'Save roleplay')+'</button></div></div></div></div>'}
function render(){if(!root)return;root.innerHTML=view==='editor'?editor():player()}function submit(){if(!activeInput.trim()){alert('Please say or type your answer first.');return}answers[completed]=activeInput.trim();scores[completed]=grade(activeInput,turns[completed]?turns[completed].ideal:'');completed=Math.min(turns.length,completed+1);activeInput='';render()}function startMic(i){const SR=window.SpeechRecognition||window.webkitSpeechRecognition;if(!SR){alert('Speech recognition is not supported in this browser. You can type your answer.');return}const rec=new SR();rec.lang='en-US';rec.continuous=false;rec.interimResults=false;listening=i;render();rec.onresult=e=>{activeInput=e.results[0][0].transcript;listening=-1;render()};rec.onerror=()=>{listening=-1;render()};rec.onend=()=>{listening=-1;render()};rec.start()}
root.addEventListener('input',e=>{const el=e.target;if(el.dataset.scene){scene[el.dataset.scene]=el.value;render();return}if(el.dataset.turn){const i=Number(el.dataset.turn);turns[i]=Object.assign({},turns[i],{[el.dataset.prop]:el.value});render();return}if(el.dataset.answer){activeInput=el.value}});root.addEventListener('click',async e=>{const btn=e.target.closest('[data-action]');if(!btn)return;const a=btn.dataset.action;if(a==='mic')startMic(Number(btn.dataset.index));if(a==='submit')submit();if(a==='preview'){view='player';completed=0;activeInput='';answers=[];scores=[];render()}if(a==='add-turn'){turns.push({id:uid(),agent:'',hint:'',ideal:'',criteria:''});render()}if(a==='remove-turn'){turns=turns.filter((_,i)=>i!==Number(btn.dataset.index));if(!turns.length)turns=normTurns([]);render()}if(a==='save'){if(!window.RP_ACTIVITY_ID){status='No activity ID - cannot save.';render();return}saving=true;status='Saving...';render();try{const resp=await fetch('save.php',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({id:window.RP_ACTIVITY_ID,scene:scene,turns:turns})});const json=await resp.json().catch(()=>({}));if(!resp.ok||!json.ok)throw new Error(json.error||('HTTP '+resp.status));status='Saved successfully'}catch(err){status='Could not save: '+err.message}finally{saving=false;render()}}});try{render()}catch(err){console.error('[roleplay] render error',err);root.innerHTML='<div style="padding:20px">Roleplay could not render. Check console.</div>'}
})();
</script>
<?php
$content = ob_get_clean();
error_log('[roleplay/viewer] html length=' . strlen($content));
render_activity_viewer($viewerTitle, '🎭', $content);
