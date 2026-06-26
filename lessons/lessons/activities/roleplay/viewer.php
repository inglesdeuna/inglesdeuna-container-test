<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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
    'hint' => "Greet the waiter and say what you'd like to eat.",
    'ideal' => "Good evening! I'd like the pasta please.",
    'criteria' => 'Greeting, polite request, food item.',
], [
    'agent' => 'Would you like something to drink?',
    'hint' => 'Order a drink politely.',
    'ideal' => "Yes, I'd like a glass of water, please.",
    'criteria' => 'Polite drink order.',
], [
    'agent' => 'Will that be all for you tonight?',
    'hint' => 'Answer politely and finish the order.',
    'ideal' => 'Yes, that will be all. Thank you.',
    'criteria' => 'Clear closing response.',
]];

$blankScene = [
    'title' => '',
    'scenario' => '',
    'agentRole' => '',
    'studentRole' => '',
    'icon' => '🎭',
    'level' => '',
];
$blankTurns = [[
    'agent' => '',
    'hint' => '',
    'ideal' => '',
    'criteria' => '',
]];

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
                $savedScene = $baseScene;

                if (isset($parsed['scene']) && is_array($parsed['scene'])) {
                    $savedScene = array_merge($baseScene, $parsed['scene']);
                }

                if (isset($parsed['turns']) && is_array($parsed['turns'])) {
                    $loadedTurns = [];
                    foreach ($parsed['turns'] as $turn) {
                        if (!is_array($turn)) {
                            continue;
                        }
                        $loadedTurns[] = [
                            'agent' => (string)($turn['agent'] ?? $turn['teacherLine'] ?? ''),
                            'hint' => (string)($turn['hint'] ?? $turn['studentLine'] ?? ''),
                            'ideal' => (string)($turn['ideal'] ?? $turn['studentLine'] ?? ''),
                            'criteria' => (string)($turn['criteria'] ?? ''),
                        ];
                    }
                    if ($loadedTurns) {
                        $savedTurns = $loadedTurns;
                    } elseif ($allowEditor) {
                        $savedTurns = $blankTurns;
                    }
                }
            }
        }
    }
} catch (Throwable $e) {
    error_log('[roleplay/viewer] load error: ' . $e->getMessage());
}

$viewerTitle = trim((string)($savedScene['title'] ?? '')) !== '' ? (string)$savedScene['title'] : 'Roleplay';
error_log('[roleplay/viewer] id=' . $activityId . ' mode=' . $mode . ' source=' . $source . ' editor=' . ($allowEditor ? 'yes' : 'no') . ' has_saved_payload=' . ($hasSavedPayload ? 'yes' : 'no') . ' turns=' . count($savedTurns));

