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
  'scenario' => 'Practice this conversation in English.',
  'agentRole' => 'Teacher',
  'studentRole' => 'Student',
  'icon' => '🎭',
  'level' => 'B1'
];
$defaultTurns = [[
  'agent' => 'Hello! Let us begin the roleplay.',
  'hint' => 'Answer politely and continue the conversation.',
  'ideal' => 'Hello! I am ready to begin.',
  'criteria' => 'Respond clearly and naturally.'
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
#roleplay-root{height:100%;min-height:0;display:flex;flex-direction:column;background:#F8F7FF}.rp-app{height:100%;min-height:0;display:flex;flex-direction:column;font-family:Nunito,system-ui,sans-serif;color:#3D3560;background:#F8F7FF}.rp-top{height:54px;flex-shrink:0;display:flex;align-items:center;justify-content:center;position:relative;background:#fff;border-bottom:1px solid #F0EEF8}.rp-title{color:#F97316;font-family:Fredoka,sans-serif;font-size:21px;font-weight:600}.rp-badge{position:absolute;right:16px;top:11px;border:1.5px solid #DCD7FF;background:#F5F3FF;color:#5B51C8;border-radius:999px;padding:5px 16px;font-weight:900;font-size:13px}.rp-body{flex:1;min-height:0;overflow-y:auto;padding:22px}.rp-wrap{max-width:1120px;margin:0 auto}.rp-card{background:#fff;border:1.5px solid #EDE9FA;border-radius:22px;overflow:hidden;margin-bottom:18px;box-shadow:0 3px 14px rgba(127,119,221,.05)}.rp-head{padding:16px 22px;border-bottom:1px solid #F0EEF8;display:flex;align-items:center;gap:12px}.rp-icon{width:34px;height:34px;border-radius:12px;display:grid;place-items:center;background:#FFF0E6;color:#C2580A;font-weight:900}.rp-card-title{font-family:Fredoka,sans-serif;color:#F97316;font-size:21px;font-weight:600}.rp-card-body{padding:18px 22px}.rp-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:14px}.rp-grid-3{display:grid;grid-template-columns:1fr 1fr 150px;gap:14px}.rp-label{display:block;margin:0 0 6px;color:#9B8FCC;font-weight:900;font-size:12px;text-transform:uppercase;letter-spacing:.08em}.rp-input,.rp-textarea{width:100%;border:1.5px solid #DCD7FF;border-radius:11px;background:#FBFAFF;color:#221A3F;font:800 16px Nunito,sans-serif;padding:10px 14px;outline:none;box-sizing:border-box}.rp-textarea{min-height:90px;resize:vertical;line-height:1.5}.rp-note{background:#F5F3FF;border:1px solid #EDE9FA;color:#5B51C8;border-radius:12px;padding:12px 16px;font-weight:900;margin-bottom:14px}.rp-turn{border:1.5px solid #EDE9FA;border-radius:18px;padding:16px;margin-bottom:14px;background:#fff}.rp-turn-title{color:#F97316;font-family:Fredoka,sans-serif;font-size:19px;font-weight:600;margin-bottom:10px}.rp-remove{float:right;border:1.5px solid #D85A30;color:#D85A30;background:#fff;border-radius:9px;padding:5px 10px;font-weight:900;cursor:pointer}.rp-add{width:100%;border:1.5px solid #DCD7FF;border-radius:13px;background:#fff;color:#3D3560;padding:13px 16px;font-weight:900;font-size:16px;cursor:pointer;text-align:left}.rp-savebar{position:sticky;bottom:0;display:grid;grid-template-columns:auto 1fr auto;gap:14px;align-items:center;background:#F8F7FF;padding:18px 0 0}.rp-btn{border:1.5px solid #DCD7FF;border-radius:13px;background:#fff;color:#3D3560;padding:12px 24px;font-weight:900;font-size:16px;cursor:pointer;font-family:Nunito,sans-serif}.rp-primary{background:#F97316;color:#fff;border-color:#F97316}.rp-status{text-align:center;color:#7F77DD;font-weight:900}.rp-player{flex:1;min-height:0;display:grid;grid-template-columns:42% 58%;background:#F8F7FF}.rp-scene{overflow-y:auto;padding:22px;background:#fff;border-right:1px solid #F0EEF8;line-height:1.65}.rp-stage{overflow-y:auto;padding:22px}.rp-chat{background:#fff;border:1.5px solid #EDE9FA;border-radius:22px;padding:22px;box-shadow:0 4px 20px rgba(127,119,221,.10)}.rp-agent{background:#F5F3FF;border:1px solid #EDE9FA;border-radius:16px;padding:14px;margin-bottom:14px;font-weight:800}.rp-feedback{margin-top:12px;background:#FFF0E6;border-left:4px solid #F97316;border-radius:10px;padding:12px;font-weight:800;color:#C2580A}@media(max-width:850px){.rp-grid-2,.rp-grid-3,.rp-player,.rp-savebar{grid-template-columns:1fr}}
</style>
<div id="roleplay-root"></div>
<script>
window.RP_ACTIVITY_ID=<?= json_encode($activityId, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
window.RP_RETURN_TO=<?= json_encode($returnTo, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
window.RP_SAVED_SCENE=<?= json_encode($savedScene, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
window.RP_SAVED_TURNS=<?= json_encode($savedTurns, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
window.RP_ALLOW_EDITOR=<?= json_encode($allowEditor) ?>;
(function(){
const root=document.getElementById('roleplay-root');let scene=normScene(window.RP_SAVED_SCENE||{});let turns=normTurns(window.RP_SAVED_TURNS||[]);let view=window.RP_ALLOW_EDITOR?'editor':'player';let status='';let saving=false;let idx=0;let answer='';let feedback='';
function h(v){return String(v??'').replace(/[&<>"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]))}function uid(){return 'rp_'+Math.random().toString(36).slice(2,9)+'_'+Date.now()}function normScene(s){s=s||{};return{title:String(s.title||'Roleplay'),scenario:String(s.scenario||s.description||'Practice this conversation in English.'),agentRole:String(s.agentRole||'Teacher'),studentRole:String(s.studentRole||'Student'),icon:String(s.icon||'🎭'),level:String(s.level||'B1')}}function normTurn(t){t=t||{};return{id:String(t.id||uid()),agent:String(t.agent||t.teacherLine||''),hint:String(t.hint||t.studentLine||''),ideal:String(t.ideal||t.studentLine||''),criteria:String(t.criteria||'')}}function normTurns(a){const out=(Array.isArray(a)?a:[]).map(normTurn);return out.length?out:[normTurn({agent:'Hello! Let us begin the roleplay.',hint:'Answer politely and continue the conversation.',ideal:'Hello! I am ready to begin.',criteria:'Respond clearly and naturally.'})]}function top(edit){return '<div class="rp-top"><div class="rp-title">'+h(scene.icon)+' '+h(scene.title)+'</div>'+(edit?'<div class="rp-badge">Edit mode</div>':'')+'</div>'}
function editor(){return '<div class="rp-app">'+top(true)+'<div class="rp-body"><div class="rp-wrap"><section class="rp-card"><div class="rp-head"><div class="rp-icon">🎭</div><div class="rp-card-title">Roleplay settings</div></div><div class="rp-card-body"><div class="rp-grid-3"><div><label class="rp-label">Activity title</label><input class="rp-input" data-scene="title" value="'+h(scene.title)+'"></div><div><label class="rp-label">Icon</label><input class="rp-input" data-scene="icon" value="'+h(scene.icon)+'"></div><div><label class="rp-label">Level</label><input class="rp-input" data-scene="level" value="'+h(scene.level)+'"></div></div><div class="rp-grid-2" style="margin-top:14px"><div><label class="rp-label">Agent role</label><input class="rp-input" data-scene="agentRole" value="'+h(scene.agentRole)+'"></div><div><label class="rp-label">Student role</label><input class="rp-input" data-scene="studentRole" value="'+h(scene.studentRole)+'"></div></div><div style="margin-top:14px"><label class="rp-label">Scenario / instructions</label><textarea class="rp-textarea" data-scene="scenario">'+h(scene.scenario)+'</textarea></div></div></section><section class="rp-card"><div class="rp-head"><div class="rp-icon">💬</div><div class="rp-card-title">Conversation turns</div><div style="margin-left:auto;background:#F5F3FF;color:#7F77DD;border-radius:8px;padding:3px 12px;font-weight:900">'+turns.length+' turns</div></div><div class="rp-card-body"><div class="rp-note">Add the agent line, the student hint, an ideal answer, and criteria.</div>'+turns.map((t,i)=>'<div class="rp-turn"><button class="rp-remove" data-action="remove-turn" data-index="'+i+'">Remove</button><div class="rp-turn-title">Turn '+(i+1)+'</div><label class="rp-label">Agent line</label><textarea class="rp-textarea" data-turn="'+i+'" data-prop="agent">'+h(t.agent)+'</textarea><div class="rp-grid-2" style="margin-top:12px"><div><label class="rp-label">Student hint</label><textarea class="rp-textarea" data-turn="'+i+'" data-prop="hint">'+h(t.hint)+'</textarea></div><div><label class="rp-label">Ideal answer</label><textarea class="rp-textarea" data-turn="'+i+'" data-prop="ideal">'+h(t.ideal)+'</textarea></div></div><label class="rp-label" style="margin-top:12px">Criteria</label><input class="rp-input" data-turn="'+i+'" data-prop="criteria" value="'+h(t.criteria)+'"></div>').join('')+'<button class="rp-add" data-action="add-turn">+ Add roleplay turn</button></div></section><div class="rp-savebar"><button class="rp-btn" data-action="preview">Preview as student</button><div class="rp-status">'+h(status)+'</div><button class="rp-btn rp-primary" data-action="save" '+(saving?'disabled':'')+'>'+(saving?'Saving...':'Save roleplay')+'</button></div></div></div></div>'}
function player(){const t=turns[idx]||turns[0]||normTurn({});return '<div class="rp-app">'+top(false)+'<div class="rp-player"><aside class="rp-scene"><h2 style="font-family:Fredoka,sans-serif;color:#F97316;margin-top:0">'+h(scene.title)+'</h2><p><b>Scenario:</b><br>'+h(scene.scenario).replace(/\n/g,'<br>')+'</p><p style="margin-top:16px"><b>Roles:</b><br>'+h(scene.agentRole)+' / '+h(scene.studentRole)+'</p><p style="margin-top:16px"><b>Progress:</b> '+(idx+1)+' / '+turns.length+'</p>'+(window.RP_ALLOW_EDITOR?'<button class="rp-btn" style="margin-top:16px" data-action="back-editor">Back to editor</button>':'')+'</aside><main class="rp-stage"><div class="rp-chat"><div style="color:#9B8FCC;font-weight:900;text-transform:uppercase;font-size:12px;margin-bottom:10px">Turn '+(idx+1)+'</div><div class="rp-agent"><b>'+h(scene.agentRole)+':</b><br>'+h(t.agent||'Start the conversation.').replace(/\n/g,'<br>')+'</div>'+(t.hint?'<div class="rp-note"><b>Hint:</b> '+h(t.hint)+'</div>':'')+'<label class="rp-label">Your answer</label><textarea class="rp-textarea" data-answer="1" placeholder="Type your roleplay answer here...">'+h(answer)+'</textarea><div style="display:flex;gap:10px;justify-content:space-between;margin-top:14px;flex-wrap:wrap"><button class="rp-btn" data-action="prev" '+(idx===0?'disabled':'')+'>Previous</button><button class="rp-btn" data-action="show-ideal">Show ideal answer</button><button class="rp-btn rp-primary" data-action="next">'+(idx>=turns.length-1?'Finish':'Next')+'</button></div>'+(feedback?'<div class="rp-feedback">'+h(feedback).replace(/\n/g,'<br>')+'</div>':'')+'</div></main></div></div>'}
function done(){return '<div class="rp-app">'+top(false)+'<div class="rp-body"><div class="rp-wrap"><section class="rp-card"><div class="rp-card-body" style="text-align:center;padding:44px"><div style="font-size:54px">✅</div><h1 style="font-family:Fredoka,sans-serif;color:#F97316">Roleplay Complete</h1><p style="font-weight:900;color:#7F77DD;margin:10px 0 22px">Great work completing the activity.</p><button class="rp-btn rp-primary" data-action="restart">Try again</button></div></section></div></div></div>'}
function render(){if(!root)return;root.innerHTML=view==='editor'?editor():(view==='done'?done():player())}
root.addEventListener('input',e=>{const el=e.target;if(el.dataset.scene){scene[el.dataset.scene]=el.value;render();return}if(el.dataset.turn){const i=Number(el.dataset.turn);turns[i]=Object.assign({},turns[i],{[el.dataset.prop]:el.value});render();return}if(el.dataset.answer){answer=el.value}});
root.addEventListener('click',async e=>{const btn=e.target.closest('[data-action]');if(!btn)return;const a=btn.dataset.action;if(a==='add-turn'){turns.push(normTurn({}));render()}if(a==='remove-turn'){turns=turns.filter((_,i)=>i!==Number(btn.dataset.index));if(!turns.length)turns=normTurns([]);render()}if(a==='preview'){view='player';idx=0;answer='';feedback='';render()}if(a==='back-editor'){view='editor';render()}if(a==='prev'){idx=Math.max(0,idx-1);answer='';feedback='';render()}if(a==='next'){if(idx>=turns.length-1)view='done';else{idx++;answer='';feedback=''}render()}if(a==='restart'){view='player';idx=0;answer='';feedback='';render()}if(a==='show-ideal'){const t=turns[idx]||{};feedback='Ideal answer: '+(t.ideal||'No ideal answer configured.')+(t.criteria?'\nCriteria: '+t.criteria:'');render()}if(a==='save'){if(!window.RP_ACTIVITY_ID){status='No activity ID - cannot save.';render();return}saving=true;status='Saving...';render();try{const resp=await fetch('save.php',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({id:window.RP_ACTIVITY_ID,scene:scene,turns:turns})});const json=await resp.json().catch(()=>({}));if(!resp.ok||!json.ok)throw new Error(json.error||('HTTP '+resp.status));status='Saved successfully'}catch(err){status='Could not save: '+err.message}finally{saving=false;render()}}});
try{render()}catch(err){console.error('[roleplay/viewer] render error',err);root.innerHTML='<div class="rp-app"><div class="rp-body"><section class="rp-card"><div class="rp-card-body">Could not render Roleplay. Check browser console.</div></section></div></div>'}
})();
</script>
<?php
$content = ob_get_clean();
error_log('[roleplay/viewer] html length=' . strlen($content));
render_activity_viewer($viewerTitle, '🎭', $content);
