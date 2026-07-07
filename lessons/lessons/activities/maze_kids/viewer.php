<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id'])        ? trim((string) $_GET['id'])        : '';
$unit       = isset($_GET['unit'])      ? trim((string) $_GET['unit'])      : '';
$returnTo   = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';
$isStaff    = !empty($_SESSION['academic_logged']) || !empty($_SESSION['admin_logged']);

if ($activityId === '' && $unit === '') {
    die('Activity not specified');
}

/* ── helpers ─────────────────────────────────────────────────────────── */
function mzk_resolve_unit(PDO $pdo, string $activityId): string
{
    if ($activityId === '') return '';
    $stmt = $pdo->prepare("SELECT unit_id FROM activities WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row && isset($row['unit_id']) ? (string) $row['unit_id'] : '';
}

function mzk_default_title(): string { return 'Vocabulary Maze'; }

function mzk_normalize_payload($raw): array
{
    $default = [
        'title'               => mzk_default_title(),
        'theme'               => '',
        'difficulty'          => 'medium',
        'vocabulary_bank'     => [],
        'path_sequence'       => [],
        'distractor_branches' => [],
        'audio_urls'          => [],
    ];
    if ($raw === null || $raw === '') return $default;
    $d = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($d)) return $default;

    $bank = [];
    foreach (($d['vocabulary_bank'] ?? []) as $item) {
        if (!is_array($item)) continue;
        $word = trim((string) ($item['word'] ?? ''));
        $img  = trim((string) ($item['image_url'] ?? ''));
        if ($word === '' && $img === '') continue;
        $bank[] = [
            'id'        => trim((string) ($item['id'] ?? uniqid('mzk_'))) ?: uniqid('mzk_'),
            'image_url' => $img,
            'word'      => $word,
        ];
    }

    $bankIds = array_column($bank, 'id');

    $pathSequence = [];
    foreach (($d['path_sequence'] ?? []) as $vid) {
        $vid = trim((string) $vid);
        if ($vid !== '' && in_array($vid, $bankIds, true)) $pathSequence[] = $vid;
    }

    /* Self-heal: older/legacy activities may have a filled vocabulary bank
       but never got an explicit path built (step 3 of the editor). Rather
       than showing a broken maze, fall back to the bank order so the
       activity is still playable. */
    if (count($pathSequence) === 0 && count($bankIds) > 0) {
        $pathSequence = $bankIds;
    }

    $branches = [];
    foreach (($d['distractor_branches'] ?? []) as $br) {
        if (!is_array($br)) continue;
        $vid = trim((string) ($br['vocabulary_id'] ?? ''));
        if ($vid === '' || !in_array($vid, $bankIds, true)) continue;
        $after = (int) ($br['attach_after_index'] ?? 0);
        $after = max(0, min(count($pathSequence) - 1, $after));
        $branches[] = ['attach_after_index' => $after, 'vocabulary_id' => $vid];
    }

    $audioUrls = [];
    if (is_array($d['audio_urls'] ?? null)) {
        foreach ($d['audio_urls'] as $vid => $url) {
            $vid = trim((string) $vid);
            $url = trim((string) $url);
            if ($vid !== '' && $url !== '') $audioUrls[$vid] = $url;
        }
    }

    $title      = trim((string) ($d['title'] ?? ''));
    $theme      = trim((string) ($d['theme'] ?? ''));
    $difficulty = trim((string) ($d['difficulty'] ?? 'medium'));
    if (!in_array($difficulty, ['easy', 'medium', 'hard'], true)) $difficulty = 'medium';

    return [
        'title'               => $title !== '' ? $title : mzk_default_title(),
        'theme'               => $theme,
        'difficulty'          => $difficulty,
        'vocabulary_bank'     => $bank,
        'path_sequence'       => $pathSequence,
        'distractor_branches' => $branches,
        'audio_urls'          => $audioUrls,
    ];
}

