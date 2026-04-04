<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

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
            'points'          => max(1, (int) ($item['points'] ?? 10)),
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
    position: relative;
    margin-bottom: 14px;
    border-radius: 12px;
    overflow: hidden;
    background: #000;
    aspect-ratio: 16 / 9;
    max-height: 360px;
    width: 100%;
}
.wp-video-wrap iframe,
.wp-video-wrap video {
    position: absolute; top: 0; left: 0;
    width: 100%; height: 100%; border: none;
}
.wp-audio-wrap { margin-bottom: 12px; text-align: center; }
.wp-audio-wrap audio { width: 100%; max-width: 500px; border-radius: 10px; outline: none; }
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
</style>

<?php if (empty($questions)): ?>
    <p style="padding:20px;color:#b8551f;font-weight:700;">This activity has no questions yet. Open the editor to configure it.</p>
<?php else: ?>

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
    var index        = 0;
    var finished     = false;
    var checkedCards = {};   // index → true when locked
    var attemptsMap  = {};   // index → attempt count
    var correctCount = 0;    // auto-graded correct
    var autoTotal    = 0;    // auto-graded questions count
    var openResponses = [];   // collected writing responses

    /* count auto-graded questions */
    questions.forEach(function (q) {
        if (String(q.type || 'writing') !== 'writing') { autoTotal++; }
    });

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

    /* ── loadCard ─────────────────────────────────────── */
    function loadCard() {
        var q    = questions[index];
        var type = String(q.type || 'writing');

        finished = false;
        completedEl.classList.remove('active');
        cardEl.style.display    = '';
        controlsEl.style.display = '';

        /* status */
        statusEl.textContent = (index + 1) + ' / ' + questions.length;

        /* clear previous content */
        mediaArea.innerHTML   = '';
        qtextEl.innerHTML     = '';
        instrEl.innerHTML     = '';
        answerEl.value        = '';
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

        /* ── listen_write: audio + TTS ── */
        if (type === 'listen_write') {
            var audioWrap = document.createElement('div');
            audioWrap.className = 'wp-audio-wrap';
            if (q.media) {
                var audio = document.createElement('audio');
                audio.controls = true; audio.preload = 'none';
                var src = document.createElement('source');
                src.src = String(q.media);
                audio.appendChild(src);
                audioWrap.appendChild(audio);
            }
            var ttsBtn = document.createElement('button');
            ttsBtn.type      = 'button';
            ttsBtn.className = 'mc-btn mc-btn-listen-wp';
            ttsBtn.innerHTML = '\uD83C\uDFA7 Listen';
            ttsBtn.addEventListener('click', function () {
                var text = String(q.question || '');
                if (!text) { return; }
                speechSynthesis.cancel();
                var u = new SpeechSynthesisUtterance(text);
                u.lang = 'en-US'; u.rate = 0.9;
                speechSynthesis.speak(u);
            });
            audioWrap.appendChild(ttsBtn);
            mediaArea.appendChild(audioWrap);
        }

        /* ── video_writing: embed ── */
        if (type === 'video_writing' && q.media) {
            var embedUrl  = toEmbedUrl(String(q.media));
            var videoWrap = document.createElement('div');
            videoWrap.className = 'wp-video-wrap';
            var isMP4 = /\.(mp4|webm|ogg)(\?|$)/i.test(embedUrl);
            if (isMP4) {
                var vid = document.createElement('video');
                vid.controls = true; vid.preload = 'metadata';
                vid.style.cssText = 'position:absolute;top:0;left:0;width:100%;height:100%;object-fit:contain;';
                var vs = document.createElement('source'); vs.src = embedUrl;
                vid.appendChild(vs);
                videoWrap.appendChild(vid);
            } else {
                var fr = document.createElement('iframe');
                fr.src = embedUrl; fr.loading = 'lazy';
                fr.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture';
                fr.allowFullscreen = true;
                videoWrap.appendChild(fr);
            }
            mediaArea.appendChild(videoWrap);
        }

        /* ── question text ── */
        if (type === 'fill_sentence' || type === 'fill_paragraph') {
            if (q.question) {
                var sentDiv = document.createElement('div');
                sentDiv.className = 'wp-q-sentence';
                sentDiv.innerHTML = esc(String(q.question)).replace(/_{2,}/g, function () {
                    return '<span class="wp-blank">___</span>';
                });
                qtextEl.appendChild(sentDiv);
            }
        } else {
            if (q.question) {
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

        /* restore state if user navigated back */
        if (checkedCards[index]) {
            answerEl.disabled = true;
            if (isAutoGraded(q)) {
                var wasCorrect = checkedCards[index] === 'correct';
                answerEl.className = 'dict-answer-box ' + (wasCorrect ? 'ok' : 'bad');
                feedbackEl.textContent = wasCorrect ? '\u2714 Right' : '\u2718 Wrong';
                feedbackEl.className   = 'mc-feedback ' + (wasCorrect ? 'good' : 'bad');
                revealEl.textContent   = checkedCards[index + '_reveal'] || '';
                if (revealEl.textContent) { revealEl.classList.add('show'); }
            } else {
                feedbackEl.textContent = '\u2714 Submitted for review';
                feedbackEl.className   = 'mc-feedback good';
            }
        }

        answerEl.focus();
    }

    /* ── checkAnswer ──────────────────────────────────── */
    function checkAnswer() {
        var q = questions[index];
        if (!isAutoGraded(q)) { return; }
        if (checkedCards[index]) { return; }

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
            checkedCards[index]          = 'wrong';
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
        var q = questions[index];
        if (!isAutoGraded(q) || checkedCards[index] || answerEl.value.trim() === '') { return; }
        checkAnswer();
    }

    /* ── goNext ───────────────────────────────────────── */
    function goNext() {
        if (finished) { return; }
        var q    = questions[index];
        var type = String(q.type || 'writing');

        if (isAutoGraded(q)) {
            /* must check first */
            if (!checkedCards[index] && answerEl.value.trim() !== '') { checkAnswer(); }
            if (!checkedCards[index]) { return; }
        } else {
            /* open writing – record response */
            var val = answerEl.value.trim();
            if (!checkedCards[index]) {
                checkedCards[index] = 'open';
                if (val !== '') {
                    openResponses.push({
                        question_id:   String(q.id || index),
                        question_text: String(q.question || ''),
                        response_text: val,
                        max_points:    Math.max(1, Number(q.points) || 10),
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
        var q = questions[index];
        if (!isAutoGraded(q)) { return; }
        var answers = q.correct_answers || [];
        if (answers.length === 0) { return; }
        var shown = answers.slice(0, 2).join(' / ');
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

        /* score: only auto-graded contribute */
        var pct    = autoTotal > 0 ? Math.round((correctCount / autoTotal) * 100) : 100;
        var errors = Math.max(0, autoTotal - correctCount);

        if (scoreTextEl) {
            if (autoTotal > 0) {
                scoreTextEl.textContent = 'Score: ' + correctCount + ' / ' + autoTotal + ' (' + pct + '%)';
            } else {
                scoreTextEl.textContent = 'Responses submitted for teacher review.';
            }
        }

        var hasOpen = openResponses.length > 0;
        if (openNoteEl && hasOpen) {
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
                + '&activity_total='   + encodeURIComponent(String(questions.length))
                + '&activity_id='      + encodeURIComponent(activityId)
                + '&activity_type=writing_practice';

            var ok = await persistScore(saveUrl);
            if (!ok) { navigateTo(saveUrl); }
        }
    }

    /* ── restart ──────────────────────────────────────── */
    function restart() {
        checkedCards  = {};
        attemptsMap   = {};
        openResponses = [];
        correctCount  = 0;
        index         = 0;
        loadCard();
    }

    /* ── event listeners ──────────────────────────────── */
    btnPrev.addEventListener('click', goPrev);
    btnNext.addEventListener('click', goNext);
    btnShow.addEventListener('click', showAnswer);
    btnRestart.addEventListener('click', restart);

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

<?php endif; ?>
<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '✍️', $content);
