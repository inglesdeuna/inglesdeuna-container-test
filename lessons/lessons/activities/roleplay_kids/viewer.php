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
$mode = isset($_GET['mode']) ? trim((string) $_GET['mode']) : '';
$returnTo = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';
$source = isset($_GET['source']) ? trim((string) $_GET['source']) : '';

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
    'level' => 'A1',
    'teacherAvatarId' => 'TEACHER',
    'studentAvatarId' => 'ANGIE',
    'teacherVoiceId' => 'nzFihrBIvB34imQBuxub',
];

$defaultTurns = [
    [
        'agent' => 'Good evening! Are you ready to order?',
        'hint' => 'Say hello and say what food you want.',
        'ideal' => "Good evening! I'd like the pasta, please.",
        'criteria' => 'Greeting and polite food order.',
    ],
    [
        'agent' => 'Would you like something to drink?',
        'hint' => 'Ask for a drink politely.',
        'ideal' => "Yes, I'd like water, please.",
        'criteria' => 'Polite drink order.',
    ],
    [
        'agent' => 'Will that be all for you tonight?',
        'hint' => 'Finish your order politely.',
        'ideal' => 'Yes, that is all. Thank you.',
        'criteria' => 'Clear polite ending.',
    ],
];

$savedScene = $defaultScene;
$savedTurns = $defaultTurns;

try {
    if ($activityId !== '') {
        $stmt = $pdo->prepare('SELECT data FROM activities WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && !empty($row['data'])) {
            $parsed = json_decode((string) $row['data'], true);
            if (is_array($parsed)) {
                if (isset($parsed['scene']) && is_array($parsed['scene'])) {
                    $savedScene = array_merge($defaultScene, $parsed['scene']);
                    if (empty($savedScene['teacherVoiceId'])) {
                        $savedScene['teacherVoiceId'] = 'nzFihrBIvB34imQBuxub';
                    }
                }
                if (isset($parsed['turns']) && is_array($parsed['turns']) && count($parsed['turns']) > 0) {
                    $savedTurns = [];
                    foreach ($parsed['turns'] as $turn) {
                        if (!is_array($turn)) {
                            continue;
                        }
                        $savedTurns[] = [
                            'agent' => (string) ($turn['agent'] ?? $turn['teacherLine'] ?? ''),
                            'hint' => (string) ($turn['hint'] ?? $turn['studentLine'] ?? ''),
                            'ideal' => (string) ($turn['ideal'] ?? $turn['studentLine'] ?? ''),
                            'criteria' => (string) ($turn['criteria'] ?? ''),
                        ];
                    }
                    if (!$savedTurns) {
                        $savedTurns = $defaultTurns;
                    }
                }
            }
        }
    }
} catch (Throwable $e) {
    error_log('[roleplay_kids/viewer] load error: ' . $e->getMessage());
}

$viewerTitle = trim((string) ($savedScene['title'] ?? '')) !== '' ? (string) $savedScene['title'] : 'Roleplay Kids';

