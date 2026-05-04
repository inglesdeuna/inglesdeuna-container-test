<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id'])        ? trim((string)$_GET['id'])        : '';
$unit       = isset($_GET['unit'])      ? trim((string)$_GET['unit'])      : '';
$returnTo   = isset($_GET['return_to']) ? trim((string)$_GET['return_to']) : '';
$assignId   = isset($_GET['assignment'])? trim((string)$_GET['assignment']): '';

if ($activityId === '' && $unit === '') die('Activity not specified');

/* ── DATA LAYER ──────────────────────────────────────────── */

function wpv_resolve_unit(PDO $pdo, string $id): string {
    if ($id === '') return '';
    $s = $pdo->prepare("SELECT unit_id FROM activities WHERE id=:id LIMIT 1");
    $s->execute(['id' => $id]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    return ($r && isset($r['unit_id'])) ? (string)$r['unit_id'] : '';
}

function wpv_normalize(mixed $raw): array {
    $allowed = ['writing', 'video_writing'];
    $d = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($d)) $d = [];
    $qs = [];
    foreach ((array)($d['questions'] ?? []) as $item) {
        if (!is_array($item)) continue;
        $type = in_array($item['type'] ?? '', $allowed, true) ? (string)$item['type'] : 'writing';
        // migrate legacy types → writing
        if (!in_array($type, $allowed, true)) $type = 'writing';
        $qs[] = [
            'id'             => trim((string)($item['id']          ?? uniqid('wp_'))),
            'type'           => $type,
            'question'       => trim((string)($item['question']    ?? '')),
            'instruction'    => trim((string)($item['instruction'] ?? '')),
            'media'          => trim((string)($item['media']       ?? '')),
            'writing_rows'   => max(2, min(14, (int)($item['writing_rows']   ?? 6))),
            'response_count' => max(1, min(20, (int)($item['response_count'] ?? 1))),
        ];
    }
    return [
        'title'       => trim((string)($d['title']       ?? '')) ?: 'Writing Practice',
        'description' => trim((string)($d['description'] ?? '')),
        'questions'   => $qs,
    ];
}

