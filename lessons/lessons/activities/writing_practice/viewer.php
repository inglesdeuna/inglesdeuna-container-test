<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id'])        ? trim((string) $_GET['id'])        : '';
$unit       = isset($_GET['unit'])      ? trim((string) $_GET['unit'])      : '';
$returnTo   = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';

if ($activityId === '' && $unit === '') {
    die('Activity not specified');
}

/* ──────────────────────────────────────────────────────────────
   DATA LAYER
────────────────────────────────────────────────────────────── */

function wpv_resolve_unit(PDO $pdo, string $activityId): string
{
    if ($activityId === '') {
        return '';
    }
    $stmt = $pdo->prepare("SELECT unit_id FROM activities WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return ($row && isset($row['unit_id'])) ? (string) $row['unit_id'] : '';
}

function wpv_normalize_payload($rawData): array
{
    $default = [
        'title'       => 'Writing Practice',
        'description' => 'Read each prompt carefully and write your response.',
        'questions'   => [],
    ];

    if ($rawData === null || $rawData === '') {
        return $default;
    }

    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;
    if (!is_array($decoded)) {
        return $default;
    }

    $allowedTypes = ['writing', 'listen_write', 'fill_sentence', 'fill_paragraph', 'video_writing'];
    $questions    = [];

    foreach ((array) ($decoded['questions'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }

        $type = in_array($item['type'] ?? '', $allowedTypes, true) ? (string) $item['type'] : 'writing';

        $rawAnswers     = $item['correct_answers'] ?? [];
        $correctAnswers = [];
        if (is_array($rawAnswers)) {
            foreach ($rawAnswers as $ans) {
                $a = trim((string) $ans);
                if ($a !== '') {
                    $correctAnswers[] = $a;
                }
            }
        }

        $questions[] = [
            'id'              => trim((string) ($item['id']          ?? uniqid('wp_'))),
            'type'            => $type,
            'question'        => trim((string) ($item['question']    ?? '')),
            'instruction'     => trim((string) ($item['instruction'] ?? '')),
            'placeholder'     => trim((string) ($item['placeholder'] ?? 'Write your answer here...')),
            'media'           => trim((string) ($item['media']       ?? '')),
            'correct_answers' => $correctAnswers,
            'points'          => 1,
        ];
    }

    return [
        'title'       => trim((string) ($decoded['title']       ?? '')) ?: $default['title'],
        'description' => trim((string) ($decoded['description'] ?? '')) ?: $default['description'],
        'questions'   => $questions,
    ];
}

function wpv_load_activity(PDO $pdo, string $activityId, string $unit): array
{
    $fallback = ['id' => '', 'title' => 'Writing Practice', 'description' => '', 'questions' => []];
    $row      = null;

    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE id = :id AND type = 'writing_practice' LIMIT 1");
        $stmt->execute(['id' => $activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row && $unit !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE unit_id = :unit AND type = 'writing_practice' ORDER BY id ASC LIMIT 1");
        $stmt->execute(['unit' => $unit]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$row) {
        return $fallback;
    }

    $payload = wpv_normalize_payload($row['data'] ?? null);
    return [
        'id'          => (string) ($row['id'] ?? ''),
        'title'       => $payload['title'],
        'description' => $payload['description'],
        'questions'   => $payload['questions'],
    ];
}

/* ──────────────────────────────────────────────────────────────
   BOOTSTRAP
────────────────────────────────────────────────────────────── */

if ($unit === '' && $activityId !== '') {
    $unit = wpv_resolve_unit($pdo, $activityId);
}

if ($returnTo === '') {
    $returnTo = '../../academic/teacher_course.php?assignment='
        . urlencode((string) ($_GET['assignment'] ?? ''))
        . '&unit=' . urlencode($unit)
        . '&step=9999';
}

$activity    = wpv_load_activity($pdo, $activityId, $unit);
$viewerTitle = (string) ($activity['title']       ?? 'Writing Practice');
$description = (string) ($activity['description'] ?? '');
$questions   = (array)  ($activity['questions']   ?? []);

/* video layout mode: all questions are video_writing */
$isVideoMode = !empty($questions) && count(array_filter(
    $questions, function ($q) { return ($q['type'] ?? '') === 'video_writing'; }
)) === count($questions);

/* grab first video URL for the fixed video banner */
$videoMediaUrl = '';
foreach ($questions as $q) {
    if (!empty($q['media'])) { $videoMediaUrl = (string) $q['media']; break; }
}

ob_start();
$cssVer = file_exists(__DIR__ . '/../multiple_choice/multiple_choice.css')
    ? (string) filemtime(__DIR__ . '/../multiple_choice/multiple_choice.css')
    : (string) time();
?>
<style>
/* ── Writing Practice Viewer – card-by-card mode ─────────── */
.wp-q-sentence {
    background: #f0f6ff;
    border: 1px solid #bfdbfe;
    border-radius: 12px;
    padding: 12px 16px;
    font-size: 17px;
    margin-bottom: 12px;
    line-height: 1.7;
    color: #1e3a5f;
    font-weight: 700;
    text-align: center;
}
.wp-blank {
    display: inline-block;
    min-width: 80px;
    border-bottom: 2px solid #a855c8;
    color: #a855c8;
    font-weight: 800;
    text-align: center;
    padding: 0 4px;
}
.wp-video-wrap {
    margin-bottom: 14px;
    border-radius: 12px;
    overflow: hidden;
    background: #000;
    width: 100%;
}
.wp-video-wrap video {
    display: block;
    width: 100%;
    max-height: 400px;
    border-radius: 12px;
}
.wp-video-wrap-iframe {
    position: relative;
    margin-bottom: 14px;
    border-radius: 12px;
    overflow: hidden;
    background: #000;
    aspect-ratio: 16 / 9;
    max-height: 360px;
    width: 100%;
}
.wp-video-wrap-iframe iframe {
    position: absolute; top: 0; left: 0;
    width: 100%; height: 100%; border: none;
}
.wp-audio-wrap { margin-bottom: 12px; text-align: center; }
.wp-audio-wrap audio { width: 100%; max-width: 500px; border-radius: 10px; outline: none; }
.wp-lw-player { display:flex; flex-direction:column; align-items:center; gap:10px; margin-bottom:14px; width:100%; }
.wp-lw-btn-row { display:flex; gap:10px; justify-content:center; flex-wrap:wrap; }
.wp-lw-pause-btn  { background:linear-gradient(180deg,#f59e0b,#d97706) !important; color:#fff !important; }
.wp-lw-replay-btn { background:linear-gradient(180deg,#94a3b8,#64748b) !important; color:#fff !important; }
.mc-btn-prev { background: linear-gradient(180deg, #f97316 0%, #c2410c 100%); }
.mc-btn-listen-wp { background: linear-gradient(180deg, #38bdf8 0%, #0ea5e9 100%); }
.wp-open-note {
    font-size: 13px; color: #7c3aed; font-weight: 700;
    background: #f5f3ff; border: 1px solid #ddd6fe;
    border-radius: 8px; padding: 8px 12px; margin-bottom: 10px;
    text-align: center;
}
#wpViewer { width: 100%; max-width: 100%; }
#wpCard { display: flex; flex-direction: column; align-items: center; justify-content: flex-start; }
.completed-screen { display: none; text-align: center; max-width: 600px; margin: 0 auto; padding: 40px 20px; }
.completed-screen.active { display: block; }
.completed-icon  { font-size: 80px; margin-bottom: 20px; }
.completed-title { font-family: 'Fredoka','Trebuchet MS',sans-serif; font-size: 36px; font-weight: 700; color: #a855c8; margin: 0 0 16px; line-height: 1.2; }
.completed-text  { font-size: 16px; color: #1f2937; line-height: 1.6; margin: 0 0 10px; }
.completed-button {
    display: inline-block; padding: 12px 24px; border: none; border-radius: 999px;
    background: linear-gradient(180deg, #a855f7 0%, #7c3aed 100%);
    color: #fff; font-weight: 700; font-size: 16px; cursor: pointer;
    box-shadow: 0 10px 24px rgba(0,0,0,.14); transition: transform .18s, filter .18s;
    margin-top: 14px;
}
.completed-button:hover { transform: scale(1.05); filter: brightness(1.07); }

/* answer reveal + answer box – shared across both modes */
.dict-answer-reveal { display: none; font-size: 14px; color: #7c3aed; font-weight: 700;
                      background: #f5f3ff; border: 1px solid #ddd6fe; border-radius: 8px;
                      padding: 8px 12px; margin-top: 6px; }
.dict-answer-reveal.show { display: block; }
.dict-answer-box { display: block; width: 100%; padding: 10px 12px;
                   border: 2px solid #cbd5e1; border-radius: 10px; font-size: 15px;
                   font-family: inherit; resize: vertical; box-sizing: border-box;
                   transition: border-color .2s, background .2s; }
.dict-answer-box:focus { outline: none; border-color: #a855f7;
                         box-shadow: 0 0 0 3px rgba(168,85,247,.15); }
.dict-answer-box.ok  { border-color: #22c55e !important; background: #f0fdf4 !important; }
.dict-answer-box.bad { border-color: #ef4444 !important; background: #fef2f2 !important; }
/* override Show Answer button to purple in card-by-card mode */
.mc-btn-show { background: linear-gradient(180deg, #a855f7 0%, #7c3aed 100%) !important; }

/* ── Inline fill inputs ─────────────────────────────── */
.wp-fill-sentence-box,
.wp-fill-paragraph-box {
    background: #f0f6ff;
    border: 1px solid #bfdbfe;
    border-radius: 14px;
    padding: 14px 22px;
    font-size: clamp(16px, 2vw, 21px);
    line-height: 2.8;
    color: #1e3a5f;
    font-weight: 600;
    margin-bottom: 10px;
    text-align: center;
    word-break: break-word;
}
.wp-fill-paragraph-box {
    text-align: left;
    font-size: clamp(15px, 1.8vw, 18px);
    line-height: 2.6;
    white-space: pre-wrap;
}
.wp-fill-input {
    display: inline-block;
    min-width: 60px;
    border: none;
    border-bottom: 2.5px solid #a78bfa;
    background: transparent;
    color: #5b21b6;
    font-weight: 700;
    font-size: inherit;
    font-family: inherit;
    padding: 1px 8px 3px;
    text-align: center;
    outline: none;
    border-radius: 4px 4px 0 0;
    vertical-align: middle;
    transition: border-color .2s, background .2s, color .2s;
    margin: 0 4px;
}
.wp-fill-input:focus {
    border-bottom-color: #7c3aed;
    background: rgba(167,139,250,.1);
}
.wp-fill-input.ok  { border-bottom-color: #22c55e; background: rgba(34,197,94,.07);  color: #166534; }
.wp-fill-input.bad { border-bottom-color: #ef4444; background: rgba(239,68,68,.07);   color: #dc2626; }

/* ── Video Layout mode ───────────────────────────────────── */
.wpvl-wrap        { max-width: 1200px; margin: 0 auto; font-family: 'Nunito','Segoe UI',sans-serif; }
.wpvl-qs          { display: flex; flex-direction: column; gap: 14px; margin-bottom: 18px; }
.wpvl-card        { background: #fff; border: 1px solid #e9d5ff; border-radius: 14px;
                    padding: 16px 18px; box-shadow: 0 6px 16px rgba(15,23,42,.05); position: relative; overflow: hidden; }
.wpvl-card::before{ content:''; position:absolute; top:0; left:0; right:0; height:5px;
                    background: linear-gradient(90deg,#a855f7,#7c3aed); }
.wpvl-q-num       { font-size: 11px; font-weight: 800; color: #7c3aed; text-transform: uppercase;
                    letter-spacing: .06em; margin-bottom: 6px; }
.wpvl-q-text      { font-weight: 800; color: #f14902; font-size: clamp(15px,2vw,19px);
                    margin: 0 0 10px; line-height: 1.4; }
.wpvl-q-instr     { font-size: 13px; color: #7c3aed; font-weight: 700; margin: 0 0 10px; }
.wpvl-answer      { width: 100%; min-height: 80px; resize: vertical; box-sizing: border-box; }
.wpvl-reveal      { margin-top: 8px; }
.wpvl-controls    { display: flex; justify-content: center; margin-bottom: 16px; }
.wpvl-btn-submit  { background: linear-gradient(180deg,#a855f7,#7c3aed); color:#fff; border:none;
                    border-radius: 999px; padding: 12px 32px; font-size: 15px; font-weight: 800;
                    cursor: pointer; box-shadow: 0 10px 22px rgba(124,58,237,.3);
                    transition: transform .15s, filter .15s; font-family: inherit; }
.wpvl-btn-submit:hover  { filter: brightness(1.08); transform: translateY(-2px); }
.wpvl-btn-submit:disabled { opacity: .5; cursor: default; transform: none; }
.wpvl-card-footer { display: flex; align-items: center; gap: 10px; margin-top: 8px; flex-wrap: wrap; }
.wpvl-btn-show  { background: linear-gradient(180deg, #a855f7, #7c3aed); color: #fff; border: none;
                  border-radius: 999px; padding: 6px 16px; font-size: 13px; font-weight: 800;
                  cursor: pointer; font-family: inherit; box-shadow: 0 4px 12px rgba(124,58,237,.2);
                  transition: filter .15s, transform .15s; }
.wpvl-btn-show:hover  { filter: brightness(1.08); transform: translateY(-1px); }
.wpvl-btn-show:disabled { opacity: .38; cursor: default; filter: none; transform: none; }

/* ─────────────────── PRESENTATION MODE ──────────────────── */
body.presentation-mode .wp-viewer-wrap,
body.presentation-mode .mc-viewer {
    max-width: 100% !important;
    width: 100% !important;
    height: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
    display: flex !important;
    flex-direction: column !important;
}

/* Video-writing two-column in presentation mode handled by video-two-col.css */
body.presentation-mode .wpvl-wrap.vtc-layout {
    max-width: 100% !important;
    margin: 0 !important;
}

body.presentation-mode .wpvl-q-text {
    font-size: clamp(22px, 2.5vw, 32px) !important;
    margin-bottom: 16px !important;
}

body.presentation-mode .wpvl-answer {
    font-size: 16px !important;
    min-height: 60px !important;
    padding: 12px !important;
}

body.presentation-mode .vtc-content-col .wpvl-controls {
    flex-shrink: 0 !important;
    padding: 12px 16px !important;
    background: #f8fbff !important;
    border-top: 1px solid #e5e7eb !important;
}

body.presentation-mode .mc-card {
    padding: 0 !important;
    flex: 1 !important;
    overflow-y: auto !important;
    background: #fff !important;
}

body.presentation-mode .mc-controls {
    flex-shrink: 0 !important;
    padding: 12px 16px !important;
    background: #f8fbff !important;
    border-top: 1px solid #e5e7eb !important;
}
</style>

<?php if (empty($questions)): ?>
    <p style="padding:20px;color:#b8551f;font-weight:700;">This activity has no questions yet. Open the editor to configure it.</p>
<?php elseif ($isVideoMode): ?>

<!-- ═══════ VIDEO LAYOUT MODE ═══════ -->
<link rel="stylesheet" href="../multiple_choice/multiple_choice.css?v=<?= urlencode($cssVer) ?>">

<div class="wpvl-wrap vtc-layout" id="wpvlWrap">

    <!-- ── LEFT: video ── -->
    <div class="vtc-video-col">
    <?php
    $isCloudinaryOrMp4 = $videoMediaUrl !== '' && (
        preg_match('/\.(mp4|webm|ogg)(\?|$)/i', $videoMediaUrl) ||
        preg_match('/cloudinary\.com\/.+\/video\//i', $videoMediaUrl)
    );
    $ytMatch = [];
    $isYoutube = !$isCloudinaryOrMp4 && $videoMediaUrl !== '' && (
        preg_match('/youtu\.be\/([A-Za-z0-9_\-]{11})/', $videoMediaUrl, $ytMatch) ||
        preg_match('/youtube\.com\/watch\?(?:.*&)?v=([A-Za-z0-9_\-]{11})/', $videoMediaUrl, $ytMatch) ||
        preg_match('/youtube\.com\/embed\/([A-Za-z0-9_\-]+)/', $videoMediaUrl, $ytMatch)
    );
    $embedUrl = $isYoutube ? 'https://www.youtube-nocookie.com/embed/' . $ytMatch[1] : $videoMediaUrl;
    ?>

    <?php if ($isCloudinaryOrMp4 && $videoMediaUrl !== ''): ?>
        <div class="vtc-video-box">
            <video controls preload="metadata">
                <source src="<?= htmlspecialchars($videoMediaUrl, ENT_QUOTES, 'UTF-8') ?>">
            </video>
        </div>
    <?php elseif ($videoMediaUrl !== ''): ?>
        <div class="vtc-video-box is-iframe">
            <iframe src="<?= htmlspecialchars($embedUrl, ENT_QUOTES, 'UTF-8') ?>"
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                    allowfullscreen loading="lazy"></iframe>
        </div>
    <?php endif; ?>
    </div><!-- /.vtc-video-col -->

    <!-- ── RIGHT: questions + controls ── -->
    <div class="vtc-content-col">
    <div class="wpvl-qs" id="wpvlQs">
        <?php foreach ($questions as $i => $q): ?>
            <?php $qText = htmlspecialchars((string)($q['question'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
            <div class="wpvl-card" id="wpvlCard<?= $i ?>">
                <div class="wpvl-q-num">Question <?= $i + 1 ?></div>
                <?php if ($qText !== ''): ?>
                    <p class="wpvl-q-text"><?= $qText ?></p>
                <?php endif; ?>
                <?php if (!empty($q['instruction'])): ?>
                    <p class="wpvl-q-instr"><?= htmlspecialchars((string)$q['instruction'], ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
                <textarea class="dict-answer-box wpvl-answer" id="wpvlAns<?= $i ?>"
                          placeholder="Write your answer here…"></textarea>
                <div class="wpvl-card-footer">
                    <?php if (!empty($q['correct_answers'])): ?>
                    <button type="button" class="wpvl-btn-show" id="wpvlShow<?= $i ?>"
                            onclick="wpvlShowAnswer(<?= $i ?>)" disabled>👁 Show Answer</button>
                    <?php endif; ?>
                    <div class="mc-feedback" id="wpvlFb<?= $i ?>"></div>
                </div>
                <div class="dict-answer-reveal wpvl-reveal" id="wpvlReveal<?= $i ?>"></div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- ── submit ── -->
    <div class="wpvl-controls">
        <button type="button" class="wpvl-btn-submit" id="wpvlSubmit">✔ Check Answers</button>
    </div>

    <!-- ── completed ── -->
    <div id="wpvlCompleted" class="completed-screen">
        <div class="completed-icon">✍️</div>
        <h2 class="completed-title" id="wpvlCompTitle"></h2>
        <p class="completed-text" id="wpvlCompText"></p>
        <p class="completed-text" id="wpvlScoreText" style="font-weight:800;font-size:20px;color:#a855c8;"></p>
        <p class="completed-text" id="wpvlOpenNote" style="display:none;color:#7c3aed;font-size:14px;"></p>
        <button type="button" class="completed-button" id="wpvlRestart">Restart</button>
    </div>
    </div><!-- /.vtc-content-col -->
</div><!-- /.wpvl-wrap vtc-layout -->

<script>
window.WP_DATA        = <?= json_encode(array_values($questions), JSON_UNESCAPED_UNICODE) ?>;
window.WP_RETURN_TO   = <?= json_encode($returnTo, JSON_UNESCAPED_UNICODE) ?>;
window.WP_ACTIVITY_ID = <?= json_encode((string) ($activity['id'] ?? ''), JSON_UNESCAPED_UNICODE) ?>;
window.WP_UNIT_ID     = <?= json_encode($unit, JSON_UNESCAPED_UNICODE) ?>;
window.WP_ASSIGNMENT_ID = <?= json_encode((string) ($_GET['assignment'] ?? ''), JSON_UNESCAPED_UNICODE) ?>;
window.WP_TITLE       = <?= json_encode($viewerTitle, JSON_UNESCAPED_UNICODE) ?>;
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    'use strict';
    var questions   = Array.isArray(window.WP_DATA) ? window.WP_DATA : [];
    var returnTo    = String(window.WP_RETURN_TO   || '');
    var activityId  = String(window.WP_ACTIVITY_ID || '');
    var unitId      = String(window.WP_UNIT_ID     || '');
    var assignId    = String(window.WP_ASSIGNMENT_ID || '');
    var actTitle    = String(window.WP_TITLE       || 'Writing Practice');
    if (!questions.length) { return; }

    var wrapEl      = document.getElementById('wpvlWrap');
    var qsEl        = document.getElementById('wpvlQs');
    var submitBtn   = document.getElementById('wpvlSubmit');
    var completedEl = document.getElementById('wpvlCompleted');
    var compTitleEl = document.getElementById('wpvlCompTitle');
    var compTextEl  = document.getElementById('wpvlCompText');
    var scoreTextEl = document.getElementById('wpvlScoreText');
    var openNoteEl  = document.getElementById('wpvlOpenNote');
    var restartBtn  = document.getElementById('wpvlRestart');

    var sndOk   = new Audio('../../hangman/assets/win.mp3');
    var sndBad  = new Audio('../../hangman/assets/lose.mp3');
    var sndDone = new Audio('../../hangman/assets/win (1).mp3');
    function playSound(s) { try { s.pause(); s.currentTime = 0; s.play(); } catch (e) {} }

    function normalize(s) {
        return String(s || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .trim().toLowerCase().replace(/[.,;:!?'"()]/g, '').replace(/\s+/g, ' ');
    }
    function checkCorrect(val, answers) {
        if (!Array.isArray(answers) || answers.length === 0) { return false; }
        var u = normalize(val);
        return answers.some(function (a) { return normalize(a) === u; });
    }
    function isAutoGraded(q) {
        return Array.isArray(q.correct_answers) && q.correct_answers.length > 0;
    }
    function persistScore(url) {
        if (!url) { return Promise.resolve(false); }
        return fetch(url, { method: 'GET', credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return !!(r && r.ok); }).catch(function () { return false; });
    }
    function navigateTo(url) {
        if (!url) { return; }
        try { if (window.top && window.top !== window.self) { window.top.location.href = url; return; } } catch (e) {}
        window.location.href = url;
    }

    var submitted = false;
    var shownCards = {}; /* tracks which cards had Show Answer clicked */

    function wpvlShowAnswer(i) {
        var q = questions[i];
        if (!q || !Array.isArray(q.correct_answers) || q.correct_answers.length === 0) { return; }
        var shown   = q.correct_answers.slice(0, 2).join(' / ');
        var ansEl   = document.getElementById('wpvlAns'    + i);
        var fbEl    = document.getElementById('wpvlFb'     + i);
        var revealEl= document.getElementById('wpvlReveal' + i);
        var showBtn = document.getElementById('wpvlShow'   + i);

        /* lock card as wrong immediately */
        if (!shownCards[i]) {
            shownCards[i] = true;
            if (ansEl)  { ansEl.disabled = true; ansEl.className = 'dict-answer-box wpvl-answer bad'; }
            if (fbEl)   { fbEl.textContent = '\u2718 Wrong'; fbEl.className = 'mc-feedback bad'; }
            playSound(sndBad);
        }
        if (showBtn) { showBtn.disabled = true; }

        var wrote = ansEl ? ansEl.value.trim() : '';
        if (revealEl) {
            revealEl.textContent = wrote !== ''
                ? 'You wrote: "' + wrote + '" \u2192 Correct: ' + shown
                : 'Correct: ' + shown;
            revealEl.classList.add('show');
        }
    }

    async function handleSubmit() {
        if (submitted) { return; }
        submitted = true;
        submitBtn.disabled = true;

        var correctCount  = 0;
        var openResponses = [];

        questions.forEach(function (q, i) {
            var ansEl    = document.getElementById('wpvlAns'    + i);
            var fbEl     = document.getElementById('wpvlFb'     + i);
            var revealEl = document.getElementById('wpvlReveal' + i);
            var showBtn  = document.getElementById('wpvlShow'   + i);
            var val      = ansEl ? ansEl.value.trim() : '';

            if (ansEl)    { ansEl.disabled = true; }
            if (showBtn)  { showBtn.disabled = true; }

            /* if Show Answer was already clicked, card is already locked as wrong */
            if (shownCards[i]) {
                /* already counted as wrong — do nothing extra */
                if (fbEl && !fbEl.textContent) { fbEl.textContent = '\u2718 Wrong'; fbEl.className = 'mc-feedback bad'; }
                return;
            }

            if (isAutoGraded(q)) {
                var correct = checkCorrect(val, q.correct_answers);
                if (correct) {
                    correctCount++;
                    if (ansEl)  { ansEl.className  = 'dict-answer-box wpvl-answer ok'; }
                    if (fbEl)   { fbEl.textContent = '\u2714 Right'; fbEl.className = 'mc-feedback good'; }
                    playSound(sndOk);
                } else {
                    if (ansEl)  { ansEl.className  = 'dict-answer-box wpvl-answer bad'; }
                    if (fbEl)   { fbEl.textContent = '\u2718 Wrong'; fbEl.className = 'mc-feedback bad'; }
                    var shown = (q.correct_answers || []).slice(0, 2).join(' / ');
                    if (revealEl) { revealEl.textContent = 'Correct: ' + shown; revealEl.classList.add('show'); }
                    playSound(sndBad);
                }
            } else {
                /* open writing: counts as completed */
                correctCount++;
                if (ansEl) { ansEl.className = 'dict-answer-box wpvl-answer ok'; }
                if (fbEl)  { fbEl.textContent = '\u2714 Submitted for review'; fbEl.className = 'mc-feedback good'; }
                if (val !== '') {
                    openResponses.push({ question_id: String(q.id || i), question_text: String(q.question || ''),
                                         response_text: val, max_points: 1 });
                }
            }
        });

        var totalCount = questions.length;
        var pct    = totalCount > 0 ? Math.round((correctCount / totalCount) * 100) : 0;
        var errors = Math.max(0, totalCount - correctCount);

        /* count words from open responses */
        var totalWords = 0;
        openResponses.forEach(function (r) {
            var t = String(r.response_text || '').replace(/\s+/g, ' ').trim();
            if (t) { totalWords += t.split(' ').length; }
        });

        /* save open-writing responses */
        if (openResponses.length > 0) {
            try {
                var fd = new FormData();
                fd.append('activity_id', activityId); fd.append('unit_id', unitId);
                fd.append('assignment_id', assignId); fd.append('responses', JSON.stringify(openResponses));
                await fetch('/lessons/lessons/activities/writing_practice/wp_save_response.php',
                            { method: 'POST', body: fd });
            } catch (e) {}
        }

        /* show completed */
        playSound(sndDone);
        if (qsEl)        { qsEl.style.marginBottom = '0'; }
        submitBtn.style.display = 'none';
        completedEl.classList.add('active');
        if (compTitleEl) { compTitleEl.textContent = actTitle; }
        if (compTextEl)  { compTextEl.textContent  = "You've completed " + actTitle + ". Great job!"; }
        if (scoreTextEl) { scoreTextEl.textContent = 'Score: ' + correctCount + ' / ' + totalCount + ' (' + pct + '%)'; }
        if (openNoteEl) {
            openNoteEl.style.display = '';
            var noteHtml = '';
            if (totalWords > 0) { noteHtml += '\uD83D\uDCCA ' + totalWords + ' palabras escritas'; }
            if (openResponses.length > 0) {
                if (noteHtml) { noteHtml += ' &nbsp;&middot;&nbsp; '; }
                noteHtml += '\u270D\uFE0F ' + openResponses.length + ' respuesta(s) enviadas para calificaci\u00F3n.';
            }
            if (noteHtml) { openNoteEl.innerHTML = noteHtml; } else { openNoteEl.style.display = 'none'; }
        }

        /* persist to return URL */
        if (returnTo) {
            var joiner  = returnTo.indexOf('?') !== -1 ? '&' : '?';
            var saveUrl = returnTo + joiner
                + 'activity_percent=' + encodeURIComponent(String(pct))
                + '&activity_errors='  + encodeURIComponent(String(errors))
                + '&activity_total='   + encodeURIComponent(String(totalCount))
                + '&activity_id='      + encodeURIComponent(activityId)
                + '&activity_type=writing_practice';
            var ok = await persistScore(saveUrl);
            if (!ok) { navigateTo(saveUrl); }
        }
    }

    submitBtn.addEventListener('click', handleSubmit);

    /* enable Show Answer per-card only after user has typed something */
    questions.forEach(function (q, i) {
        if (!Array.isArray(q.correct_answers) || q.correct_answers.length === 0) { return; }
        var ansEl   = document.getElementById('wpvlAns'  + i);
        var showBtn = document.getElementById('wpvlShow' + i);
        if (!ansEl || !showBtn) { return; }
        ansEl.addEventListener('input', function () {
            if (!shownCards[i] && !submitted) {
                showBtn.disabled = ansEl.value.trim() === '';
            }
        });
    });

    restartBtn.addEventListener('click', function () {
        submitted = false;
        submitBtn.disabled = false;
        submitBtn.style.display = '';
        completedEl.classList.remove('active');
        shownCards = {};
        questions.forEach(function (q, i) {
            var ansEl    = document.getElementById('wpvlAns'    + i);
            var fbEl     = document.getElementById('wpvlFb'     + i);
            var revealEl = document.getElementById('wpvlReveal' + i);
            var showBtn  = document.getElementById('wpvlShow'   + i);
            if (ansEl)    { ansEl.value = ''; ansEl.disabled = false; ansEl.className = 'dict-answer-box wpvl-answer'; }
            if (fbEl)     { fbEl.textContent = ''; fbEl.className = 'mc-feedback'; }
            if (revealEl) { revealEl.textContent = ''; revealEl.classList.remove('show'); }
            if (showBtn)  { showBtn.disabled = true; } /* re-disable: needs typing again to enable */
        });
        // Don't scroll in presentation mode
        if (!window.PRESENTATION_MODE) {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    });
});
</script>

<?php else: ?>

<!-- ═══════ CARD-BY-CARD MODE (existing) ═══════ -->

<link rel="stylesheet" href="../multiple_choice/multiple_choice.css?v=<?= urlencode($cssVer) ?>">

<div class="mc-viewer" id="wpViewer">
    <div class="mc-status" id="wpStatus"></div>

    <div class="mc-card" id="wpCard">
        <!-- media injected by JS -->
        <div id="wpMediaArea"></div>
        <!-- question text injected by JS -->
        <div id="wpQtext"></div>
        <!-- instruction injected by JS -->
        <div id="wpInstruction"></div>
        <!-- answer input -->
        <textarea id="wpAnswer" class="dict-answer-box" style="width:100%;max-width:620px;" placeholder="Write your answer here..."></textarea>
        <!-- answer reveal -->
        <div id="wpReveal" class="dict-answer-reveal"></div>
    </div>

    <div class="mc-controls" id="wpControls">
        <button type="button" class="mc-btn mc-btn-prev" id="btnPrev">Prev</button>
        <button type="button" class="mc-btn mc-btn-show" id="btnShow">Show Answer</button>
        <button type="button" class="mc-btn mc-btn-next" id="btnNext">Next</button>
    </div>

    <div class="mc-feedback" id="wpFeedback"></div>

    <div id="wpCompleted" class="completed-screen">
        <div class="completed-icon">✍️</div>
        <h2 class="completed-title" id="wpCompTitle"></h2>
        <p class="completed-text" id="wpCompText"></p>
        <p class="completed-text" id="wpScoreText" style="font-weight:800;font-size:20px;color:#a855c8;"></p>
        <p class="completed-text" id="wpOpenNote" style="display:none;color:#7c3aed;font-size:14px;"></p>
        <button type="button" class="completed-button" id="btnRestart">Restart</button>
    </div>
</div>

<!-- PHP → JS data bridge -->
<script>
window.WP_DATA        = <?= json_encode(array_values($questions), JSON_UNESCAPED_UNICODE) ?>;
window.WP_RETURN_TO   = <?= json_encode($returnTo, JSON_UNESCAPED_UNICODE) ?>;
window.WP_ACTIVITY_ID = <?= json_encode((string) ($activity['id'] ?? ''), JSON_UNESCAPED_UNICODE) ?>;
window.WP_UNIT_ID     = <?= json_encode($unit, JSON_UNESCAPED_UNICODE) ?>;
window.WP_ASSIGNMENT_ID = <?= json_encode((string) ($_GET['assignment'] ?? ''), JSON_UNESCAPED_UNICODE) ?>;
window.WP_TITLE       = <?= json_encode($viewerTitle, JSON_UNESCAPED_UNICODE) ?>;
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    /* ── data ─────────────────────────────────────────── */
    var questions   = Array.isArray(window.WP_DATA) ? window.WP_DATA : [];
    var returnTo    = String(window.WP_RETURN_TO   || '');
    var activityId  = String(window.WP_ACTIVITY_ID || '');
    var unitId      = String(window.WP_UNIT_ID     || '');
    var assignId    = String(window.WP_ASSIGNMENT_ID || '');
    var actTitle    = String(window.WP_TITLE       || 'Writing Practice');

    if (!questions.length) { return; }

    /* ── elements ─────────────────────────────────────── */
    var statusEl    = document.getElementById('wpStatus');
    var mediaArea   = document.getElementById('wpMediaArea');
    var qtextEl     = document.getElementById('wpQtext');
    var instrEl     = document.getElementById('wpInstruction');
    var answerEl    = document.getElementById('wpAnswer');
    var revealEl    = document.getElementById('wpReveal');
    var feedbackEl  = document.getElementById('wpFeedback');
    var cardEl      = document.getElementById('wpCard');
    var controlsEl  = document.getElementById('wpControls');
    var completedEl = document.getElementById('wpCompleted');
    var compTitleEl = document.getElementById('wpCompTitle');
    var compTextEl  = document.getElementById('wpCompText');
    var scoreTextEl = document.getElementById('wpScoreText');
    var openNoteEl  = document.getElementById('wpOpenNote');
    var btnPrev     = document.getElementById('btnPrev');
    var btnShow     = document.getElementById('btnShow');
    var btnNext     = document.getElementById('btnNext');
    var btnRestart  = document.getElementById('btnRestart');

    /* ── sounds ───────────────────────────────────────── */
    var sndOk   = new Audio('../../hangman/assets/win.mp3');
    var sndBad  = new Audio('../../hangman/assets/lose.mp3');
    var sndDone = new Audio('../../hangman/assets/win (1).mp3');

    function playSound(s) { try { s.pause(); s.currentTime = 0; s.play(); } catch (e) {} }

    /* ── state ────────────────────────────────────────── */
    var index             = 0;
    var finished          = false;
    var checkedCards      = {};   // index → true when locked
    var attemptsMap       = {};   // index → attempt count
    var correctCount      = 0;    // correct (auto-graded correct + open-writing submitted)
    var openResponses     = [];   // collected writing responses
    var currentFillInputs = [];   // inline <input> elements for fill_sentence / fill_paragraph

    /* ── helpers ──────────────────────────────────────── */
    function isAutoGraded(q) {
        return String(q.type || 'writing') !== 'writing';
    }

    function normalize(s) {
        return String(s || '')
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .trim().toLowerCase()
            .replace(/[.,;:!?'"()]/g, '')
            .replace(/\s+/g, ' ');
    }

    function checkCorrect(userVal, answers) {
        if (!Array.isArray(answers) || answers.length === 0) { return false; }
        var u = normalize(userVal);
        return answers.some(function (a) { return normalize(a) === u; });
    }

    function toEmbedUrl(url) {
        if (!url) { return ''; }
        if (/youtube\.com\/embed\/|player\.vimeo\.com\/video\//.test(url)) { return url; }
        var m = url.match(/youtu\.be\/([A-Za-z0-9_-]{11})/);
        if (m) { return 'https://www.youtube-nocookie.com/embed/' + m[1]; }
        var m2 = url.match(/youtube\.com\/watch\?(?:.*&)?v=([A-Za-z0-9_-]{11})/);
        if (m2) { return 'https://www.youtube-nocookie.com/embed/' + m2[1]; }
        return url;
    }

    function esc(s) {
        return String(s || '')
            .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    var PLACEHOLDERS = {
        writing:        'Write your response here\u2026',
        listen_write:   'Write what you hear\u2026',
        fill_sentence:  'Complete the sentence\u2026',
        fill_paragraph: 'Complete the paragraph\u2026',
        video_writing:  'Write about what you saw\u2026',
    };

    /* ── createFillInput helper ───────────────────────── */
    function createFillInput(blankIdx, q) {
        var answers     = q.correct_answers || [];
        var expectedAns = answers[blankIdx] ? String(answers[blankIdx]) : '';
        var inp = document.createElement('input');
        inp.type = 'text';
        inp.className = 'wp-fill-input';
        inp.setAttribute('autocomplete',   'off');
        inp.setAttribute('autocorrect',    'off');
        inp.setAttribute('autocapitalize', 'off');
        inp.setAttribute('spellcheck',     'false');
        inp.placeholder = '…';
        inp.style.width = Math.max(60, (expectedAns.length || 7) * 11 + 20) + 'px';
        inp.addEventListener('input', function () {
            if (!checkedCards[index] && !finished) {
                var anyFilled = currentFillInputs.some(function (fi) { return fi.value.trim() !== ''; });
                btnShow.disabled = !anyFilled;
            }
        });
        inp.addEventListener('blur',    function ()  { autoCheck(); });
        inp.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); autoCheck(); }
        });
        return inp;
    }

    /* ── loadCard ─────────────────────────────────────── */
    function loadCard() {
        var q    = questions[index];
        var type = String(q.type || 'writing');

        // Stop any active listen_write audio or TTS from previous card
        if (window.wpLwAudio) { window.wpLwAudio.pause(); window.wpLwAudio = null; }
        if (window.speechSynthesis) { speechSynthesis.cancel(); }

        finished = false;
        completedEl.classList.remove('active');
        cardEl.style.display    = '';
        controlsEl.style.display = '';

        /* status */
        statusEl.textContent = (index + 1) + ' / ' + questions.length;

        /* clear previous content */
        currentFillInputs     = [];
        mediaArea.innerHTML   = '';
        qtextEl.innerHTML     = '';
        instrEl.innerHTML     = '';
        answerEl.value        = '';
        answerEl.style.display = '';
        answerEl.className    = 'dict-answer-box';
        answerEl.disabled     = false;
        answerEl.placeholder  = PLACEHOLDERS[type] || PLACEHOLDERS.writing;
        feedbackEl.textContent = '';
        feedbackEl.className   = 'mc-feedback';
        revealEl.classList.remove('show');
        revealEl.textContent   = '';

        /* ── open-writing notice ── */
        if (type === 'writing') {
            var note = document.createElement('div');
            note.className   = 'wp-open-note';
            note.textContent = '\u270D\uFE0F Open Writing \u2014 your response will be submitted for teacher review.';
            mediaArea.appendChild(note);
        }

        /* ── listen_write: MP3/TTS player + optional fill-in blanks ── */
        if (type === 'listen_write') {
            var lwWrap = document.createElement('div');
            lwWrap.className = 'wp-lw-player';

            var lwNote = document.createElement('div');
            lwNote.className   = 'wp-open-note';
            lwNote.textContent = '\uD83C\uDFA7 Escucha y completa los espacios en blanco.';
            lwWrap.appendChild(lwNote);

            var lwBtnRow = document.createElement('div');
            lwBtnRow.className = 'wp-lw-btn-row';

            var lwPlay   = document.createElement('button');
            lwPlay.type  = 'button'; lwPlay.className = 'mc-btn mc-btn-listen-wp';
            lwPlay.innerHTML = '\u25B6 Escuchar';

            var lwPause  = document.createElement('button');
            lwPause.type = 'button'; lwPause.className = 'mc-btn wp-lw-pause-btn';
            lwPause.innerHTML = '\u23F8 Pausar'; lwPause.style.display = 'none';

            var lwReplay = document.createElement('button');
            lwReplay.type = 'button'; lwReplay.className = 'mc-btn wp-lw-replay-btn';
            lwReplay.innerHTML = '\u21A9 Repetir'; lwReplay.style.display = 'none';

            function lwSetState(s) {
                lwPlay.style.display   = (s === 'idle' || s === 'paused') ? '' : 'none';
                lwPause.style.display  = (s === 'playing') ? '' : 'none';
                lwReplay.style.display = (s === 'done') ? '' : 'none';
                lwPlay.innerHTML = (s === 'paused') ? '\u25B6 Continuar' : '\u25B6 Escuchar';
            }

            if (q.media) {
                /* — MP3 file — */
                var lwAudio = new Audio(String(q.media));
                lwAudio.preload = 'auto';
                window.wpLwAudio = lwAudio;
                lwPlay.addEventListener('click', function () {
                    lwAudio.play().catch(function () {});
                    lwSetState('playing');
                });
                lwPause.addEventListener('click', function () {
                    lwAudio.pause(); lwSetState('paused');
                });
                lwReplay.addEventListener('click', function () {
                    lwAudio.currentTime = 0;
                    lwAudio.play().catch(function () {});
                    lwSetState('playing');
                });
                lwAudio.addEventListener('ended', function () {
                    window.wpLwAudio = null; lwSetState('done');
                });
            } else if (window.speechSynthesis) {
                /* — TTS fallback — */
                var lwTtsPaused = false;
                function lwSpeak() {
                    var _bi = 0;
                    var text = String(q.question || '').replace(/_{2,}/g, function () {
                        return String((q.correct_answers || [])[_bi++] || '...');
                    });
                    if (!text) { return; }
                    speechSynthesis.cancel(); lwTtsPaused = false;
                    var u = new SpeechSynthesisUtterance(text);
                    u.lang = 'en-US'; u.rate = 0.85;
                    u.onstart = function () { lwSetState('playing'); };
                    u.onend   = function () { lwTtsPaused = false; lwSetState('done'); };
                    u.onerror = function () { lwTtsPaused = false; lwSetState('idle'); };
                    speechSynthesis.speak(u);
                }
                lwPlay.addEventListener('click', function () {
                    if (lwTtsPaused && speechSynthesis.paused) {
                        speechSynthesis.resume(); lwTtsPaused = false; lwSetState('playing'); return;
                    }
                    lwSpeak();
                });
                lwPause.addEventListener('click', function () {
                    if (speechSynthesis.speaking && !speechSynthesis.paused) {
                        speechSynthesis.pause(); lwTtsPaused = true; lwSetState('paused');
                    }
                });
                lwReplay.addEventListener('click', function () { lwSpeak(); });
            }

            lwBtnRow.appendChild(lwPlay);
            lwBtnRow.appendChild(lwPause);
            lwBtnRow.appendChild(lwReplay);
            lwWrap.appendChild(lwBtnRow);
            mediaArea.appendChild(lwWrap);

            /* — fill-in blanks — ALWAYS shown for listen_write — */
            var lwRawText = String(q.question || '');
            answerEl.style.display = 'none';
            var lwFillBox = document.createElement('div');
            lwFillBox.className = 'wp-fill-paragraph-box';
            if (/_{2,}/.test(lwRawText)) {
                /* Explicit ___ markers → embed an input at each blank position */
                lwRawText.split(/_{2,}/).forEach(function (seg, si, arr) {
                    if (seg) {
                        seg.split('\n').forEach(function (line, li) {
                            if (li > 0) { lwFillBox.appendChild(document.createElement('br')); }
                            if (line)   { lwFillBox.appendChild(document.createTextNode(line)); }
                        });
                    }
                    if (si < arr.length - 1) {
                        var lwInp = createFillInput(si, q);
                        lwFillBox.appendChild(lwInp);
                        currentFillInputs.push(lwInp);
                    }
                });
            } else {
                /* No ___ markers → replace each answer word inline within the paragraph */
                var lwAns2 = Array.isArray(q.correct_answers) ? q.correct_answers : [];
                if (lwAns2.length > 0 && lwRawText) {
                    /* Walk through the text, find each answer word in order and swap it for an input */
                    var lwRemaining = lwRawText;
                    var lwSegs = []; /* [{type:'text',val:string}|{type:'input',idx:number}] */
                    for (var lwai = 0; lwai < lwAns2.length; lwai++) {
                        var lwWord  = String(lwAns2[lwai] || '');
                        var lwEsc   = lwWord.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                        var lwRe2   = new RegExp('(?<![\\w\\\'])' + lwEsc + '(?![\\w\\\'])', 'i');
                        var lwMatch = lwRe2.exec(lwRemaining);
                        if (lwMatch) {
                            if (lwMatch.index > 0) { lwSegs.push({type: 'text', val: lwRemaining.substring(0, lwMatch.index)}); }
                            lwSegs.push({type: 'input', idx: lwai});
                            lwRemaining = lwRemaining.substring(lwMatch.index + lwMatch[0].length);
                        } else {
                            /* word not found – input will appear at the end */
                            if (lwRemaining) {
                                lwSegs.push({type: 'text', val: lwRemaining});
                                lwRemaining = '';
                            }
                            lwSegs.push({type: 'input', idx: lwai});
                        }
                    }
                    if (lwRemaining) { lwSegs.push({type: 'text', val: lwRemaining}); }
                    lwSegs.forEach(function (seg) {
                        if (seg.type === 'text') {
                            seg.val.split('\n').forEach(function (line, li) {
                                if (li > 0) { lwFillBox.appendChild(document.createElement('br')); }
                                if (line)   { lwFillBox.appendChild(document.createTextNode(line)); }
                            });
                        } else {
                            var lwInpFb = createFillInput(seg.idx, q);
                            lwFillBox.appendChild(lwInpFb);
                            currentFillInputs.push(lwInpFb);
                        }
                    });
                } else {
                    /* No answers defined – show full text + one blank at end */
                    if (lwRawText) {
                        lwRawText.split('\n').forEach(function (line, li) {
                            if (li > 0) { lwFillBox.appendChild(document.createElement('br')); }
                            if (line)   { lwFillBox.appendChild(document.createTextNode(line)); }
                        });
                        lwFillBox.appendChild(document.createTextNode('\u00a0'));
                    }
                    var lwInpFb = createFillInput(0, q);
                    lwFillBox.appendChild(lwInpFb);
                    currentFillInputs.push(lwInpFb);
                }
            }
            qtextEl.appendChild(lwFillBox);
        }

        /* ── video_writing: embed ── */
        if (type === 'video_writing' && q.media) {
            var rawUrl   = String(q.media);
            var embedUrl = toEmbedUrl(rawUrl);
            var isDirectVideo = /\.(mp4|webm|ogg)(\?|$)/i.test(rawUrl)
                             || /cloudinary\.com\/.+\/video\//i.test(rawUrl);
            if (isDirectVideo) {
                var videoWrap = document.createElement('div');
                videoWrap.className = 'wp-video-wrap';
                var vid = document.createElement('video');
                vid.controls = true;
                vid.preload  = 'metadata';
                var vs = document.createElement('source');
                vs.src = rawUrl;
                vid.appendChild(vs);
                videoWrap.appendChild(vid);
                mediaArea.appendChild(videoWrap);
            } else {
                var videoWrap = document.createElement('div');
                videoWrap.className = 'wp-video-wrap-iframe';
                var fr = document.createElement('iframe');
                fr.src = embedUrl; fr.loading = 'lazy';
                fr.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture';
                fr.allowFullscreen = true;
                videoWrap.appendChild(fr);
                mediaArea.appendChild(videoWrap);
            }
        }

        /* ── question text ── */
        if (type === 'fill_sentence' || type === 'fill_paragraph') {
            answerEl.style.display = 'none';
            var rawText = String(q.question || '');
            var fillBox = document.createElement('div');
            fillBox.className = type === 'fill_paragraph' ? 'wp-fill-paragraph-box' : 'wp-fill-sentence-box';
            if (/_{2,}/.test(rawText)) {
                /* Explicit ___ blanks → embed an input at each position */
                rawText.split(/_{2,}/).forEach(function (seg, si, arr) {
                    if (seg) {
                        seg.split('\n').forEach(function (line, li) {
                            if (li > 0) { fillBox.appendChild(document.createElement('br')); }
                            if (line)   { fillBox.appendChild(document.createTextNode(line)); }
                        });
                    }
                    if (si < arr.length - 1) {
                        var fillInp = createFillInput(si, q);
                        fillBox.appendChild(fillInp);
                        currentFillInputs.push(fillInp);
                    }
                });
            } else {
                /* No explicit ___ blanks → find each answer word inline and replace with input */
                var fpAnswers = Array.isArray(q.correct_answers) ? q.correct_answers : [];
                if (fpAnswers.length > 0 && rawText) {
                    var fpRemaining = rawText;
                    var fpSegs = [];
                    for (var fpai = 0; fpai < fpAnswers.length; fpai++) {
                        var fpWord = String(fpAnswers[fpai] || '');
                        var fpEsc  = fpWord.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                        var fpRe   = new RegExp('(?<![\\w\\\'])' + fpEsc + '(?![\\w\\\'])', 'i');
                        var fpM    = fpRe.exec(fpRemaining);
                        if (fpM) {
                            if (fpM.index > 0) { fpSegs.push({type: 'text', val: fpRemaining.substring(0, fpM.index)}); }
                            fpSegs.push({type: 'input', idx: fpai});
                            fpRemaining = fpRemaining.substring(fpM.index + fpM[0].length);
                        } else {
                            if (fpRemaining) {
                                fpSegs.push({type: 'text', val: fpRemaining});
                                fpRemaining = '';
                            }
                            fpSegs.push({type: 'input', idx: fpai});
                        }
                    }
                    if (fpRemaining) { fpSegs.push({type: 'text', val: fpRemaining}); }
                    fpSegs.forEach(function (seg) {
                        if (seg.type === 'text') {
                            seg.val.split('\n').forEach(function (line, li) {
                                if (li > 0) { fillBox.appendChild(document.createElement('br')); }
                                if (line)   { fillBox.appendChild(document.createTextNode(line)); }
                            });
                        } else {
                            var fpInp = createFillInput(seg.idx, q);
                            fillBox.appendChild(fpInp);
                            currentFillInputs.push(fpInp);
                        }
                    });
                } else {
                    /* No answers defined – show full text + one blank at end */
                    if (rawText) {
                        rawText.split('\n').forEach(function (line, li) {
                            if (li > 0) { fillBox.appendChild(document.createElement('br')); }
                            if (line)   { fillBox.appendChild(document.createTextNode(line)); }
                        });
                        fillBox.appendChild(document.createTextNode('\u00a0'));
                    }
                    var fillInp = createFillInput(0, q);
                    fillBox.appendChild(fillInp);
                    currentFillInputs.push(fillInp);
                }
            }
            qtextEl.appendChild(fillBox);
        } else {
            /* listen_write: question text is read aloud by TTS – do NOT show it visually */
            if (q.question && type !== 'listen_write') {
                var qp = document.createElement('div');
                qp.style.cssText = 'font-weight:800;color:#f14902;font-size:clamp(16px,2vw,22px);margin-bottom:10px;line-height:1.4;text-align:center;';
                qp.textContent = String(q.question);
                qtextEl.appendChild(qp);
            }
        }

        /* ── instruction ── */
        if (q.instruction) {
            instrEl.style.cssText = 'font-size:14px;color:#7c3aed;font-weight:700;margin-bottom:10px;text-align:center;';
            instrEl.textContent = String(q.instruction);
        }

        /* ── buttons state ── */
        btnPrev.disabled = (index === 0);
        btnNext.textContent = (index < questions.length - 1) ? 'Next' : 'Finish';
        btnShow.style.display = isAutoGraded(q) ? '' : 'none';
        btnShow.disabled = isAutoGraded(q) && !checkedCards[index]; /* disabled until user types */

        /* restore state if user navigated back */
        if (checkedCards[index]) {
            var isFillType     = (type === 'fill_sentence' || type === 'fill_paragraph' || type === 'listen_write');
            var wasCardCorrect = checkedCards[index] === 'correct';
            if (isFillType && currentFillInputs.length > 0) {
                var savedVals = checkedCards[index + '_inputs'] || [];
                currentFillInputs.forEach(function (inp, ii) {
                    inp.value    = savedVals[ii] || '';
                    inp.disabled = true;
                    var ans2   = q.correct_answers || [];
                    var thisOk = wasCardCorrect ? true
                               : (ans2.length > ii ? checkCorrect(inp.value, [ans2[ii]]) : false);
                    inp.className = 'wp-fill-input ' + (thisOk ? 'ok' : 'bad');
                });
                feedbackEl.textContent = wasCardCorrect ? '\u2714 Right' : '\u2718 Wrong';
                feedbackEl.className   = 'mc-feedback ' + (wasCardCorrect ? 'good' : 'bad');
                revealEl.textContent   = checkedCards[index + '_reveal'] || '';
                if (revealEl.textContent) { revealEl.classList.add('show'); }
            } else {
                answerEl.disabled = true;
                if (isAutoGraded(q)) {
                    answerEl.className     = 'dict-answer-box ' + (wasCardCorrect ? 'ok' : 'bad');
                    feedbackEl.textContent = wasCardCorrect ? '\u2714 Right' : '\u2718 Wrong';
                    feedbackEl.className   = 'mc-feedback ' + (wasCardCorrect ? 'good' : 'bad');
                    revealEl.textContent   = checkedCards[index + '_reveal'] || '';
                    if (revealEl.textContent) { revealEl.classList.add('show'); }
                } else {
                    feedbackEl.textContent = '\u2714 Submitted for review';
                    feedbackEl.className   = 'mc-feedback good';
                }
            }
        }

        if (currentFillInputs.length > 0) {
            currentFillInputs[0].focus();
        } else {
            answerEl.focus();
        }
    }

    /* ── checkAnswer ──────────────────────────────────── */
    function checkAnswer() {
        var q    = questions[index];
        var type = String(q.type || 'writing');
        if (!isAutoGraded(q)) { return; }
        if (checkedCards[index]) { return; }

        var isFill = (type === 'fill_sentence' || type === 'fill_paragraph' || type === 'listen_write') && currentFillInputs.length > 0;

        if (isFill) {
            var vals    = currentFillInputs.map(function (fi) { return fi.value.trim(); });
            var answers = q.correct_answers || [];
            if (vals.every(function (v) { return v === ''; })) {
                feedbackEl.textContent = 'Fill in the blank first.';
                feedbackEl.className   = 'mc-feedback bad';
                return;
            }
            var fillAttempts = (attemptsMap[index] || 0) + 1;
            attemptsMap[index] = fillAttempts;
            var fillCorrect;
            if (currentFillInputs.length === 1) {
                fillCorrect = checkCorrect(vals[0], answers);
            } else if (answers.length === currentFillInputs.length) {
                fillCorrect = vals.every(function (v, ii) { return checkCorrect(v, [answers[ii]]); });
            } else {
                fillCorrect = answers.some(function (a) { return normalize(vals.join(' ')) === normalize(a); });
            }
            if (fillCorrect) {
                feedbackEl.textContent = '\u2714 Right';
                feedbackEl.className   = 'mc-feedback good';
                currentFillInputs.forEach(function (fi) { fi.className = 'wp-fill-input ok'; fi.disabled = true; });
                playSound(sndOk);
                checkedCards[index]              = 'correct';
                checkedCards[index + '_inputs']  = vals;
                checkedCards[index + '_correct'] = currentFillInputs.length;
                correctCount += currentFillInputs.length;
            } else if (fillAttempts >= 2) {
                feedbackEl.textContent = '\u2718 Wrong';
                feedbackEl.className   = 'mc-feedback bad';
                var indivOk = 0;
                currentFillInputs.forEach(function (fi, ii) {
                    var ok2 = answers.length > ii ? checkCorrect(fi.value.trim(), [answers[ii]]) : false;
                    fi.className = 'wp-fill-input ' + (ok2 ? 'ok' : 'bad');
                    fi.disabled  = true;
                    if (ok2) { indivOk++; }
                });
                playSound(sndBad);
                var shownFill = answers.join(', ');
                revealEl.textContent = 'Correct: ' + shownFill;
                revealEl.classList.add('show');
                checkedCards[index]              = 'wrong';
                checkedCards[index + '_inputs']  = vals;
                checkedCards[index + '_reveal']  = 'Correct: ' + shownFill;
                checkedCards[index + '_correct'] = indivOk;
                correctCount += indivOk;
            } else {
                feedbackEl.textContent = '\u2718 Wrong (1/2) \u2013 try again';
                feedbackEl.className   = 'mc-feedback bad';
                currentFillInputs.forEach(function (fi) { fi.className = 'wp-fill-input bad'; });
                playSound(sndBad);
            }
            return;
        }

        /* ── textarea path ── */
        var val = answerEl.value.trim();
        if (val === '') {
            feedbackEl.textContent = 'Write an answer first.';
            feedbackEl.className   = 'mc-feedback bad';
            return;
        }
        var attempts = (attemptsMap[index] || 0) + 1;
        attemptsMap[index] = attempts;
        var correct = checkCorrect(val, q.correct_answers || []);
        if (correct) {
            feedbackEl.textContent = '\u2714 Right';
            feedbackEl.className   = 'mc-feedback good';
            answerEl.className     = 'dict-answer-box ok';
            answerEl.disabled      = true;
            playSound(sndOk);
            checkedCards[index]   = 'correct';
            correctCount++;
        } else if (attempts >= 2) {
            feedbackEl.textContent = '\u2718 Wrong';
            feedbackEl.className   = 'mc-feedback bad';
            answerEl.className     = 'dict-answer-box bad';
            answerEl.disabled      = true;
            playSound(sndBad);
            var shown = (q.correct_answers || []).slice(0, 2).join(' / ');
            revealEl.textContent = 'Correct: ' + shown;
            revealEl.classList.add('show');
            checkedCards[index]             = 'wrong';
            checkedCards[index + '_reveal'] = 'Correct: ' + shown;
        } else {
            feedbackEl.textContent = '\u2718 Wrong (1/2) \u2013 try again';
            feedbackEl.className   = 'mc-feedback bad';
            answerEl.className     = 'dict-answer-box bad';
            playSound(sndBad);
        }
    }

    /* ── autoCheck (on blur / Enter) ──────────────────── */
    function autoCheck() {
        var q    = questions[index];
        var type = String(q.type || 'writing');
        if (!isAutoGraded(q) || checkedCards[index]) { return; }
        var isFill = (type === 'fill_sentence' || type === 'fill_paragraph' || type === 'listen_write') && currentFillInputs.length > 0;
        if (isFill) {
            if (currentFillInputs.every(function (fi) { return fi.value.trim() !== ''; })) { checkAnswer(); }
        } else {
            if (answerEl.value.trim() !== '') { checkAnswer(); }
        }
    }

    /* ── goNext ───────────────────────────────────────── */
    function goNext() {
        if (finished) { return; }
        var q    = questions[index];
        var type = String(q.type || 'writing');

        if (isAutoGraded(q)) {
            /* must check first */
            var isFillNext = (type === 'fill_sentence' || type === 'fill_paragraph' || type === 'listen_write') && currentFillInputs.length > 0;
            if (!checkedCards[index]) {
                if (isFillNext) {
                    if (currentFillInputs.some(function (fi) { return fi.value.trim() !== ''; })) { checkAnswer(); }
                } else if (answerEl.value.trim() !== '') {
                    checkAnswer();
                }
            }
            if (!checkedCards[index]) { return; }
        } else {
            /* open writing – record response; always counts as 1 point completed */
            var val = answerEl.value.trim();
            if (!checkedCards[index]) {
                checkedCards[index] = 'open';
                correctCount++;          // submission = completed = 1 point
                if (val !== '') {
                    openResponses.push({
                        question_id:   String(q.id || index),
                        question_text: String(q.question || ''),
                        response_text: val,
                        max_points:    1,
                    });
                }
                feedbackEl.textContent = '\u2714 Submitted for review';
                feedbackEl.className   = 'mc-feedback good';
                answerEl.disabled      = true;
            }
        }

        if (index < questions.length - 1) {
            index++;
            loadCard();
        } else {
            showCompleted();
        }
    }

    /* ── goPrev ───────────────────────────────────────── */
    function goPrev() {
        if (index > 0) { index--; loadCard(); }
    }

    /* ── showAnswer ───────────────────────────────────── */
    function showAnswer() {
        var q    = questions[index];
        var type = String(q.type || 'writing');
        if (!isAutoGraded(q)) { return; }
        var answers = q.correct_answers || [];
        if (answers.length === 0) { return; }

        var isFill = (type === 'fill_sentence' || type === 'fill_paragraph' || type === 'listen_write') && currentFillInputs.length > 0;
        if (isFill) {
            var shownFill = answers.join(', ');
            if (!checkedCards[index]) {
                var savedVals2 = currentFillInputs.map(function (fi) { return fi.value.trim(); });
                checkedCards[index]             = 'wrong';
                checkedCards[index + '_inputs'] = savedVals2;
                checkedCards[index + '_reveal'] = 'Correct: ' + shownFill;
                currentFillInputs.forEach(function (fi) { fi.className = 'wp-fill-input bad'; fi.disabled = true; });
                feedbackEl.textContent = '\u2718 Wrong';
                feedbackEl.className   = 'mc-feedback bad';
                playSound(sndBad);
            }
            revealEl.textContent = 'Correct: ' + shownFill;
            revealEl.classList.add('show');
            return;
        }

        /* ── textarea path ── */
        var shown = answers.slice(0, 2).join(' / ');
        if (!checkedCards[index]) {
            checkedCards[index]             = 'wrong';
            checkedCards[index + '_reveal'] = 'Correct: ' + shown;
            answerEl.disabled  = true;
            answerEl.className = 'dict-answer-box bad';
            feedbackEl.textContent = '\u2718 Wrong';
            feedbackEl.className   = 'mc-feedback bad';
            playSound(sndBad);
        }
        if (answerEl.value.trim() !== '') {
            revealEl.textContent = 'You wrote: "' + answerEl.value + '" \u2192 Correct: ' + shown;
        } else {
            revealEl.textContent = 'Correct: ' + shown;
        }
        revealEl.classList.add('show');
    }

    /* ── persist score (fire & forget) ───────────────── */
    function persistScore(url) {
        if (!url) { return Promise.resolve(false); }
        return fetch(url, { method: 'GET', credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return !!(r && r.ok); })
            .catch(function () { return false; });
    }

    function navigateTo(url) {
        if (!url) { return; }
        try {
            if (window.top && window.top !== window.self) { window.top.location.href = url; return; }
        } catch (e) {}
        window.location.href = url;
    }

    /* ── showCompleted ────────────────────────────────── */
    async function showCompleted() {
        finished = true;
        cardEl.style.display     = 'none';
        controlsEl.style.display = 'none';
        feedbackEl.textContent   = '';
        statusEl.textContent     = 'Completed';
        completedEl.classList.add('active');
        playSound(sndDone);

        /* titles */
        if (compTitleEl) { compTitleEl.textContent = actTitle; }
        if (compTextEl)  { compTextEl.textContent  = "You've completed " + actTitle + ". Great job!"; }

        /* score: fill questions count per-input; other questions count as 1 */
        var totalCount = questions.reduce(function (sum, qq) {
            var qt = String(qq.type || 'writing');
            if ((qt === 'fill_paragraph' || qt === 'fill_sentence' || qt === 'listen_write')
                && Array.isArray(qq.correct_answers) && qq.correct_answers.length > 0) {
                return sum + qq.correct_answers.length;
            }
            return sum + 1;
        }, 0);
        var pct    = totalCount > 0 ? Math.round((correctCount / totalCount) * 100) : 0;
        var errors = Math.max(0, totalCount - correctCount);

        /* count total words written across all responses */
        var totalWords = 0;
        openResponses.forEach(function (r) {
            var t = String(r.response_text || '').replace(/\s+/g, ' ').trim();
            if (t) { totalWords += t.split(' ').length; }
        });

        if (scoreTextEl) {
            scoreTextEl.textContent = 'Score: ' + correctCount + ' / ' + totalCount + ' (' + pct + '%)';
        }
        if (totalWords > 0 && openNoteEl) {
            openNoteEl.style.display = '';
            openNoteEl.innerHTML     = '\uD83D\uDCCA ' + totalWords + ' palabras escritas';
            if (openResponses.length > 0) {
                openNoteEl.innerHTML += ' &nbsp;&middot;&nbsp; \u270D\uFE0F ' + openResponses.length + ' respuesta(s) enviadas para calificaci\u00F3n.';
            }
        } else if (openNoteEl && openResponses.length > 0) {
            openNoteEl.style.display = '';
            openNoteEl.textContent   = '\u270D\uFE0F ' + openResponses.length + ' open-writing response(s) sent for teacher grading.';
        }

        /* save open-writing responses */
        if (hasOpen) {
            try {
                var fd = new FormData();
                fd.append('activity_id',   activityId);
                fd.append('unit_id',       unitId);
                fd.append('assignment_id', assignId);
                fd.append('responses',     JSON.stringify(openResponses));
                await fetch('/lessons/lessons/activities/writing_practice/wp_save_response.php', {
                    method: 'POST', body: fd,
                });
            } catch (e) { /* non-critical */ }
        }

        /* persist score to return URL */
        if (returnTo) {
            var joiner  = returnTo.indexOf('?') !== -1 ? '&' : '?';
            var saveUrl = returnTo
                + joiner
                + 'activity_percent=' + encodeURIComponent(String(pct))
                + '&activity_errors='  + encodeURIComponent(String(errors))
                + '&activity_total='   + encodeURIComponent(String(totalCount))
                + '&activity_id='      + encodeURIComponent(activityId)
                + '&activity_type=writing_practice';

            var ok = await persistScore(saveUrl);
            if (!ok) { navigateTo(saveUrl); }
        }
    }

    /* ── restart ──────────────────────────────────────── */
    function restart() {
        checkedCards      = {};
        attemptsMap       = {};
        openResponses     = [];
        correctCount      = 0;
        index             = 0;
        finished          = false;
        currentFillInputs = [];
        if (window.speechSynthesis) { speechSynthesis.cancel(); }
        if (window.wpLwAudio) { window.wpLwAudio.pause(); window.wpLwAudio = null; }
        loadCard();
    }

    /* ── event listeners ──────────────────────────────── */
    btnPrev.addEventListener('click', goPrev);
    btnNext.addEventListener('click', goNext);
    btnShow.addEventListener('click', showAnswer);
    btnRestart.addEventListener('click', restart);

    answerEl.addEventListener('input', function () {
        if (btnShow.style.display !== 'none' && !checkedCards[index] && !finished) {
            btnShow.disabled = answerEl.value.trim() === '';
        }
    });
    answerEl.addEventListener('blur', autoCheck);
    answerEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            var q = questions[index];
            if (isAutoGraded(q)) {
                autoCheck();
            } else {
                goNext();
            }
        }
    });

    /* ── init ─────────────────────────────────────────── */
    loadCard();
});
</script>

<?php endif; /* end if/elseif/else */ ?>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '✍️', $content);