ob_start();
?>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@400;600;700&family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<style>
#roleplay-kids-root{height:100%;min-height:0;background:#fff8f2;font-family:Nunito,system-ui,sans-serif;color:#2f2763}.rk-app{height:100%;min-height:0;display:flex;flex-direction:column;background:linear-gradient(180deg,#fff8f2,#f8f7ff)}.rk-top{background:#fff;border-bottom:1px solid #f0eef8}.rk-title{height:58px;display:grid;place-items:center;font-family:Fredoka,sans-serif;font-size:27px;font-weight:700;color:#f97316}.rk-progress{height:10px;background:#eeedfe;overflow:hidden}.rk-progress-fill{height:100%;background:linear-gradient(90deg,#f97316,#fdba74,#7f77dd);transition:width .25s}.rk-sub{height:42px;display:grid;place-items:center;background:#fff0e6;border-bottom:1px solid #fcddbf;color:#c2580a;font-weight:900}.rk-scroll,.rk-editor-body{flex:1;min-height:0;overflow:auto;padding:18px}.rk-wrap,.rk-editor-wrap{max-width:960px;margin:0 auto}.rk-card,.rk-edit-card,.rk-picker{background:#fff;border:2px solid #ede9fa;border-radius:26px;margin:0 0 18px;box-shadow:0 7px 20px rgba(127,119,221,.08);overflow:hidden}.rk-card.locked{opacity:.45}.rk-head{display:flex;align-items:center;gap:12px;padding:18px 22px 10px}.rk-avatar{width:58px;height:58px;border-radius:50%;display:grid;place-items:center;background:#fff;border:3px solid #ffe8b8;box-shadow:0 0 14px rgba(249,115,22,.25);overflow:hidden;flex:0 0 auto}.rk-avatar img{width:100%;height:100%;object-fit:cover}.rk-avatar-fallback{font-size:30px}.rk-turn-label{font-weight:900;color:#9b8fcc;text-transform:uppercase;letter-spacing:.14em}.rk-turn-label.active{color:#f97316}.rk-block,.rk-said,.rk-model,.rk-improve{margin:0 22px 12px 92px;border-left:6px solid #7f77dd;background:#f1eeff;border-radius:0 16px 16px 0;padding:12px 16px}.rk-model{border-left-color:#1d9e75;background:#e1f5ee}.rk-improve{border-left-color:#f97316;background:#fff0e6}.rk-mini{font-size:12px;font-weight:900;color:#7f77dd;text-transform:uppercase;letter-spacing:.08em;margin-bottom:5px}.rk-bubble{font-weight:900;line-height:1.5}.rk-listen{border:2px solid #f97316;background:#fff0e6;color:#c2580a;border-radius:999px;padding:5px 13px;font:900 12px Nunito,sans-serif;cursor:pointer;margin-left:10px;vertical-align:middle}.rk-listen.speaking{background:#f97316;color:#fff}.rk-score-row{display:flex;gap:8px;margin:0 22px 18px 92px}.rk-chip{min-width:80px;text-align:center;border:2px solid #ede9fa;border-radius:16px;background:#fff;padding:8px 10px}.rk-chip b{display:block;color:#f97316;font-size:22px}.rk-chip span{display:block;color:#9b8fcc;font-size:12px;font-weight:900}.rk-turn-box{margin:0 22px 14px 92px;border:3px solid #7f77dd;border-radius:22px;padding:14px 18px;background:#fff}.rk-turn-box.disabled{border-color:#ede9fa;background:#fbfaff}.rk-say-row{display:flex;align-items:center;gap:16px}.rk-mic{border:2px solid #bdb8d8;background:#fff;border-radius:18px;min-width:190px;padding:14px 20px;font-weight:900;font-size:21px;color:#111;cursor:pointer}.rk-mic.listening{background:#7f77dd;color:#fff}.rk-hint{color:#9b8fcc;font-style:italic;font-weight:900}.rk-hidden-input{margin-top:12px;width:100%;border:2px solid #dcd7ff;border-radius:14px;padding:11px 13px;font:900 15px Nunito,sans-serif}.rk-actions{margin:0 22px 20px 92px;display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap}.rk-btn{border:2px solid #dcd7ff;border-radius:16px;background:#fff;color:#3d3560;padding:12px 24px;font-weight:900;font-size:16px;cursor:pointer;font-family:Nunito,sans-serif}.rk-primary{background:#f97316;color:#fff;border-color:#f97316}.rk-picker{padding:18px}.rk-picker-title,.rk-edit-title{font-family:Fredoka,sans-serif;color:#f97316;font-size:22px;margin-bottom:12px}.rk-picker-title{text-align:center}.rk-avatar-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(88px,1fr));gap:12px}.rk-avatar-choice{border:2px solid #ede9fa;background:#fff;border-radius:18px;padding:8px;cursor:pointer;text-align:center;font-weight:900;color:#7f77dd}.rk-avatar-choice.active{border-color:#f97316;background:#fff0e6;color:#f97316}.rk-avatar-choice .rk-avatar{width:68px;height:68px;margin:0 auto 6px}.rk-edit-head{border-bottom:1px solid #f0eef8;padding:16px 22px}.rk-edit-content{padding:18px 22px}.rk-grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px}.rk-grid3{display:grid;grid-template-columns:1fr 1fr 140px;gap:14px}.rk-label{display:block;margin:0 0 6px;color:#9b8fcc;font-weight:900;font-size:12px;text-transform:uppercase;letter-spacing:.08em}.rk-input,.rk-textarea{width:100%;border:2px solid #dcd7ff;border-radius:13px;background:#fbfaff;color:#221a3f;font:900 16px Nunito,sans-serif;padding:10px 14px;outline:none;box-sizing:border-box}.rk-textarea{min-height:90px;resize:vertical;line-height:1.5}.rk-turn-edit{border:2px solid #ede9fa;border-radius:20px;padding:16px;margin-bottom:14px}.rk-remove{float:right;border:2px solid #d85a30;color:#d85a30;background:#fff;border-radius:12px;padding:5px 10px;font-weight:900;cursor:pointer}.rk-savebar{position:sticky;bottom:0;display:grid;grid-template-columns:auto 1fr auto;gap:14px;align-items:center;background:#f8f7ff;padding:18px 0 0}.rk-status{text-align:center;color:#7f77dd;font-weight:900}.rk-complete{display:grid;place-items:center;min-height:100%;padding:28px}.rk-complete-card{text-align:center;background:#fff;border:2px solid #ede9fa;border-radius:28px;padding:42px;max-width:540px;box-shadow:0 8px 26px rgba(127,119,221,.10)}@media(max-width:760px){.rk-block,.rk-said,.rk-model,.rk-improve,.rk-score-row,.rk-turn-box,.rk-actions{margin-left:22px}.rk-grid2,.rk-grid3,.rk-savebar{grid-template-columns:1fr}.rk-say-row{flex-direction:column;align-items:flex-start}.rk-mic{width:100%}}
</style>
<div id="roleplay-kids-root"></div>
<script>
window.RK_ACTIVITY_ID = <?= json_encode($activityId, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
window.RK_RETURN_TO = <?= json_encode($returnTo, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
window.RK_SAVED_SCENE = <?= json_encode($savedScene, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
window.RK_SAVED_TURNS = <?= json_encode($savedTurns, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
window.RK_ALLOW_EDITOR = <?= json_encode($allowEditor) ?>;

(function () {
  const root = document.getElementById('roleplay-kids-root');
  const BASE = '../hangman/assets/';
  const AV = {
    ANGIE: ['ANGIE(2).png','ANGIE.png','Angie.png'],
    ANY: ['ANY(1).png','ANY.png','Any.png'],
    BENNY: ['BENNY(1).png','BENNY.png','Benny.png'],
    JAYJAY: ['JAY JAY.png','JAYJAY.png','JAY_JAY.png'],
    JESUS: ['JESUS(2).png','JESUS.png','Jesus.png'],
    JOHN: ['JOHN(2).png','JOHN.png','John.png'],
    LEEANN: ['LeeAnn(3).png','LEEANN.png','LeeAnn.png'],
    MARYJAY: ['MARY JAY (5).png','MARY JAY(5).png','MARY JAY.png','MARYJAY.png','MARY_JAY.png','Mary Jay(5).png','Mary Jay.png','mary jay.png'],
    NELLA: ['NELLA(6).png','NELLA.png','Nella.png'],
    TEACHER: ['TEACHER(3).png','TEACHER.png','Teacher.png'],
    VICTOR: ['VICTOR(1).png','VICTOR.png','Victor.png'],
    VIOLET: ['VIOLET(1).png','VIOLET.png','Violet.png']
  };
  const LABELS = Object.keys(AV);

  let scene = normScene(window.RK_SAVED_SCENE || {});
  let turns = normTurns(window.RK_SAVED_TURNS || []);
  let view = window.RK_ALLOW_EDITOR ? 'editor' : 'avatar';
  let completed = 0;
  let answers = [];
  let scores = [];
  let shown = [];
  let activeInput = '';
  let status = '';
  let saving = false;

  function h(v) {
    return String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }
  function uid() { return 'rk_' + Math.random().toString(36).slice(2, 9); }
  function fileUrl(f) { return BASE + f.split('/').map(p => p.indexOf('%') >= 0 ? p : encodeURIComponent(p)).join('/'); }
  function img(id) {
    const files = AV[id] || AV.ANGIE;
    const urls = files.map(fileUrl);
    return '<img src="' + h(urls[0]) + '" data-srcs="' + h(urls.join('|')) + '" data-i="0" alt="' + h(id) + '" onerror="window.rkAvatarFallback(this)">';
  }
  window.rkAvatarFallback = function (el) {
    let list = (el.dataset.srcs || '').split('|');
    let i = Number(el.dataset.i || 0) + 1;
    if (i < list.length) {
      el.dataset.i = i;
      el.src = list[i];
    } else {
      el.style.display = 'none';
      if (!el.parentNode.querySelector('.rk-avatar-fallback')) {
        el.parentNode.insertAdjacentHTML('beforeend', '<span class="rk-avatar-fallback">🙂</span>');
      }
    }
  };

  function normScene(s) {
    s = s || {};
    return {
      title: String(s.title || 'Roleplay Kids'),
      scenario: String(s.scenario || s.description || 'At the Restaurant'),
      agentRole: String(s.agentRole || 'Waiter'),
      studentRole: String(s.studentRole || 'Customer'),
      level: String(s.level || 'A1'),
      teacherAvatarId: String(s.teacherAvatarId || 'TEACHER'),
      studentAvatarId: String(s.studentAvatarId || 'ANGIE'),
      teacherVoiceId: String(s.teacherVoiceId || 'nzFihrBIvB34imQBuxub')
    };
  }
  function normTurn(t) {
    t = t || {};
    return {
      id: String(t.id || uid()),
      agent: String(t.agent || t.teacherLine || ''),
      hint: String(t.hint || t.studentLine || ''),
      ideal: String(t.ideal || t.studentLine || ''),
      criteria: String(t.criteria || '')
    };
  }
  function normTurns(a) {
    const out = (Array.isArray(a) ? a : []).map(normTurn);
    return out.length ? out : [normTurn({agent:'Good evening! Are you ready to order?', hint:'Say hello and say what food you want.', ideal:"Good evening! I'd like the pasta, please.", criteria:'Greeting and polite food order.'})];
  }
  function words(s) {
    return String(s || '').toLowerCase().replace(/[^a-z0-9\s]/g, ' ').split(/\s+/).filter(w => w.length > 1);
  }
  function score(a, m) {
    let aw = words(a), mw = words(m), set = new Set(aw), match = 0;
    mw.forEach(w => { if (set.has(w)) match++; });
    let acc = mw.length ? Math.round(match / mw.length * 10) : 7;
    let flu = a.trim().length >= 5 && aw.length >= 2 ? Math.min(10, 6 + Math.round(aw.length / 4)) : 3;
    let voc = Math.max(1, Math.min(10, Math.round((new Set(aw).size / Math.max(3, mw.length)) * 10)));
    let overall = Math.round((acc + flu + voc) / 3);
    return {accuracy: acc, fluency: flu, vocab: voc, overall, improve: (acc >= 6 ? 'Great speaking! ' : 'Good try! Use more words from the model sentence. ') + 'Model answer: ' + (m || 'No model answer configured.')};
  }
  function speakAgent(i) {
    const text = turns[i] && turns[i].agent ? turns[i].agent : '';
    if (!text.trim()) return;
    if (!('speechSynthesis' in window)) {
      alert('Text to speech is not supported in this browser.');
      return;
    }
    speechSynthesis.cancel();
    const btn = root.querySelector('[data-action="listen-agent"][data-index="' + i + '"]');
    const u = new SpeechSynthesisUtterance(text);
    u.lang = 'en-US';
    u.rate = 0.88;
    u.pitch = 1.05;
    if (btn) {
      btn.classList.add('speaking');
      btn.textContent = '🔊 Playing...';
    }
    u.onend = u.onerror = function () {
      if (btn) {
        btn.classList.remove('speaking');
        btn.textContent = '🔊 Listen';
      }
    };
    speechSynthesis.speak(u);
  }

  function header() {
    let pct = turns.length ? Math.round(completed / turns.length * 100) : 0;
    return '<div class="rk-top"><div class="rk-title">Roleplay Kids</div><div class="rk-progress"><div class="rk-progress-fill" style="width:' + pct + '%"></div></div><div class="rk-sub">' + h(scene.scenario) + ' · ' + h(scene.agentRole) + ' / ' + h(scene.studentRole) + '</div></div>';
  }
  function avatarPick() {
    return '<div class="rk-app">' + header() + '<div class="rk-scroll"><div class="rk-wrap"><div class="rk-picker"><div class="rk-picker-title">Choose your avatar</div><div class="rk-avatar-grid">' +
      LABELS.filter(x => x !== 'TEACHER').map(id => '<button type="button" class="rk-avatar-choice ' + (scene.studentAvatarId === id ? 'active' : '') + '" data-action="choose-avatar" data-id="' + h(id) + '"><div class="rk-avatar">' + img(id) + '</div>' + h(id) + '</button>').join('') +
      '</div><div style="text-align:center;margin-top:18px"><button type="button" class="rk-btn rk-primary" data-action="start">Start roleplay</button></div></div></div></div></div>';
  }
  function player() {
    return '<div class="rk-app">' + header() + '<div class="rk-scroll"><div class="rk-wrap">' + turns.map(turnCard).join('') + '</div></div></div>';
  }
  function turnCard(t, i) {
    let done = i < completed, act = i === completed, lock = i > completed, sc = scores[i] || {}, ans = answers[i] || '';
    return '<section class="rk-card ' + (lock ? 'locked' : '') + '"><div class="rk-head"><div class="rk-avatar">' + img(scene.teacherAvatarId) + '</div><div class="rk-turn-label ' + (act ? 'active' : '') + '">TURN ' + (i + 1) + ' · ' + (done ? '✓ completed' : act ? 'active' : '🔒 locked') + '</div></div><div class="rk-block"><div class="rk-mini">' + h(scene.agentRole) + '<button type="button" class="rk-listen" data-action="listen-agent" data-index="' + i + '">🔊 Listen</button></div><div class="rk-bubble">' + h(t.agent || '...') + '</div></div>' +
      (done ? '<div class="rk-said"><div class="rk-mini">You said</div><div>' + h(ans) + '</div></div><div class="rk-model"><div class="rk-mini" style="color:#1d9e75">Model answer</div><div>' + h(t.ideal) + '</div></div><div class="rk-improve"><div class="rk-mini" style="color:#f97316">Feedback</div><div>' + h(sc.improve || 'Good work!') + '</div></div><div class="rk-score-row"><div class="rk-chip"><b>' + h(sc.fluency || 0) + '</b><span>Fluency</span></div><div class="rk-chip"><b>' + h(sc.accuracy || 0) + '</b><span>Accuracy</span></div><div class="rk-chip"><b>' + h(sc.vocab || 0) + '</b><span>Vocab</span></div></div>' : '') +
      (act ? '<div class="rk-turn-box"><div class="rk-say-row"><div class="rk-avatar">' + img(scene.studentAvatarId) + '</div><button type="button" class="rk-mic" data-action="mic" data-index="' + i + '">🎙 Now say it</button><span class="rk-hint">Hint: ' + h(t.hint || 'Answer naturally') + '</span></div><textarea class="rk-hidden-input" data-answer="1" placeholder="Speech will appear here. You can also type...">' + h(activeInput) + '</textarea></div>' + (shown[i] ? '<div class="rk-model"><div class="rk-mini" style="color:#1d9e75">Model answer</div><div>' + h(t.ideal) + '</div></div>' : '') + '<div class="rk-actions"><button type="button" class="rk-btn" data-action="show-answer">Show answer</button><button type="button" class="rk-btn rk-primary" data-action="next">' + (i >= turns.length - 1 ? 'Finish' : 'Next') + '</button></div>' : '') +
      (lock ? '<div class="rk-turn-box disabled"><span class="rk-hint">Finish the previous turn to unlock this one.</span></div>' : '') + '</section>';
  }
  function complete() {
    let avg = scores.length ? Math.round(scores.reduce((a, s) => a + (s.overall || 0), 0) / scores.length * 10) : 0;
    return '<div class="rk-app">' + header() + '<div class="rk-complete"><div class="rk-complete-card"><div class="rk-avatar" style="width:96px;height:96px;margin:0 auto 12px">' + img(scene.studentAvatarId) + '</div><h1 style="font-family:Fredoka,sans-serif;color:#f97316">Great job!</h1><p style="font-weight:900;color:#7f77dd;margin:12px 0 20px">Score: ' + avg + '%</p><button type="button" class="rk-btn rk-primary" data-action="restart">Try again</button></div></div></div>';
  }
  function editor() {
    return '<div class="rk-app"><div class="rk-top"><div class="rk-title">Roleplay Kids Editor</div></div><div class="rk-editor-body"><div class="rk-editor-wrap">' +
      '<section class="rk-edit-card"><div class="rk-edit-head"><div class="rk-edit-title">Kids roleplay settings</div></div><div class="rk-edit-content"><div class="rk-grid3"><div><label class="rk-label">Title</label><input class="rk-input" data-scene="title" value="' + h(scene.title) + '"></div><div><label class="rk-label">Scenario</label><input class="rk-input" data-scene="scenario" value="' + h(scene.scenario) + '"></div><div><label class="rk-label">Level</label><input class="rk-input" data-scene="level" value="' + h(scene.level) + '"></div></div><div class="rk-grid2" style="margin-top:14px"><div><label class="rk-label">Agent role</label><input class="rk-input" data-scene="agentRole" value="' + h(scene.agentRole) + '"></div><div><label class="rk-label">Student role</label><input class="rk-input" data-scene="studentRole" value="' + h(scene.studentRole) + '"></div></div><div style="margin-top:14px"><label class="rk-label">Teacher avatar</label><div class="rk-avatar-grid">' + LABELS.map(id => '<button type="button" class="rk-avatar-choice ' + (scene.teacherAvatarId === id ? 'active' : '') + '" data-action="teacher-avatar" data-id="' + h(id) + '"><div class="rk-avatar">' + img(id) + '</div>' + h(id) + '</button>').join('') + '</div></div></div></section>' +
      '<section class="rk-edit-card"><div class="rk-edit-head"><div class="rk-edit-title">Conversation turns</div></div><div class="rk-edit-content">' + turns.map((t, i) => '<div class="rk-turn-edit"><button type="button" class="rk-remove" data-action="remove-turn" data-index="' + i + '">Remove</button><div class="rk-turn-label active">Turn ' + (i + 1) + '</div><label class="rk-label">Agent line</label><textarea class="rk-textarea" data-turn="' + i + '" data-prop="agent">' + h(t.agent) + '</textarea><div class="rk-grid2" style="margin-top:12px"><div><label class="rk-label">Student hint</label><textarea class="rk-textarea" data-turn="' + i + '" data-prop="hint">' + h(t.hint) + '</textarea></div><div><label class="rk-label">Model sentence</label><textarea class="rk-textarea" data-turn="' + i + '" data-prop="ideal">' + h(t.ideal) + '</textarea></div></div><label class="rk-label" style="margin-top:12px">Criteria</label><input class="rk-input" data-turn="' + i + '" data-prop="criteria" value="' + h(t.criteria) + '"></div>').join('') + '<button type="button" class="rk-btn" data-action="add-turn">+ Add turn</button></div></section>' +
      '<div class="rk-savebar"><button type="button" class="rk-btn" data-action="preview">Preview as student</button><div class="rk-status">' + h(status) + '</div><button type="button" class="rk-btn rk-primary" data-action="save" ' + (saving ? 'disabled' : '') + '>' + (saving ? 'Saving...' : 'Save kids roleplay') + '</button></div></div></div></div>';
  }

  function render() {
    if (!root) return;
    let sc = root.querySelector('.rk-scroll,.rk-editor-body');
    let top = sc ? sc.scrollTop : 0;
    root.innerHTML = view === 'editor' ? editor() : (view === 'avatar' ? avatarPick() : (view === 'complete' ? complete() : player()));
    let sc2 = root.querySelector('.rk-scroll,.rk-editor-body');
    if (sc2) sc2.scrollTop = top;
  }
  function next() {
    let i = completed;
    if (!activeInput.trim() && !shown[i]) {
      alert('Say/type your answer or tap Show answer first.');
      return;
    }
    answers[i] = activeInput.trim() || '(Used Show answer)';
    scores[i] = score(activeInput, turns[i] ? turns[i].ideal : '');
    completed = Math.min(turns.length, completed + 1);
    activeInput = '';
    if (completed >= turns.length) view = 'complete';
    render();
  }
  function mic(i) {
    let SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SR) {
      alert('Speech recognition is not supported. You can type your answer.');
      return;
    }
    let rec = new SR();
    let btn = root.querySelector('[data-action="mic"][data-index="' + i + '"]');
    let box = root.querySelector('[data-answer="1"]');
    rec.lang = 'en-US';
    rec.continuous = false;
    rec.interimResults = false;
    if (btn) {
      btn.classList.add('listening');
      btn.textContent = '🎙 Listening...';
    }
    rec.onresult = e => {
      activeInput = e.results[0][0].transcript;
      if (box) box.value = activeInput;
    };
    rec.onend = rec.onerror = () => {
      if (btn) {
        btn.classList.remove('listening');
        btn.textContent = '🎙 Now say it';
      }
    };
    rec.start();
  }

  root.addEventListener('input', function (e) {
    let el = e.target;
    if (el.dataset.scene) {
      scene[el.dataset.scene] = el.value;
      return;
    }
    if (el.dataset.turn) {
      let i = Number(el.dataset.turn);
      turns[i] = Object.assign({}, turns[i] || normTurn({}), {[el.dataset.prop]: el.value});
      return;
    }
    if (el.dataset.answer) {
      activeInput = el.value;
    }
  });
  root.addEventListener('click', async function (e) {
    let b = e.target.closest('[data-action]');
    if (!b) return;
    e.preventDefault();
    let a = b.dataset.action;
    if (a === 'listen-agent') speakAgent(Number(b.dataset.index));
    if (a === 'choose-avatar') { scene.studentAvatarId = b.dataset.id; render(); }
    if (a === 'teacher-avatar') { scene.teacherAvatarId = b.dataset.id; render(); }
    if (a === 'start') { view = 'player'; render(); }
    if (a === 'mic') mic(Number(b.dataset.index));
    if (a === 'show-answer') { shown[completed] = true; render(); }
    if (a === 'next') next();
    if (a === 'restart') { view = 'avatar'; completed = 0; answers = []; scores = []; shown = []; activeInput = ''; render(); }
    if (a === 'preview') { view = 'avatar'; completed = 0; answers = []; scores = []; shown = []; activeInput = ''; render(); }
    if (a === 'add-turn') { turns.push({id: uid(), agent: '', hint: '', ideal: '', criteria: ''}); render(); }
    if (a === 'remove-turn') { turns = turns.filter((_, i) => i !== Number(b.dataset.index)); if (!turns.length) turns = normTurns([]); render(); }
    if (a === 'save') {
      if (!window.RK_ACTIVITY_ID) { status = 'No activity ID - cannot save.'; render(); return; }
      saving = true; status = 'Saving...'; render();
      try {
        let resp = await fetch('save.php', {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          credentials: 'same-origin',
          body: JSON.stringify({id: window.RK_ACTIVITY_ID, scene: scene, turns: turns})
        });
        let json = await resp.json().catch(() => ({}));
        if (!resp.ok || !json.ok) throw new Error(json.error || ('HTTP ' + resp.status));
        status = 'Saved successfully';
      } catch (err) {
        status = 'Could not save: ' + err.message;
      } finally {
        saving = false;
        render();
      }
    }
  });

  try {
    render();
  } catch (err) {
    console.error('[roleplay kids] render error', err);
    root.innerHTML = '<div style="padding:20px">Roleplay Kids could not render. Check console.</div>';
  }
})();
</script>
<?php
$content = ob_get_clean();
error_log('[roleplay_kids/viewer] html length=' . strlen($content));
render_activity_viewer($viewerTitle, '🌟', $content);