function wpv_load(PDO $pdo, string $id, string $unit): array {
    $row = null;
    if ($id !== '') {
        $s = $pdo->prepare("SELECT id, data FROM activities WHERE id=:id AND type='writing_practice' LIMIT 1");
        $s->execute(['id' => $id]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row && $unit !== '') {
        $s = $pdo->prepare("SELECT id, data FROM activities WHERE unit_id=:u AND type='writing_practice' ORDER BY id ASC LIMIT 1");
        $s->execute(['u' => $unit]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row) return ['id' => '', 'title' => 'Writing Practice', 'description' => '', 'questions' => []];
    $p = wpv_normalize($row['data'] ?? null);
    return ['id' => (string)$row['id'], 'title' => $p['title'], 'description' => $p['description'], 'questions' => $p['questions']];
}

/* ── BOOTSTRAP ───────────────────────────────────────────── */
if ($unit === '' && $activityId !== '') $unit = wpv_resolve_unit($pdo, $activityId);

$activity    = wpv_load($pdo, $activityId, $unit);
$viewerTitle = $activity['title'];
$description = $activity['description'];
$questions   = $activity['questions'];
if ($activityId === '' && $activity['id'] !== '') $activityId = $activity['id'];

if ($returnTo === '') {
    $returnTo = '../../academic/student_course.php?assignment=' . urlencode($assignId) . '&unit=' . urlencode($unit) . '&step=9999';
}

ob_start();
?>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">
<style>
:root {
    --wp-purple:      #7F77DD;
    --wp-purple-dk:   #534AB7;
    --wp-purple-soft: #EEEDFE;
    --wp-orange:      #F97316;
    --wp-orange-dk:   #C2580A;
    --wp-teal:        #1D9E75;
    --wp-ink:         #271B5D;
    --wp-muted:       #7C739B;
    --wp-shadow:      0 8px 32px rgba(83,74,183,.16);
}
*{box-sizing:border-box}

/* ── Shell ── */
.wp-viewer-shell {
    max-width: 860px;
    margin: 0 auto;
    font-family: 'Nunito','Segoe UI',sans-serif;
    display: flex;
    flex-direction: column;
    gap: 14px;
}

/* ── Header banner ── */
.wp-header {
    background: linear-gradient(135deg, #534AB7 0%, #7F77DD 100%);
    border-radius: 20px;
    padding: 18px 22px;
    box-shadow: var(--wp-shadow);
    color: #fff;
}
.wp-header h2 {
    margin: 0 0 4px;
    font-family: 'Fredoka', sans-serif;
    font-size: clamp(22px, 3.5vw, 32px);
    font-weight: 700;
    line-height: 1.1;
}
.wp-header p {
    margin: 0;
    font-size: 14px;
    opacity: .88;
    line-height: 1.5;
}

/* ── Progress row ── */
.wp-progress-row {
    display: flex;
    align-items: center;
    gap: 12px;
}
.wp-progress-track {
    flex: 1;
    height: 8px;
    background: rgba(127,119,221,.18);
    border-radius: 999px;
    overflow: hidden;
}
.wp-progress-fill {
    height: 100%;
    background: linear-gradient(90deg, var(--wp-purple), var(--wp-orange));
    border-radius: 999px;
    transition: width .4s ease;
    width: 0%;
}
.wp-progress-count {
    font-size: 12px;
    font-weight: 900;
    color: var(--wp-purple-dk);
    white-space: nowrap;
    min-width: 48px;
    text-align: right;
}

/* ── Card ── */
.wp-card {
    background: #fff;
    border: 1px solid #E4E1F8;
    border-radius: 24px;
    padding: 22px;
    box-shadow: var(--wp-shadow);
    display: flex;
    flex-direction: column;
    gap: 14px;
}

/* ── Video area ── */
.wp-video-wrap {
    border-radius: 14px;
    overflow: hidden;
    background: #000;
    aspect-ratio: 16/9;
    width: 100%;
}
.wp-video-wrap iframe,
.wp-video-wrap video {
    display: block;
    width: 100%;
    height: 100%;
    border: none;
}

/* ── Prompt ── */
.wp-prompt {
    font-family: 'Fredoka', sans-serif;
    font-size: clamp(18px, 2.8vw, 26px);
    font-weight: 700;
    color: var(--wp-ink);
    line-height: 1.3;
    text-align: center;
}
.wp-instr {
    font-size: 14px;
    color: var(--wp-muted);
    text-align: center;
    font-weight: 700;
    line-height: 1.5;
}

/* ── Writing items ── */
.wp-writing-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.wp-writing-item {
    background: linear-gradient(180deg, #fafafe 0%, #f3f1ff 100%);
    border: 1px solid #ddd6fe;
    border-radius: 14px;
    padding: 14px;
}
.wp-writing-item-label {
    display: block;
    font-size: 12px;
    font-weight: 900;
    color: var(--wp-purple-dk);
    letter-spacing: .04em;
    text-transform: uppercase;
    margin-bottom: 8px;
}
.wp-writing-item textarea {
    width: 100%;
    padding: 10px 12px;
    border-radius: 10px;
    border: 1px solid #c4b5fd;
    font-size: 15px;
    font-family: inherit;
    line-height: 1.6;
    resize: vertical;
    transition: border-color .15s;
    background: #fff;
}
.wp-writing-item textarea:focus {
    outline: none;
    border-color: var(--wp-purple);
    box-shadow: 0 0 0 3px rgba(127,119,221,.18);
}
.wp-writing-item textarea.wp-locked {
    background: #f5f3ff;
    color: #4c1d95;
    cursor: default;
}

/* ── Feedback ── */
.wp-feedback {
    min-height: 22px;
    font-size: 14px;
    font-weight: 800;
    text-align: center;
    border-radius: 10px;
    padding: 6px 12px;
    transition: all .2s;
}
.wp-feedback:empty { display: none; }
.wp-feedback.good { background: #ecfdf5; color: #166534; border: 1px solid #86efac; }
.wp-feedback.info { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }

/* ── Answer reveal ── */
.wp-answer-box {
    background: #f5f3ff;
    border: 1px solid #c4b5fd;
    border-radius: 14px;
    padding: 14px 16px;
    display: none;
}
.wp-answer-box.show { display: block; }
.wp-answer-box h4 {
    margin: 0 0 8px;
    font-size: 12px;
    font-weight: 900;
    color: var(--wp-purple-dk);
    text-transform: uppercase;
    letter-spacing: .05em;
}
.wp-answer-item {
    font-size: 15px;
    color: var(--wp-ink);
    font-weight: 700;
    padding: 4px 0;
    border-bottom: 1px solid #e9d5ff;
    line-height: 1.5;
}
.wp-answer-item:last-child { border-bottom: none; }

/* ── Controls ── */
.wp-controls {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    flex-wrap: wrap;
}
.wp-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 11px 20px;
    border: none;
    border-radius: 999px;
    font-family: inherit;
    font-size: 14px;
    font-weight: 800;
    cursor: pointer;
    transition: filter .15s, transform .15s;
    line-height: 1;
    text-decoration: none;
    white-space: nowrap;
}
.wp-btn:hover:not(:disabled) { filter: brightness(1.07); transform: translateY(-1px); }
.wp-btn:disabled { opacity: .45; cursor: default; transform: none; }
.wp-btn-prev  { background: linear-gradient(180deg,#F97316,#C2580A); color: #fff; }
.wp-btn-show  { background: linear-gradient(180deg,#7F77DD,#534AB7); color: #fff; }
.wp-btn-next  { background: linear-gradient(180deg,#1D9E75,#085041); color: #fff; }

/* ── Completion screen ── */
.wp-done {
    display: none;
    text-align: center;
    padding: 40px 20px;
    background: #fff;
    border: 1px solid #E4E1F8;
    border-radius: 24px;
    box-shadow: var(--wp-shadow);
}
.wp-done.active { display: block; }
.wp-done-icon  { font-size: 72px; margin-bottom: 16px; }
.wp-done-title {
    font-family: 'Fredoka', sans-serif;
    font-size: clamp(28px, 4vw, 40px);
    font-weight: 700;
    color: var(--wp-purple-dk);
    margin: 0 0 10px;
}
.wp-done-sub {
    font-size: 15px;
    color: var(--wp-muted);
    line-height: 1.6;
    margin: 0 0 8px;
}
.wp-done-words {
    display: inline-block;
    background: var(--wp-purple-soft);
    color: var(--wp-purple-dk);
    font-weight: 900;
    font-size: 14px;
    padding: 6px 14px;
    border-radius: 999px;
    margin-bottom: 24px;
}
.wp-done-actions { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
.wp-btn-restart { background: linear-gradient(180deg,#7F77DD,#534AB7); color: #fff; }
.wp-btn-continue { background: linear-gradient(180deg,#F97316,#C2580A); color: #fff; }

/* ── Embedded / fullscreen fills iframe ── */
body.embedded-mode .viewer-content,
body.fullscreen-embedded .viewer-content,
body.presentation-mode .viewer-content {
    overflow-y: auto !important;
}
body.embedded-mode .wp-viewer-shell,
body.fullscreen-embedded .wp-viewer-shell,
body.presentation-mode .wp-viewer-shell {
    max-width: 100%;
}
body.embedded-mode .wp-header,
body.fullscreen-embedded .wp-header,
body.presentation-mode .wp-header {
    padding: 10px 16px;
    border-radius: 14px;
}
body.embedded-mode .wp-header h2,
body.fullscreen-embedded .wp-header h2,
body.presentation-mode .wp-header h2 { font-size: 18px; }

@media (max-width: 600px) {
    .wp-card { padding: 16px; }
    .wp-controls { justify-content: center; }
}
</style>

<?php if (empty($questions)): ?>
<div style="text-align:center;padding:40px;color:#7C739B;font-family:'Nunito',sans-serif;font-size:16px;font-weight:700;">
    No hay preguntas configuradas para esta actividad.
</div>
<?php else: ?>

<div class="wp-viewer-shell">

    <!-- Header -->
    <div class="wp-header">
        <h2><?= htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8') ?></h2>
        <?php if ($description !== ''): ?>
        <p><?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
    </div>

    <!-- Progress -->
    <div class="wp-progress-row">
        <div class="wp-progress-track">
            <div class="wp-progress-fill" id="wpFill"></div>
        </div>
        <span class="wp-progress-count" id="wpCount">1 / <?= count($questions) ?></span>
    </div>

    <!-- Card -->
    <div class="wp-card" id="wpCard">
        <div id="wpVideoArea" style="display:none"></div>
        <div class="wp-prompt" id="wpPrompt"></div>
        <div class="wp-instr"  id="wpInstr"  style="display:none"></div>
        <div class="wp-writing-list" id="wpList"></div>
        <div class="wp-answer-box" id="wpAnswerBox"></div>
        <div class="wp-feedback" id="wpFeedback"></div>
    </div>

    <!-- Controls -->
    <div class="wp-controls" id="wpControls">
        <button class="wp-btn wp-btn-prev" id="btnPrev" type="button">← Anterior</button>
        <button class="wp-btn wp-btn-show" id="btnShow" type="button">👁 Ver respuesta</button>
        <button class="wp-btn wp-btn-next" id="btnNext" type="button">Siguiente →</button>
    </div>

    <!-- Completion -->
    <div class="wp-done" id="wpDone">
        <div class="wp-done-icon">✅</div>
        <h2 class="wp-done-title" id="wpDoneTitle"></h2>
        <p class="wp-done-sub">¡Completaste todas las preguntas! Buen trabajo.</p>
        <div class="wp-done-words" id="wpDoneWords" style="display:none"></div>
        <div class="wp-done-actions">
            <button class="wp-btn wp-btn-restart" id="btnRestart" type="button">↺ Reintentar</button>
            <?php if ($returnTo !== ''): ?>
            <button class="wp-btn wp-btn-continue" id="btnContinue" type="button">Continuar →</button>
            <?php endif; ?>
        </div>
    </div>

</div>

<audio id="wpSndDone" src="../../hangman/assets/win (1).mp3" preload="auto"></audio>

<script>
(function () {
'use strict';

/* ── data ── */
var QUESTIONS   = <?= json_encode(array_values($questions), JSON_UNESCAPED_UNICODE) ?>;
var RETURN_TO   = <?= json_encode($returnTo,   JSON_UNESCAPED_UNICODE) ?>;
var ACTIVITY_ID = <?= json_encode($activityId, JSON_UNESCAPED_UNICODE) ?>;
var UNIT_ID     = <?= json_encode($unit,        JSON_UNESCAPED_UNICODE) ?>;
var ASSIGN_ID   = <?= json_encode($assignId,    JSON_UNESCAPED_UNICODE) ?>;

/* ── dom ── */
var fillEl     = document.getElementById('wpFill');
var countEl    = document.getElementById('wpCount');
var cardEl     = document.getElementById('wpCard');
var videoEl    = document.getElementById('wpVideoArea');
var promptEl   = document.getElementById('wpPrompt');
var instrEl    = document.getElementById('wpInstr');
var listEl     = document.getElementById('wpList');
var ansBoxEl   = document.getElementById('wpAnswerBox');
var feedbackEl = document.getElementById('wpFeedback');
var controlsEl = document.getElementById('wpControls');
var doneEl     = document.getElementById('wpDone');
var doneTitleEl= document.getElementById('wpDoneTitle');
var doneWordsEl= document.getElementById('wpDoneWords');
var btnPrev    = document.getElementById('btnPrev');
var btnShow    = document.getElementById('btnShow');
var btnNext    = document.getElementById('btnNext');
var btnRestart = document.getElementById('btnRestart');
var btnCont    = document.getElementById('btnContinue');
var sndDone    = document.getElementById('wpSndDone');

/* ── state ── */
var idx       = 0;
var done      = false;
var inputs    = [];   // current textareas
var responses = [];   // { question_id, question_text, response_text }

/* ── helpers ── */
function esc(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function toEmbed(url) {
    if (!url) return '';
    if (/youtube\.com\/embed\/|player\.vimeo\.com/.test(url)) return url;
    var m = url.match(/youtu\.be\/([A-Za-z0-9_-]{11})/);
    if (m) return 'https://www.youtube-nocookie.com/embed/' + m[1];
    var m2 = url.match(/[?&]v=([A-Za-z0-9_-]{11})/);
    if (m2) return 'https://www.youtube-nocookie.com/embed/' + m2[1];
    return url;
}

function wordCount(s) {
    return String(s || '').trim().replace(/\s+/g,' ').split(' ').filter(Boolean).length;
}

function playSound(el) {
    try { el.pause(); el.currentTime = 0; el.play(); } catch(e) {}
}

/* ── updateProgress ── */
function updateProgress() {
    var total = QUESTIONS.length;
    var pct   = total > 0 ? Math.round(((idx + 1) / total) * 100) : 0;
    fillEl.style.width = pct + '%';
    countEl.textContent = (idx + 1) + ' / ' + total;
    btnPrev.disabled = idx === 0;
}

/* ── loadCard ── */
function loadCard() {
    var q    = QUESTIONS[idx];
    var type = String(q.type || 'writing');

    feedbackEl.textContent = '';
    feedbackEl.className   = 'wp-feedback';
    ansBoxEl.className     = 'wp-answer-box';
    ansBoxEl.innerHTML     = '';
    inputs = [];

    /* video */
    videoEl.innerHTML    = '';
    videoEl.style.display = 'none';
    if (type === 'video_writing' && q.media) {
        var rawUrl  = String(q.media);
        var isVideo = /\.(mp4|webm|ogg)(\?|$)/i.test(rawUrl) || /cloudinary\.com\/.+\/video\//i.test(rawUrl);
        var wrap = document.createElement('div');
        wrap.className = 'wp-video-wrap';
        if (isVideo) {
            var vid = document.createElement('video');
            vid.controls = true; vid.preload = 'metadata';
            var src = document.createElement('source');
            src.src = rawUrl;
            vid.appendChild(src);
            wrap.appendChild(vid);
        } else {
            var iframe = document.createElement('iframe');
            iframe.src = toEmbed(rawUrl);
            iframe.allow = 'autoplay; encrypted-media; picture-in-picture';
            iframe.allowFullscreen = true;
            wrap.appendChild(iframe);
        }
        videoEl.appendChild(wrap);
        videoEl.style.display = '';
    }

    /* prompt */
    promptEl.textContent = q.question || '';

    /* instruction */
    if (q.instruction) {
        instrEl.textContent  = q.instruction;
        instrEl.style.display = '';
    } else {
        instrEl.textContent  = '';
        instrEl.style.display = 'none';
    }

    /* writing inputs */
    listEl.innerHTML = '';
    var count = Math.max(1, Math.min(20, parseInt(q.response_count, 10) || 1));
    var rows  = Math.max(2, Math.min(14, parseInt(q.writing_rows,   10) || 6));
    for (var i = 0; i < count; i++) {
        var item = document.createElement('div');
        item.className = 'wp-writing-item';

        var lbl = document.createElement('label');
        lbl.className   = 'wp-writing-item-label';
        lbl.textContent = count > 1 ? 'Respuesta ' + (i + 1) : 'Tu respuesta';
        item.appendChild(lbl);

        var ta = document.createElement('textarea');
        ta.rows        = rows;
        ta.spellcheck  = true;
        ta.setAttribute('lang', 'en');
        ta.setAttribute('autocapitalize', 'sentences');
        ta.placeholder = 'Escribe tu respuesta aquí…';
        item.appendChild(ta);
        listEl.appendChild(item);
        inputs.push(ta);
    }

    /* show-answer button: only if there are reference answers */
    var hasAnswers = Array.isArray(q.correct_answers) && q.correct_answers.length > 0;
    btnShow.style.display = hasAnswers ? '' : 'none';
    btnShow.disabled = false;

    updateProgress();
}

/* ── showAnswer ── */
function showAnswer() {
    var q = QUESTIONS[idx];
    if (!Array.isArray(q.correct_answers) || q.correct_answers.length === 0) return;

    ansBoxEl.innerHTML = '<h4>Respuesta de referencia</h4>';
    q.correct_answers.forEach(function(a) {
        if (!a) return;
        var div = document.createElement('div');
        div.className   = 'wp-answer-item';
        div.textContent = a;
        ansBoxEl.appendChild(div);
    });
    ansBoxEl.className = 'wp-answer-box show';
    btnShow.disabled   = true;

    feedbackEl.textContent = 'Revisa la respuesta de referencia y compara con la tuya.';
    feedbackEl.className   = 'wp-feedback info';
}

/* ── collectResponses ── */
function collectCurrentResponses() {
    var q = QUESTIONS[idx];
    inputs.forEach(function(ta, i) {
        var text = ta.value.trim();
        if (text !== '') {
            responses.push({
                question_id:   String(q.id || ''),
                question_text: String(q.question || ''),
                response_index: i,
                response_text: text,
            });
        }
        ta.classList.add('wp-locked');
        ta.disabled = true;
    });
}

/* ── navigation ── */
function goPrev() {
    if (idx === 0 || done) return;
    idx--;
    loadCard();
}

function goNext() {
    if (done) return;
    collectCurrentResponses();
    if (idx < QUESTIONS.length - 1) {
        idx++;
        loadCard();
    } else {
        showCompleted();
    }
}

/* ── completion ── */
async function showCompleted() {
    done = true;
    cardEl.style.display     = 'none';
    controlsEl.style.display = 'none';
    doneEl.classList.add('active');
    if (doneTitleEl) doneTitleEl.textContent = QUESTIONS[0] ? (QUESTIONS[0].question || '✍️ Writing Practice') : '✍️ Writing Practice';
    playSound(sndDone);

    /* word count */
    var totalWords = 0;
    responses.forEach(function(r) { totalWords += wordCount(r.response_text); });
    if (totalWords > 0 && doneWordsEl) {
        doneWordsEl.textContent = '📊 ' + totalWords + ' palabra' + (totalWords !== 1 ? 's' : '') + ' escritas';
        doneWordsEl.style.display = '';
    }

    /* save responses */
    if (responses.length > 0) {
        try {
            var fd = new FormData();
            fd.append('activity_id',   ACTIVITY_ID);
            fd.append('unit_id',       UNIT_ID);
            fd.append('assignment_id', ASSIGN_ID);
            fd.append('responses',     JSON.stringify(responses));
            await fetch('/lessons/lessons/activities/writing_practice/wp_save_response.php', {
                method: 'POST', body: fd,
            });
        } catch(e) { /* non-critical */ }
    }

    /* persist score */
    if (RETURN_TO) {
        var total  = QUESTIONS.length;
        var pct    = 100;
        var errors = 0;
        var joiner = RETURN_TO.indexOf('?') !== -1 ? '&' : '?';
        var saveUrl = RETURN_TO + joiner
            + 'activity_percent=' + encodeURIComponent(String(pct))
            + '&activity_errors='  + encodeURIComponent(String(errors))
            + '&activity_total='   + encodeURIComponent(String(total))
            + '&activity_id='      + encodeURIComponent(ACTIVITY_ID)
            + '&activity_type=writing_practice';
        try {
            var r = await fetch(saveUrl, { method: 'GET', credentials: 'same-origin', cache: 'no-store' });
            if (!r.ok) throw new Error('not ok');
        } catch(e) {
            try {
                if (window.top && window.top !== window.self) { window.top.location.href = saveUrl; }
                else { window.location.href = saveUrl; }
            } catch(ex) { window.location.href = saveUrl; }
        }
    }
}

/* ── restart ── */
function restart() {
    idx       = 0;
    done      = false;
    responses = [];
    inputs    = [];
    cardEl.style.display     = '';
    controlsEl.style.display = '';
    doneEl.classList.remove('active');
    if (doneWordsEl) doneWordsEl.style.display = 'none';
    loadCard();
}

/* ── events ── */
btnPrev.addEventListener('click', goPrev);
btnNext.addEventListener('click', goNext);
btnShow.addEventListener('click', showAnswer);
btnRestart.addEventListener('click', restart);
if (btnCont) {
    btnCont.addEventListener('click', function() {
        try {
            if (window.top && window.top !== window.self) { window.top.location.href = RETURN_TO; }
            else { window.location.href = RETURN_TO; }
        } catch(e) { window.location.href = RETURN_TO; }
    });
}

/* ── init ── */
loadCard();

})();
</script>

<?php endif; ?>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '✍️', $content);