ob_start();
?>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@400;600&family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<style>
#roleplay-root{height:100%;min-height:0;background:#F8F7FF;font-family:Nunito,system-ui,sans-serif;color:#2F2763}.rp-app{height:100%;min-height:0;display:flex;flex-direction:column;background:#F8F7FF}.rp-top{flex:0 0 auto;background:#fff;border-bottom:1px solid #F0EEF8}.rp-title{height:58px;display:grid;place-items:center;font-family:Fredoka,sans-serif;font-size:26px;font-weight:600;letter-spacing:.03em;color:#F97316}.rp-progress{height:8px;background:#EEEDFE;overflow:hidden}.rp-progress-fill{height:100%;background:linear-gradient(90deg,#F97316 0%,#F97316 55%,#7F77DD 100%);transition:width .25s ease}.rp-sub{height:40px;display:grid;place-items:center;background:#FFF0E6;border-bottom:1px solid #FCDDBF;color:#C2580A;font-weight:900;letter-spacing:.03em}.rp-scroll{flex:1;min-height:0;overflow:auto;padding:18px}.rp-wrap{max-width:930px;margin:0 auto}.rp-card{background:#fff;border:1.5px solid #EDE9FA;border-radius:22px;margin:0 0 18px;box-shadow:0 4px 16px rgba(127,119,221,.07);overflow:hidden}.rp-card.locked{opacity:.45}.rp-card-head{display:flex;align-items:center;gap:12px;padding:18px 22px 10px}.rp-turn-label{font-weight:900;color:#9B8FCC;text-transform:uppercase;letter-spacing:.14em}.rp-turn-label.active{color:#F97316}.rp-avatar{width:44px;height:44px;border-radius:50%;display:grid;place-items:center;background:#FFF0E6;font-size:24px;flex:0 0 auto}.rp-block{margin:0 22px 12px;border-left:5px solid #7F77DD;background:#F1EEFF;border-radius:0 12px 12px 0;padding:12px 16px}.rp-mini{font-size:12px;font-weight:900;color:#7F77DD;text-transform:uppercase;letter-spacing:.08em;margin-bottom:5px}.rp-bubble{font-weight:800;line-height:1.5;color:#2F2763}.rp-said{margin:0 22px 10px;border-left:5px solid #7F77DD;background:#F1EEFF;border-radius:0 12px 12px 0;padding:10px 16px}.rp-model{margin:0 22px 10px;border-left:5px solid #1D9E75;background:#E1F5EE;border-radius:0 12px 12px 0;padding:10px 16px}.rp-improve{margin:0 22px 10px;border-left:5px solid #F97316;background:#FFF0E6;border-radius:0 12px 12px 0;padding:10px 16px}.rp-score-row{display:flex;gap:8px;margin:0 22px 18px 76px}.rp-chip{min-width:80px;text-align:center;border:1.5px solid #EDE9FA;border-radius:12px;background:#fff;padding:8px 10px}.rp-chip b{display:block;color:#F97316;font-size:20px}.rp-chip span{display:block;color:#9B8FCC;font-size:12px;font-weight:800}.rp-turn-box{margin:0 22px 14px 76px;border:2px solid #7F77DD;border-radius:18px;padding:14px 18px;background:#fff}.rp-turn-box.disabled{border-color:#EDE9FA;background:#FBFAFF}.rp-say-row{display:flex;align-items:center;gap:16px}.rp-mic{border:1.5px solid #BDB8D8;background:#fff;border-radius:13px;min-width:180px;padding:12px 18px;font-weight:900;font-size:20px;color:#111;cursor:pointer}.rp-mic.listening{background:#7F77DD;color:#fff;border-color:#7F77DD}.rp-hint{color:#9B8FCC;font-style:italic;font-weight:800}.rp-hidden-input{margin-top:12px;width:100%;border:1.5px solid #DCD7FF;border-radius:12px;padding:10px 12px;font:800 15px Nunito,sans-serif}.rp-actions{margin:0 22px 20px 76px;display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap}.rp-btn{border:1.5px solid #DCD7FF;border-radius:13px;background:#fff;color:#3D3560;padding:12px 24px;font-weight:900;font-size:16px;cursor:pointer;font-family:Nunito,sans-serif}.rp-primary{background:#F97316;color:#fff;border-color:#F97316}.rp-editor-body{flex:1;min-height:0;overflow:auto;padding:22px}.rp-editor-wrap{max-width:1020px;margin:0 auto}.rp-edit-card{background:#fff;border:1.5px solid #EDE9FA;border-radius:22px;margin-bottom:18px;overflow:hidden}.rp-edit-head{display:flex;align-items:center;gap:12px;border-bottom:1px solid #F0EEF8;padding:16px 22px}.rp-edit-title{font-family:Fredoka,sans-serif;color:#F97316;font-size:22px}.rp-edit-content{padding:18px 22px}.rp-grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px}.rp-grid3{display:grid;grid-template-columns:1fr 1fr 140px;gap:14px}.rp-label{display:block;margin:0 0 6px;color:#9B8FCC;font-weight:900;font-size:12px;text-transform:uppercase;letter-spacing:.08em}.rp-input,.rp-textarea{width:100%;border:1.5px solid #DCD7FF;border-radius:11px;background:#FBFAFF;color:#221A3F;font:800 16px Nunito,sans-serif;padding:10px 14px;outline:none;box-sizing:border-box}.rp-textarea{min-height:90px;resize:vertical;line-height:1.5}.rp-turn-edit{border:1.5px solid #EDE9FA;border-radius:18px;padding:16px;margin-bottom:14px}.rp-remove{float:right;border:1.5px solid #D85A30;color:#D85A30;background:#fff;border-radius:9px;padding:5px 10px;font-weight:900;cursor:pointer}.rp-savebar{position:sticky;bottom:0;display:grid;grid-template-columns:auto 1fr auto;gap:14px;align-items:center;background:#F8F7FF;padding:18px 0 0}.rp-status{text-align:center;color:#7F77DD;font-weight:900}.rp-complete{display:grid;place-items:center;min-height:100%;padding:28px}.rp-complete-card{text-align:center;background:#fff;border:1.5px solid #EDE9FA;border-radius:24px;padding:42px;max-width:520px;box-shadow:0 8px 26px rgba(127,119,221,.10)}@media(max-width:760px){.rp-scroll{padding:12px}.rp-score-row,.rp-turn-box,.rp-actions{margin-left:22px}.rp-grid2,.rp-grid3,.rp-savebar{grid-template-columns:1fr}.rp-say-row{flex-direction:column;align-items:flex-start}.rp-mic{width:100%}}
</style>
<div id="roleplay-root"></div>
<script>
window.RP_ACTIVITY_ID = <?= json_encode($activityId, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
window.RP_RETURN_TO = <?= json_encode($returnTo, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
window.RP_SAVED_SCENE = <?= json_encode($savedScene, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
window.RP_SAVED_TURNS = <?= json_encode($savedTurns, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
window.RP_ALLOW_EDITOR = <?= json_encode($allowEditor) ?>;
window.RP_HAS_SAVED_PAYLOAD = <?= json_encode($hasSavedPayload) ?>;

(function () {
  const root = document.getElementById('roleplay-root');
  let scene = normScene(window.RP_SAVED_SCENE || {}, window.RP_ALLOW_EDITOR && !window.RP_HAS_SAVED_PAYLOAD);
  let turns = normTurns(window.RP_SAVED_TURNS || [], window.RP_ALLOW_EDITOR && !window.RP_HAS_SAVED_PAYLOAD);
  let view = window.RP_ALLOW_EDITOR ? 'editor' : 'player';
  let completed = 0;
  let answers = [];
  let scores = [];
  let shownAnswers = [];
  let activeInput = '';
  let status = '';
  let saving = false;

  function h(v) { return String(v ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch])); }
  function uid() { return 'rp_' + Math.random().toString(36).slice(2, 9) + '_' + Date.now(); }
  function normScene(s, blank) {
    s = s || {};
    if (blank) {
      return {title:String(s.title || ''),scenario:String(s.scenario || s.description || ''),agentRole:String(s.agentRole || ''),studentRole:String(s.studentRole || ''),icon:String(s.icon || '🎭'),level:String(s.level || '')};
    }
    return {title:String(s.title || 'Roleplay'),scenario:String(s.scenario || s.description || 'At the Restaurant'),agentRole:String(s.agentRole || 'Waiter'),studentRole:String(s.studentRole || 'Customer'),icon:String(s.icon || '🎭'),level:String(s.level || 'B1')};
  }
  function normTurn(t) { t = t || {}; return {id:String(t.id || uid()),agent:String(t.agent || t.teacherLine || ''),hint:String(t.hint || t.studentLine || ''),ideal:String(t.ideal || t.studentLine || ''),criteria:String(t.criteria || '')}; }
  function normTurns(a, blank) {
    const out = (Array.isArray(a) ? a : []).map(normTurn);
    if (out.length) return out;
    if (blank) return [normTurn({})];
    return [normTurn({agent:'Good evening! Are you ready to order?',hint:"Greet the waiter and say what you'd like to eat.",ideal:"Good evening! I'd like the pasta please.",criteria:'Polite greeting and food order.'}),normTurn({agent:'Would you like something to drink?',hint:'Order a drink politely.',ideal:"Yes, I'd like a glass of water, please.",criteria:'Polite drink order.'})];
  }
  function header() {
    const pct = turns.length ? Math.round((completed / turns.length) * 100) : 0;
    const sub = [scene.scenario || scene.title || 'Roleplay', [scene.agentRole, scene.studentRole].filter(Boolean).join(' / ')].filter(Boolean).join(' · ');
    return '<div class="rp-top"><div class="rp-title">Roleplay</div><div class="rp-progress"><div class="rp-progress-fill" style="width:' + pct + '%"></div></div><div class="rp-sub">' + h(sub) + '</div></div>';
  }
  function cleanWords(s) { return String(s || '').toLowerCase().replace(/[^a-z0-9\s]/g, ' ').split(/\s+/).filter(w => w.length > 1); }
  function scoreTurn(ans, ideal) {
    const aw = cleanWords(ans), iw = cleanWords(ideal), aset = new Set(aw); let matched = 0;
    iw.forEach(w => { if (aset.has(w)) matched++; });
    const accuracy = iw.length ? Math.round((matched / iw.length) * 10) : 7;
    const understood = ans.trim().length >= 8 && aw.length >= 2;
    const fluency = Math.max(1, Math.min(10, understood ? Math.round(6 + Math.min(4, aw.length / 4)) : 3));
    const vocab = Math.max(1, Math.min(10, Math.round((new Set(aw).size / Math.max(3, iw.length)) * 10)));
    const overall = Math.round((accuracy + fluency + vocab) / 3);
    let improve = 'Model answer: ' + (ideal || 'No model answer configured.');
    if (!understood) improve = 'Try giving a complete answer. ' + improve;
    else if (accuracy < 6) improve = 'Good try. Use more key words from the model sentence. ' + improve;
    else improve = 'Great! Your answer is understandable. ' + improve;
    return {accuracy, fluency, vocab, overall, matched, total: iw.length, improve};
  }
  function player() { return '<div class="rp-app">' + header() + '<div class="rp-scroll"><div class="rp-wrap">' + turns.map((t, i) => turnCard(t, i)).join('') + '</div></div></div>'; }
  function turnCard(t, i) {
    const isDone = i < completed, active = i === completed, locked = i > completed, ans = answers[i] || '', sc = scores[i] || null, show = shownAnswers[i];
    return '<section class="rp-card ' + (locked ? 'locked' : '') + '"><div class="rp-card-head"><div class="rp-avatar">👨‍🍳</div><div class="rp-turn-label ' + (active ? 'active' : '') + '">TURN ' + (i + 1) + ' · ' + (isDone ? '✓ completed' : active ? 'active' : '🔒 locked') + '</div></div><div class="rp-block"><div class="rp-mini">' + h(scene.agentRole || 'Agent') + '</div><div class="rp-bubble">' + h(t.agent || '...') + '</div></div>' + (isDone ? '<div class="rp-said"><div class="rp-mini">You said</div><div>' + h(ans) + '</div></div><div class="rp-model"><div class="rp-mini" style="color:#1D9E75">Model answer</div><div>' + h(t.ideal || 'No model answer configured.') + '</div></div><div class="rp-improve"><div class="rp-mini" style="color:#F97316">Feedback</div><div>' + h(sc ? sc.improve : 'Good work.') + '</div></div><div class="rp-score-row"><div class="rp-chip"><b>' + h(sc ? sc.fluency : 0) + '</b><span>Fluency</span></div><div class="rp-chip"><b>' + h(sc ? sc.accuracy : 0) + '</b><span>Accuracy</span></div><div class="rp-chip"><b>' + h(sc ? sc.vocab : 0) + '</b><span>Vocab</span></div></div>' : '') + (active ? '<div class="rp-turn-box"><div class="rp-say-row"><button type="button" class="rp-mic" data-action="mic" data-index="' + i + '">🎙 Now say it</button><span class="rp-hint">Hint: ' + h(t.hint || 'Answer naturally') + '</span></div><textarea class="rp-hidden-input" data-answer="1" placeholder="Speech will appear here. You can also type...">' + h(activeInput) + '</textarea></div>' + (show ? '<div class="rp-model"><div class="rp-mini" style="color:#1D9E75">Model answer</div><div>' + h(t.ideal || 'No model answer configured.') + '</div></div>' : '') + '<div class="rp-actions"><button type="button" class="rp-btn" data-action="show-answer">Show answer</button><button type="button" class="rp-btn rp-primary" data-action="next">' + (i >= turns.length - 1 ? 'Finish' : 'Next') + '</button></div>' : '') + (locked ? '<div class="rp-turn-box disabled"><span class="rp-hint">Complete the previous turn to unlock this one.</span></div>' : '') + '</section>';
  }
  function completedPage() {
    const avg = scores.length ? Math.round(scores.reduce((a, s) => a + (s.overall || 0), 0) / scores.length * 10) : 0;
    const correct = scores.filter(s => (s.overall || 0) >= 7).length;
    return '<div class="rp-app">' + header() + '<div class="rp-complete"><div class="rp-complete-card"><div style="font-size:58px">✅</div><h1 style="font-family:Fredoka,sans-serif;color:#F97316;margin:8px 0">Roleplay Complete!</h1><p style="font-weight:900;color:#7F77DD">Score: ' + avg + '%</p><p style="margin:12px 0 22px;color:#3D3560;font-weight:800">' + correct + ' of ' + turns.length + ' turns completed successfully.</p><button type="button" class="rp-btn rp-primary" data-action="restart">Try again</button></div></div></div>';
  }
  function editor() {
    return '<div class="rp-app"><div class="rp-top"><div class="rp-title">Roleplay Editor</div></div><div class="rp-editor-body"><div class="rp-editor-wrap"><section class="rp-edit-card"><div class="rp-edit-head"><div class="rp-edit-title">Roleplay settings</div></div><div class="rp-edit-content"><div class="rp-grid3"><div><label class="rp-label">Activity title</label><input class="rp-input" data-scene="title" value="' + h(scene.title) + '"></div><div><label class="rp-label">Scenario</label><input class="rp-input" data-scene="scenario" value="' + h(scene.scenario) + '"></div><div><label class="rp-label">Level</label><input class="rp-input" data-scene="level" value="' + h(scene.level) + '"></div></div><div class="rp-grid2" style="margin-top:14px"><div><label class="rp-label">Agent role</label><input class="rp-input" data-scene="agentRole" value="' + h(scene.agentRole) + '"></div><div><label class="rp-label">Student role</label><input class="rp-input" data-scene="studentRole" value="' + h(scene.studentRole) + '"></div></div></div></section><section class="rp-edit-card"><div class="rp-edit-head"><div class="rp-edit-title">Conversation turns</div></div><div class="rp-edit-content">' + turns.map((t, i) => '<div class="rp-turn-edit"><button type="button" class="rp-remove" data-action="remove-turn" data-index="' + i + '">Remove</button><div class="rp-turn-label active">Turn ' + (i + 1) + '</div><label class="rp-label">Agent line</label><textarea class="rp-textarea" data-turn="' + i + '" data-prop="agent">' + h(t.agent) + '</textarea><div class="rp-grid2" style="margin-top:12px"><div><label class="rp-label">Student hint</label><textarea class="rp-textarea" data-turn="' + i + '" data-prop="hint">' + h(t.hint) + '</textarea></div><div><label class="rp-label">Model sentence / ideal answer</label><textarea class="rp-textarea" data-turn="' + i + '" data-prop="ideal">' + h(t.ideal) + '</textarea></div></div><label class="rp-label" style="margin-top:12px">Criteria</label><input class="rp-input" data-turn="' + i + '" data-prop="criteria" value="' + h(t.criteria) + '"></div>').join('') + '<button type="button" class="rp-btn" data-action="add-turn">+ Add turn</button></div></section><div class="rp-savebar"><button type="button" class="rp-btn" data-action="preview">Preview as student</button><div class="rp-status">' + h(status) + '</div><button type="button" class="rp-btn rp-primary" data-action="save" ' + (saving ? 'disabled' : '') + '>' + (saving ? 'Saving...' : 'Save roleplay') + '</button></div></div></div></div>';
  }
  function render() {
    if (!root) return;
    const sc = root.querySelector('.rp-scroll,.rp-editor-body');
    const top = sc ? sc.scrollTop : 0;
    root.innerHTML = view === 'editor' ? editor() : (view === 'complete' ? completedPage() : player());
    const sc2 = root.querySelector('.rp-scroll,.rp-editor-body');
    if (sc2) sc2.scrollTop = top;
  }
  function nextTurn() {
    const i = completed;
    if (!activeInput.trim() && !shownAnswers[i]) { alert('Please say/type your answer or tap Show answer first.'); return; }
    answers[i] = activeInput.trim() || '(Used Show answer)';
    scores[i] = scoreTurn(activeInput, turns[i] ? turns[i].ideal : '');
    completed = Math.min(turns.length, completed + 1);
    activeInput = '';
    if (completed >= turns.length) view = 'complete';
    render();
  }
  function startMic(i) {
    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SR) { alert('Speech recognition is not supported in this browser. You can type your answer.'); return; }
    const rec = new SR(); rec.lang = 'en-US'; rec.continuous = false; rec.interimResults = false;
    const btn = root.querySelector('[data-action="mic"][data-index="' + i + '"]');
    const box = root.querySelector('[data-answer="1"]');
    if (btn) { btn.classList.add('listening'); btn.textContent = '🎙 Listening...'; }
    rec.onresult = e => { activeInput = e.results[0][0].transcript; if (box) box.value = activeInput; };
    rec.onerror = rec.onend = () => { if (btn) { btn.classList.remove('listening'); btn.textContent = '🎙 Now say it'; } };
    rec.start();
  }
  root.addEventListener('input', e => {
    const el = e.target;
    if (el.dataset.scene) { scene[el.dataset.scene] = el.value; return; }
    if (el.dataset.turn) { const i = Number(el.dataset.turn); turns[i] = Object.assign({}, turns[i] || normTurn({}), {[el.dataset.prop]: el.value}); return; }
    if (el.dataset.answer) activeInput = el.value;
  });
  root.addEventListener('click', async e => {
    const btn = e.target.closest('[data-action]'); if (!btn) return;
    e.preventDefault();
    const a = btn.dataset.action;
    if (a === 'mic') startMic(Number(btn.dataset.index));
    if (a === 'show-answer') { shownAnswers[completed] = true; render(); }
    if (a === 'next') nextTurn();
    if (a === 'restart') { view = 'player'; completed = 0; answers = []; scores = []; shownAnswers = []; activeInput = ''; render(); }
    if (a === 'preview') { view = 'player'; completed = 0; activeInput = ''; answers = []; scores = []; shownAnswers = []; render(); }
    if (a === 'add-turn') { turns.push({id: uid(), agent: '', hint: '', ideal: '', criteria: ''}); render(); }
    if (a === 'remove-turn') { turns = turns.filter((_, i) => i !== Number(btn.dataset.index)); if (!turns.length) turns = [normTurn({})]; render(); }
    if (a === 'save') {
      if (!window.RP_ACTIVITY_ID) { status = 'No activity ID - cannot save.'; render(); return; }
      saving = true; status = 'Saving...'; render();
      try {
        const resp = await fetch('save.php', {method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({id:window.RP_ACTIVITY_ID,scene:scene,turns:turns})});
        const json = await resp.json().catch(() => ({}));
        if (!resp.ok || !json.ok) throw new Error(json.error || ('HTTP ' + resp.status));
        window.RP_HAS_SAVED_PAYLOAD = true;
        status = 'Saved successfully';
      } catch (err) {
        status = 'Could not save: ' + err.message;
      } finally {
        saving = false;
        render();
      }
    }
  });
  try { render(); } catch (err) { console.error('[roleplay] render error', err); root.innerHTML = '<div style="padding:20px">Roleplay could not render. Check console.</div>'; }
})();
</script>
<?php
$content = ob_get_clean();
error_log('[roleplay/viewer] html length=' . strlen($content));
render_activity_viewer($viewerTitle, '🎭', $content);