function mzk_load(PDO $pdo, string $activityId, string $unit): array
{
    $fallback = array_merge(['id' => ''], mzk_normalize_payload(null));
    $row = null;

    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE id = :id AND type = 'maze_kids' LIMIT 1");
        $stmt->execute(['id' => $activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row && $unit !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE unit_id = :unit AND type = 'maze_kids' ORDER BY id ASC LIMIT 1");
        $stmt->execute(['unit' => $unit]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row) return $fallback;

    $p = mzk_normalize_payload($row['data'] ?? null);
    return array_merge(['id' => (string) ($row['id'] ?? '')], $p);
}

/* ── load data ───────────────────────────────────────────────────────── */
if ($unit === '' && $activityId !== '') {
    $unit = mzk_resolve_unit($pdo, $activityId);
}

$activity   = mzk_load($pdo, $activityId, $unit);
$viewerTitle = $activity['title'];

if ($activityId === '' && !empty($activity['id'])) {
    $activityId = $activity['id'];
}

if (count($activity['path_sequence']) === 0) {
    $editUrl = 'editor.php?unit=' . urlencode($unit) . ($activityId !== '' ? '&id=' . urlencode($activityId) : '');
    ob_start();
    ?>
    <link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@600;700&family=Nunito:wght@700;800;900&display=swap" rel="stylesheet">
    <style>
    body{margin:0!important;padding:0!important;background:#fff!important;font-family:'Nunito','Segoe UI',sans-serif!important}
    .activity-wrapper{max-width:100%!important;margin:0!important;padding:0!important;background:transparent!important}
    .top-row,.activity-header,.activity-title,.activity-subtitle{display:none!important}
    .viewer-content{padding:0!important;margin:0!important;background:transparent!important;border:none!important;box-shadow:none!important}
    .mzk-empty-page{width:100%;padding:clamp(14px,2.5vw,34px);display:flex;justify-content:center;background:#F8F7FF;box-sizing:border-box}
    .mzk-empty-card{width:min(560px,100%);background:#fff;border:1px solid #F0EEF8;border-radius:34px;padding:clamp(24px,4vw,40px);box-shadow:0 8px 40px rgba(127,119,221,.13);text-align:center;box-sizing:border-box}
    .mzk-empty-card .mzk-kicker{display:inline-flex;align-items:center;justify-content:center;padding:7px 14px;border-radius:999px;background:#FFF0E6;border:1px solid #FCDDBF;color:#C2580A;font-family:'Nunito',sans-serif;font-size:12px;font-weight:900;letter-spacing:.08em;text-transform:uppercase;margin-bottom:14px}
    .mzk-empty-card h1{font-family:'Fredoka',sans-serif;font-size:clamp(22px,4vw,30px);font-weight:700;color:#F97316;margin:0 0 10px}
    .mzk-empty-card p{font-family:'Nunito',sans-serif;font-size:14px;font-weight:700;color:#9B94BE;margin:0 0 22px;line-height:1.5}
    .mzk-empty-card a.mzk-empty-btn{display:inline-flex;align-items:center;justify-content:center;padding:13px 24px;border-radius:8px;background:#7F77DD;color:#fff;text-decoration:none;font-weight:900;font-family:'Nunito',sans-serif;font-size:14px;box-shadow:0 6px 18px rgba(127,119,221,.18)}
    </style>
    <div class="mzk-empty-page">
        <div class="mzk-empty-card">
            <div class="mzk-kicker">Maze</div>
            <h1>🧩 <?php echo htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
            <?php if ($isStaff): ?>
                <p>Este laberinto todavía no tiene un camino configurado. Agrega imágenes y arma la secuencia del camino en el editor para que los estudiantes puedan jugarlo.</p>
                <a class="mzk-empty-btn" href="<?php echo htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8'); ?>">✏️ Configurar laberinto</a>
            <?php else: ?>
                <p>Esta actividad todavía no está lista. ¡Vuelve pronto!</p>
            <?php endif; ?>
        </div>
    </div>
    <?php
    $content = ob_get_clean();
    render_activity_viewer($viewerTitle, '🧩', $content);
    exit;
}

/* map bank by id for quick lookup on the client */
$bankById = [];
foreach ($activity['vocabulary_bank'] as $item) {
    $bankById[$item['id']] = $item;
}

/* ── render ──────────────────────────────────────────────────────────── */
ob_start();
?>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@500;600;700;800;900&display=swap" rel="stylesheet">

<style>
:root{
    --mz-orange:#F97316;--mz-purple:#7F77DD;--mz-purple-dark:#534AB7;
    --mz-purple-soft:#EEEDFE;--mz-lila:#EDE9FA;--mz-muted:#9B94BE;
    --mz-green:#16a34a;--mz-green-soft:#f0fdf4;--mz-green-dark:#15803d;
    --mz-red:#ef4444;--mz-red-soft:#fef2f2;--mz-red-light:#FCA5A5;
    --wall:#CDC7F3;
}
html,body{width:100%;min-height:100%}
body{margin:0!important;padding:0!important;background:#fff!important;font-family:'Nunito','Segoe UI',sans-serif!important}
.activity-wrapper{max-width:100%!important;margin:0!important;padding:0!important;display:flex!important;flex-direction:column!important;background:transparent!important}
.top-row,.activity-header,.activity-title,.activity-subtitle{display:none!important}
.viewer-content{flex:1!important;display:flex!important;flex-direction:column!important;padding:0!important;margin:0!important;background:transparent!important;border:none!important;box-shadow:none!important;border-radius:0!important}

/* ── page shell ── */
.mzk-page{width:100%;padding:clamp(14px,2.5vw,34px);display:flex;align-items:flex-start;justify-content:center;background:#F8F7FF;box-sizing:border-box}
.mzk-app{width:min(820px,100%);margin:0 auto}

.mzk-hero{text-align:center;margin-bottom:clamp(14px,2vw,22px)}
.mzk-kicker{display:inline-flex;align-items:center;justify-content:center;padding:7px 14px;border-radius:999px;background:#FFF0E6;border:1px solid #FCDDBF;color:#C2580A;font-family:'Nunito',sans-serif;font-size:12px;font-weight:900;letter-spacing:.08em;text-transform:uppercase;margin-bottom:10px}
.mzk-hero h1{font-family:'Fredoka',sans-serif;font-size:clamp(26px,5vw,44px);font-weight:700;color:var(--mz-orange);margin:0;line-height:1.05}
.mzk-hero p{font-family:'Nunito',sans-serif;font-size:clamp(13px,1.8vw,16px);font-weight:800;color:var(--mz-muted);margin:8px 0 0}

.mzk-progress{display:flex;align-items:center;gap:10px;margin-bottom:16px}
.mzk-track{flex:1;height:10px;background:var(--mz-purple-soft);border-radius:999px;overflow:hidden}
.mzk-fill{height:100%;background:linear-gradient(90deg,var(--mz-orange),var(--mz-purple));border-radius:999px;transition:width .35s}
.mzk-badge{min-width:72px;text-align:center;padding:6px 10px;border-radius:999px;background:var(--mz-purple);color:#fff;font-family:'Nunito',sans-serif;font-size:12px;font-weight:900}

.mzk-stage{background:#fff;border:1px solid #F0EEF8;border-radius:34px;padding:clamp(14px,2.6vw,26px);box-shadow:0 8px 40px rgba(127,119,221,.13);width:100%;box-sizing:border-box;overflow-x:auto}

.mzk-maze-wrap{position:relative;margin:0 auto}
.mzk-maze-wrap svg{display:block;width:100%;height:auto}

.mzk-node{cursor:pointer}
.mzk-node-circle{fill:#fff;stroke:var(--mz-purple);stroke-width:3}
.mzk-node.branch .mzk-node-circle{stroke:var(--mz-red-light)}
.mzk-node.start .mzk-node-circle{stroke:var(--mz-orange);stroke-width:4}
.mzk-node.end .mzk-node-circle{stroke:var(--mz-green);stroke-width:4}
.mzk-node.done .mzk-node-circle{stroke:var(--mz-green);fill:var(--mz-green-soft)}
.mzk-node.wrong .mzk-node-circle{stroke:var(--mz-red);fill:var(--mz-red-soft)}
.mzk-node image{pointer-events:none}
.mzk-node-label{font-family:'Nunito',sans-serif;font-weight:800;font-size:11px;fill:var(--mz-purple-dark);pointer-events:none}
.mzk-node-badge{fill:var(--mz-purple);}
.mzk-node-badge-text{font-family:'Fredoka',sans-serif;font-weight:700;font-size:13px;fill:#fff;pointer-events:none}
.mzk-node-flag{font-family:'Nunito',sans-serif;font-weight:900;font-size:10px;letter-spacing:.05em;text-transform:uppercase}
.mzk-node.shake{animation:mzkShake .4s}
@keyframes mzkShake{
    10%,90%{transform:translateX(-2px)}
    20%,80%{transform:translateX(3px)}
    30%,50%,70%{transform:translateX(-5px)}
    40%,60%{transform:translateX(5px)}
}

.mzk-instruction{text-align:center;font-family:'Nunito',sans-serif;font-size:13px;font-weight:900;color:var(--mz-purple-dark);margin:12px 0 4px}
#mzkFeedback{text-align:center;font-family:'Nunito',sans-serif;font-size:13px;font-weight:900;min-height:18px;margin-top:6px}
#mzkFeedback.good{color:var(--mz-green-dark)}
#mzkFeedback.bad{color:#b91c1c}

.mzk-completed-screen{display:none}
.mzk-completed-screen.active{display:block}

.mzk-controls{border-top:1px solid #F0EEF8;margin-top:16px;padding-top:16px;text-align:center;display:flex;align-items:center;justify-content:center;gap:10px;flex-wrap:wrap}
.mzk-btn{display:inline-flex;align-items:center;justify-content:center;padding:13px 20px;border:none;border-radius:8px;color:#fff;cursor:pointer;min-width:clamp(100px,14vw,130px);font-weight:900;font-family:'Nunito','Segoe UI',sans-serif;font-size:13px;line-height:1;box-shadow:0 6px 18px rgba(127,119,221,.18);transition:transform .12s,filter .12s}
.mzk-btn:hover{filter:brightness(1.07);transform:translateY(-1px)}
.mzk-btn:active{transform:scale(.98)}
.mzk-btn-restart{background:var(--mz-purple)}

@media(max-width:640px){
    .mzk-page{padding:12px}
    .mzk-stage{border-radius:26px;padding:14px}
}
</style>

<div class="mzk-page" id="mzkPage">
<div class="mzk-app" id="mzkApp">

    <div class="mzk-hero">
        <div class="mzk-kicker">Maze</div>
        <h1><?php echo htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
        <p>🧭 Tap the pictures in order to find your way through the maze!</p>
    </div>

    <div class="mzk-progress">
        <div class="mzk-track"><div class="mzk-fill" id="mzkFill" style="width:0%"></div></div>
        <div class="mzk-badge" id="mzkBadge">0 / <?php echo count($activity['path_sequence']); ?></div>
    </div>

    <div class="mzk-stage" id="mzkStage">
        <div class="mzk-maze-wrap" id="mzkMazeWrap"></div>
        <div id="mzkFeedback"></div>

        <div class="mzk-completed-screen" id="mzkCompleted"></div>

        <div class="mzk-controls" id="mzkControls">
            <button class="mzk-btn mzk-btn-restart" type="button" onclick="mzkRestart()">🔄 Start Over</button>
        </div>
    </div>

</div>
</div>

<audio id="mzkTtsAudio" preload="none"></audio>
<audio id="mzkWinAudio"  src="../../hangman/assets/win.mp3"      preload="auto"></audio>
<audio id="mzkLoseAudio" src="../../hangman/assets/lose.mp3"     preload="auto"></audio>
<audio id="mzkDoneAudio" src="../../hangman/assets/win (1).mp3"  preload="auto"></audio>

<script src="../../core/_activity_feedback.js"></script>
<script src="maze_layout.js"></script>
<script>
/* ── data from PHP ── */
const MZK_TITLE       = <?php echo json_encode($viewerTitle, JSON_UNESCAPED_UNICODE); ?>;
const MZK_ACTIVITY_ID = <?php echo json_encode($activityId, JSON_UNESCAPED_UNICODE); ?>;
const MZK_RETURN_TO   = <?php echo json_encode($returnTo, JSON_UNESCAPED_UNICODE); ?>;
const MZK_BANK_BY_ID  = <?php echo json_encode($bankById, JSON_UNESCAPED_UNICODE); ?>;
const MZK_PATH        = <?php echo json_encode($activity['path_sequence'], JSON_UNESCAPED_UNICODE); ?>;
const MZK_BRANCHES    = <?php echo json_encode($activity['distractor_branches'], JSON_UNESCAPED_UNICODE); ?>;
const MZK_AUDIO_URLS  = <?php echo json_encode($activity['audio_urls'], JSON_UNESCAPED_UNICODE); ?>;
const MZK_TTS_URL     = 'tts.php';

/* ── state ── */
let mzkNextIndex = 0;   // next required index in MZK_PATH the child must tap
let mzkDone = false;
let mzkScores = [];     // per main-path node: always 1 once tapped in order (no penalty scoring)
let mzkLayout = null;

function mzkPlay(el){ try{ el.pause(); el.currentTime=0; el.play(); }catch(e){} }

function mzkNodeVocab(node){ return MZK_BANK_BY_ID[node.vocabularyId] || { image_url:'', word:'' }; }

/* ── build + render the maze SVG ── */
function mzkBuildMaze(){
    mzkLayout = generateMazeLayout(MZK_PATH, MZK_BRANCHES);
    const layout = mzkLayout;
    const NS = 'http://www.w3.org/2000/svg';

    const svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('viewBox', '0 0 ' + layout.width + ' ' + layout.height);
    svg.setAttribute('width', layout.width);
    svg.setAttribute('height', layout.height);

    /* thick semi-transparent wall stroke */
    const wallPath = document.createElementNS(NS, 'path');
    wallPath.setAttribute('d', layout.wallPathD);
    wallPath.setAttribute('fill', 'none');
    wallPath.setAttribute('stroke', 'var(--wall)');
    wallPath.setAttribute('stroke-width', '34');
    wallPath.setAttribute('stroke-linecap', 'round');
    wallPath.setAttribute('stroke-linejoin', 'round');
    wallPath.setAttribute('opacity', '0.35');
    svg.appendChild(wallPath);

    /* thin dashed walkable corridor */
    const corridorPath = document.createElementNS(NS, 'path');
    corridorPath.setAttribute('d', layout.corridorPathD);
    corridorPath.setAttribute('fill', 'none');
    corridorPath.setAttribute('stroke', 'var(--mz-purple)');
    corridorPath.setAttribute('stroke-width', '3');
    corridorPath.setAttribute('stroke-dasharray', '2 10');
    corridorPath.setAttribute('stroke-linecap', 'round');
    corridorPath.setAttribute('opacity', '0.55');
    svg.appendChild(corridorPath);

    const R = 34; /* 68px diameter — preschool-friendly tap target */

    layout.nodes.forEach(function(node){
        const vocab = mzkNodeVocab(node);
        const isStart = node.kind === 'path' && node.index === 0;
        const isEnd   = node.kind === 'path' && node.index === MZK_PATH.length - 1;

        const g = document.createElementNS(NS, 'g');
        g.setAttribute('class', 'mzk-node' + (node.kind === 'branch' ? ' branch' : '') + (isStart ? ' start' : '') + (isEnd ? ' end' : ''));
        g.setAttribute('data-node-id', node.id);
        g.setAttribute('transform', 'translate(' + node.x + ',' + node.y + ')');

        const circle = document.createElementNS(NS, 'circle');
        circle.setAttribute('class', 'mzk-node-circle');
        circle.setAttribute('r', R);
        g.appendChild(circle);

        if (vocab.image_url) {
            const img = document.createElementNS(NS, 'image');
            img.setAttributeNS('http://www.w3.org/1999/xlink', 'href', vocab.image_url);
            img.setAttribute('href', vocab.image_url);
            img.setAttribute('x', -R + 6);
            img.setAttribute('y', -R + 6);
            img.setAttribute('width', (R - 6) * 2);
            img.setAttribute('height', (R - 6) * 2);
            img.setAttribute('clip-path', 'circle(' + (R - 6) + 'px)');
            img.setAttribute('preserveAspectRatio', 'xMidYMid slice');
            g.appendChild(img);
        }

        if (vocab.word) {
            const label = document.createElementNS(NS, 'text');
            label.setAttribute('class', 'mzk-node-label');
            label.setAttribute('x', 0);
            label.setAttribute('y', R + 16);
            label.setAttribute('text-anchor', 'middle');
            label.textContent = vocab.word;
            g.appendChild(label);
        }

        if (node.kind === 'path') {
            const badgeCircle = document.createElementNS(NS, 'circle');
            badgeCircle.setAttribute('class', 'mzk-node-badge');
            badgeCircle.setAttribute('cx', R - 8);
            badgeCircle.setAttribute('cy', -R + 8);
            badgeCircle.setAttribute('r', 12);
            g.appendChild(badgeCircle);

            const badgeText = document.createElementNS(NS, 'text');
            badgeText.setAttribute('class', 'mzk-node-badge-text');
            badgeText.setAttribute('x', R - 8);
            badgeText.setAttribute('y', -R + 12.5);
            badgeText.setAttribute('text-anchor', 'middle');
            badgeText.textContent = String(node.index + 1);
            g.appendChild(badgeText);
        }

        if (isStart || isEnd) {
            const flag = document.createElementNS(NS, 'text');
            flag.setAttribute('class', 'mzk-node-flag');
            flag.setAttribute('x', 0);
            flag.setAttribute('y', -R - 12);
            flag.setAttribute('text-anchor', 'middle');
            flag.setAttribute('fill', isStart ? 'var(--mz-orange)' : 'var(--mz-green)');
            flag.textContent = isStart ? '🚩 START' : '🏁 GOAL';
            g.appendChild(flag);
        }

        g.addEventListener('click', function(){ mzkHandleTap(node, g); });
        svg.appendChild(g);
    });

    const wrap = document.getElementById('mzkMazeWrap');
    wrap.innerHTML = '';
    wrap.appendChild(svg);
}

/* ── interaction ── */
function mzkSetFeedback(text, cls){
    const el = document.getElementById('mzkFeedback');
    el.textContent = text;
    el.className = cls || '';
}

function mzkUpdateProgress(){
    const total = MZK_PATH.length;
    const pct = Math.round(mzkNextIndex / total * 100);
    document.getElementById('mzkFill').style.width = Math.max(pct, 4) + '%';
    document.getElementById('mzkBadge').textContent = mzkNextIndex + ' / ' + total;
}

function mzkHandleTap(node, groupEl){
    if (mzkDone) return;

    if (node.kind === 'path' && node.index === mzkNextIndex) {
        groupEl.classList.add('done');
        mzkPlay(document.getElementById('mzkWinAudio'));
        mzkSetFeedback('✔ Great job!', 'good');
        mzkSpeakWord(node.vocabularyId);
        mzkScores.push(1);
        mzkNextIndex++;
        mzkUpdateProgress();
        if (mzkNextIndex >= MZK_PATH.length) {
            setTimeout(mzkFinish, 500);
        }
        return;
    }

    /* wrong node (out of order path node, or a dead-end branch) — no score penalty */
    groupEl.classList.add('wrong');
    mzkPlay(document.getElementById('mzkLoseAudio'));
    mzkSetFeedback(node.kind === 'branch' ? '🚧 Dead end! Try another path.' : '✘ Not yet — follow the numbers in order.', 'bad');
    groupEl.classList.add('shake');
    setTimeout(function(){
        groupEl.classList.remove('shake');
        groupEl.classList.remove('wrong');
    }, 450);
}

/* ── audio ── */
function mzkSpeakWord(vocabularyId){
    const vocab = MZK_BANK_BY_ID[vocabularyId] || {};
    const word = (vocab.word || '').toLowerCase();
    if (!word) return;

    const savedUrl = MZK_AUDIO_URLS[vocabularyId];
    const audioEl = document.getElementById('mzkTtsAudio');
    if (savedUrl) {
        audioEl.src = savedUrl;
        audioEl.playbackRate = 0.9;
        audioEl.play().catch(function(){ mzkBrowserTTS(word); });
        return;
    }

    const fd = new FormData();
    fd.append('text', word);
    fd.append('voice_id', 'Nggzl2QAXh3OijoXD116');
    fetch(MZK_TTS_URL, { method:'POST', body:fd, credentials:'same-origin' })
        .then(function(r){ if (!r.ok) throw new Error('TTS ' + r.status); return r.blob(); })
        .then(function(blob){
            const url = URL.createObjectURL(blob);
            audioEl.src = url;
            audioEl.playbackRate = 0.9;
            audioEl.onended = function(){ try { URL.revokeObjectURL(url); } catch(e){} };
            audioEl.play();
        })
        .catch(function(){ mzkBrowserTTS(word); });
}

function mzkBrowserTTS(text){
    if (!window.speechSynthesis) return;
    window.speechSynthesis.cancel();
    const u = new SpeechSynthesisUtterance(text);
    u.rate = 0.8; u.pitch = 1.05; u.lang = 'en-US';
    window.speechSynthesis.speak(u);
}

/* ── completion ── */
async function mzkFinish(){
    mzkDone = true;
    mzkPlay(document.getElementById('mzkDoneAudio'));
    document.getElementById('mzkControls').style.display = 'none';
    mzkSetFeedback('', '');

    const completedEl = document.getElementById('mzkCompleted');
    completedEl.classList.add('active');
    completedEl.innerHTML = '';

    const total = MZK_PATH.length;
    const pct = 100; /* wrong taps never count against the final score */

    window.ActivityFeedback.showCompleted({
        target:        completedEl,
        scores:        mzkScores,
        title:         MZK_TITLE,
        activityType:  'Maze',
        questionCount: total,
        onRetry:       mzkRestart
    });

    if (MZK_ACTIVITY_ID && MZK_RETURN_TO) {
        const joiner = MZK_RETURN_TO.indexOf('?') !== -1 ? '&' : '?';
        const saveUrl = MZK_RETURN_TO + joiner
            + 'activity_percent=' + pct
            + '&activity_errors=0'
            + '&activity_total='  + total
            + '&activity_id='     + encodeURIComponent(MZK_ACTIVITY_ID)
            + '&activity_type=maze_kids';
        try {
            const ok = await fetch(saveUrl, { method:'GET', credentials:'same-origin', cache:'no-store' }).then(function(r){ return r.ok; });
            if (!ok) throw new Error();
        } catch (e) {
            try {
                if (window.top && window.top !== window.self) window.top.location.href = saveUrl;
                else window.location.href = saveUrl;
            } catch (ee) { window.location.href = saveUrl; }
        }
    }
}

function mzkRestart(){
    mzkNextIndex = 0;
    mzkDone = false;
    mzkScores = [];
    document.getElementById('mzkControls').style.display = '';
    document.getElementById('mzkCompleted').classList.remove('active');
    document.getElementById('mzkCompleted').innerHTML = '';
    mzkSetFeedback('', '');
    mzkUpdateProgress();
    mzkBuildMaze();
}

/* ── init ── */
mzkBuildMaze();
mzkUpdateProgress();
</script>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '🧩', $content);
