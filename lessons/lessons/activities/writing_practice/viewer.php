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
?>
<style>
/* ── Writing Practice Viewer ─────────────────────────────── */
/* Reuses .mc-* classes from dictation/multiple_choice.css    */

.wp-wrap {
    max-width: 980px;
    margin: 0 auto;
    padding: 8px 0 28px;
    display: flex;
    flex-direction: column;
    gap: 14px;
}

/* ── Hero card ─────────────────────────────────────────────── */
.wp-hero {
    border: 1px solid #dcc4f0;
    border-radius: 18px;
    padding: 18px 20px;
    background: linear-gradient(145deg, #fff8e6 0%, #fdeaff 55%, #f0e0ff 100%);
    box-shadow: 0 10px 24px rgba(120, 40, 160, .12);
}

.wp-title {
    margin: 0;
    font-family: 'Fredoka', 'Trebuchet MS', sans-serif;
    font-size: 30px;
    line-height: 1.1;
    color: #a855c8;
}

.wp-lead {
    font-size: 15px;
    color: #b8551f;
    margin: 8px 0 0;
    line-height: 1.5;
}

.wp-meta {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 12px;
}

.wp-chip {
    display: inline-flex;
    align-items: center;
    padding: 6px 10px;
    border-radius: 999px;
    background: #eddeff;
    color: #a855c8;
    font-size: 12px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .03em;
}

.wp-progress-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    margin-top: 12px;
}

.wp-progress-label { font-size: 13px; font-weight: 800; color: #b8551f; }

.wp-progress-track {
    width: 100%;
    height: 10px;
    border-radius: 999px;
    background: #f3e5ff;
    overflow: hidden;
    border: 1px solid #e8ccff;
}

.wp-progress-fill {
    height: 100%;
    width: 0%;
    background: linear-gradient(90deg, #a855c8 0%, #f14902 100%);
    transition: width .2s ease;
}

/* ── Question list ─────────────────────────────────────────── */
.wp-list { display: flex; flex-direction: column; gap: 12px; }

.wp-card {
    border: 1px solid #dcc4f0;
    border-radius: 14px;
    padding: 16px 18px;
    background: #fff;
    box-shadow: 0 6px 16px rgba(120, 40, 160, .08);
    transition: border-color .2s;
}

.wp-card-unanswered { border-color: #ef4444; background: #fff9f9; }

.wp-q-num {
    font-size: 12px;
    font-weight: 800;
    color: #a855c8;
    text-transform: uppercase;
    letter-spacing: .06em;
    margin-bottom: 6px;
}

.wp-q-type-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 99px;
    font-size: 11px;
    font-weight: 800;
    background: #e0edff;
    color: #2563eb;
    margin-left: 6px;
    letter-spacing: .03em;
    text-transform: uppercase;
}

.wp-q-text {
    font-weight: 800;
    color: #f14902;
    font-size: 17px;
    margin: 0 0 8px;
    line-height: 1.4;
}

.wp-q-instruction {
    font-size: 14px;
    color: #7c3aed;
    margin: 0 0 10px;
    font-weight: 700;
}

/* Fill-in-blank sentence display */
.wp-q-sentence {
    background: #f0f6ff;
    border: 1px solid #bfdbfe;
    border-radius: 10px;
    padding: 10px 14px;
    font-size: 16px;
    margin-bottom: 10px;
    line-height: 1.6;
    color: #1e3a5f;
    font-weight: 700;
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

/* Audio player */
.wp-audio-wrap { margin-bottom: 12px; }

.wp-audio-wrap audio {
    width: 100%;
    border-radius: 10px;
    outline: none;
}

/* Video embed */
.wp-video-wrap {
    position: relative;
    margin-bottom: 14px;
    border-radius: 12px;
    overflow: hidden;
    background: #000;
    aspect-ratio: 16 / 9;
    max-height: 360px;
}

.wp-video-wrap iframe,
.wp-video-wrap video {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    border: none;
}

/* Auto-expanding textarea */
.wp-textarea {
    width: 100%;
    box-sizing: border-box;
    border: 2px solid #dcc4f0;
    border-radius: 10px;
    padding: 12px 14px;
    font-size: 15px;
    font-family: 'Nunito', 'Segoe UI', sans-serif;
    color: #1f2937;
    resize: none;
    overflow: hidden;
    min-height: 56px;
    line-height: 1.6;
    transition: border-color .15s, box-shadow .15s;
    background: #fff;
}

.wp-textarea:focus {
    border-color: #a855c8;
    outline: none;
    box-shadow: 0 0 0 3px rgba(168, 85, 200, .12);
}

.wp-textarea-answered { border-color: #16a34a; background: #f0fdf4; }
.wp-textarea:disabled { opacity: .75; cursor: not-allowed; }

.wp-char-count {
    font-size: 12px;
    color: #94a3b8;
    margin-top: 4px;
    text-align: right;
}

.wp-tts-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-top: 8px;
    padding: 8px 14px;
    border: none;
    border-radius: 10px;
    cursor: pointer;
    font-weight: 800;
    font-family: inherit;
    font-size: 14px;
    background: linear-gradient(180deg, #a855c8, #7c3aed);
    color: #fff;
    transition: filter .15s, transform .15s;
}
.wp-tts-btn:hover { filter: brightness(1.08); transform: translateY(-1px); }

/* Per-question feedback */
.wp-q-feedback {
    margin-top: 8px;
    font-size: 14px;
    font-weight: 800;
    border-radius: 8px;
    padding: 8px 12px;
    display: none;
}

.wp-q-feedback.correct { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.wp-q-feedback.wrong   { background: #fff2f2; color: #991b1b; border: 1px solid #fecaca; }
.wp-q-feedback.open    { background: #f5f3ff; color: #5b21b6; border: 1px solid #ddd6fe; }

/* ── Actions bar ───────────────────────────────────────────── */
.wp-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    justify-content: center;
    position: sticky;
    bottom: 12px;
    padding: 12px;
    border: 1px solid #dcc4f0;
    border-radius: 14px;
    background: rgba(255, 255, 255, .95);
    backdrop-filter: blur(4px);
}

.wp-btn {
    border: none;
    border-radius: 10px;
    padding: 12px 20px;
    font-weight: 800;
    cursor: pointer;
    color: #fff;
    background: linear-gradient(180deg, #f14902, #d33d00);
    box-shadow: 0 8px 18px rgba(241, 73, 2, .22);
    font-family: 'Nunito', 'Segoe UI', sans-serif;
    font-size: 15px;
    min-width: 160px;
    transition: filter .15s, transform .15s;
}

.wp-btn:hover:not(:disabled) { filter: brightness(1.07); transform: translateY(-1px); }
.wp-btn:disabled { opacity: .55; cursor: not-allowed; }

.wp-result {
    padding: 12px;
    border-radius: 10px;
    background: #e9f8ee;
    color: #166534;
    font-weight: 700;
    display: none;
    text-align: center;
    margin-top: 6px;
}

/* ── Completed / results screen ────────────────────────────── */
.wp-completed-screen {
    display: none;
    text-align: center;
    max-width: 600px;
    margin: 0 auto;
    padding: 40px 24px;
    border: 1px solid #dcc4f0;
    border-radius: 18px;
    background: #fff;
    box-shadow: 0 10px 30px rgba(120, 40, 160, .12);
}

.wp-completed-screen.active { display: block; }

.wp-completed-icon  { font-size: 72px; margin-bottom: 14px; }

.wp-completed-title {
    font-family: 'Fredoka', 'Trebuchet MS', sans-serif;
    font-size: 32px;
    font-weight: 700;
    color: #a855c8;
    margin: 0 0 12px;
    line-height: 1.2;
}

.wp-completed-score {
    font-size: 22px;
    font-weight: 800;
    color: #f14902;
    margin: 0 0 8px;
}

.wp-completed-text {
    font-size: 15px;
    color: #b8551f;
    line-height: 1.6;
    margin: 0 0 6px;
}

/* ── Empty state ───────────────────────────────────────────── */
.wp-empty {
    padding: 16px;
    border: 1px solid #dcc4f0;
    border-radius: 12px;
    background: #fff;
    color: #b8551f;
    font-weight: 700;
}

/* ── Responsive ────────────────────────────────────────────── */
@media (max-width: 760px) {
    .wp-title    { font-size: 24px; }
    .wp-q-text   { font-size: 15px; }
    .wp-actions  { position: static; }
    .wp-textarea { font-size: 14px; }
    .wp-btn      { width: 100%; max-width: 300px; min-width: 0; }
}
</style>

<div class="wp-wrap" id="wpApp">

    <!-- ── Hero ──────────────────────────────────────────────── -->
    <section class="wp-hero">
        <h2 class="wp-title"><?= htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8') ?></h2>

        <?php if ($description !== ''): ?>
            <p class="wp-lead"><?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?></p>
        <?php else: ?>
            <p class="wp-lead">Read each prompt carefully and write your response.</p>
        <?php endif; ?>

        <div class="wp-meta">
            <span class="wp-chip">Questions: <span id="wp-total-count"><?= count($questions) ?></span></span>
            <span class="wp-chip">Answered: <span id="wp-answered-count">0</span></span>
        </div>

        <div class="wp-progress-head">
            <span class="wp-progress-label">Progress</span>
            <span class="wp-progress-label" id="wp-progress-pct">0%</span>
        </div>
        <div class="wp-progress-track">
            <div class="wp-progress-fill" id="wp-progress-fill"></div>
        </div>
    </section>

    <!-- ── Content ───────────────────────────────────────────── -->
    <?php if (empty($questions)): ?>

        <div class="wp-empty">
            This activity has no questions yet. Open the editor to configure it.
        </div>

    <?php else: ?>

        <div id="wp-questions-wrap">
            <div class="wp-list" id="wp-list"></div>

            <div class="wp-actions">
                <button type="button" class="wp-btn" id="btnSubmitWP">
                    ✔ Submit
                </button>
            </div>

            <div class="wp-result" id="wpResult"></div>
        </div>

        <div id="wp-completed" class="wp-completed-screen">
            <div class="wp-completed-icon">✍️</div>
            <h2 class="wp-completed-title"><?= htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8') ?></h2>
            <p class="wp-completed-score" id="wp-score-text"></p>
            <p class="wp-completed-text">Your responses have been submitted successfully.</p>
        </div>

    <?php endif; ?>

</div>

<!-- Pass PHP data to JavaScript safely -->
<script>
window.WP_DATA        = <?= json_encode($questions, JSON_UNESCAPED_UNICODE) ?>;
window.WP_RETURN_TO   = <?= json_encode($returnTo, JSON_UNESCAPED_UNICODE) ?>;
window.WP_ACTIVITY_ID = <?= json_encode((string) ($activity['id'] ?? ''), JSON_UNESCAPED_UNICODE) ?>;
window.WP_UNIT_ID       = <?= json_encode($unit, JSON_UNESCAPED_UNICODE) ?>;
window.WP_ASSIGNMENT_ID = <?= json_encode((string) ($_GET['assignment'] ?? ''), JSON_UNESCAPED_UNICODE) ?>;
</script>

<script>
(function () {
    'use strict';

    /* ── Data & elements ──────────────────────────────────── */
    const questions      = Array.isArray(window.WP_DATA) ? window.WP_DATA : [];
    const submitBtn      = document.getElementById('btnSubmitWP');
    const listEl         = document.getElementById('wp-list');
    const questionsWrap  = document.getElementById('wp-questions-wrap');
    const completedScreen = document.getElementById('wp-completed');
    const scoreTextEl    = document.getElementById('wp-score-text');
    const resultEl       = document.getElementById('wpResult');
    const answeredEl     = document.getElementById('wp-answered-count');
    const progressFill   = document.getElementById('wp-progress-fill');
    const progressPct    = document.getElementById('wp-progress-pct');

    if (!submitBtn || !listEl || questions.length === 0) { return; }

    /* ── Helpers ──────────────────────────────────────────── */

    function esc(s) {
        return String(s || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /* Flexible answer comparison – ignore case, extra spaces, common punctuation */
    function normalizeAns(s) {
        return String(s || '')
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .trim()
            .toLowerCase()
            .replace(/[.,;:!?'"()]/g, '')
            .replace(/\s+/g, ' ');
    }

    function isAnswerCorrect(userValue, correctAnswers) {
        // No correct answers defined → open-ended, always award points
        if (!Array.isArray(correctAnswers) || correctAnswers.length === 0) {
            return true;
        }
        const ua = normalizeAns(userValue);
        return correctAnswers.some(function (ca) {
            return normalizeAns(ca) === ua;
        });
    }

    /* Auto-expand textarea height to fit content */
    function autoResize(ta) {
        ta.style.height = 'auto';
        ta.style.height = (ta.scrollHeight + 2) + 'px';
    }

    /* Convert YouTube watch/share URL to nocookie embed URL */
    function toEmbedUrl(url) {
        if (!url) { return ''; }
        if (/youtube\.com\/embed\/|player\.vimeo\.com\/video\//.test(url)) { return url; }

        const shortMatch = url.match(/youtu\.be\/([A-Za-z0-9_-]{11})/);
        if (shortMatch) { return 'https://www.youtube-nocookie.com/embed/' + shortMatch[1]; }

        const watchMatch = url.match(/youtube\.com\/watch\?(?:.*&)?v=([A-Za-z0-9_-]{11})/);
        if (watchMatch) { return 'https://www.youtube-nocookie.com/embed/' + watchMatch[1]; }

        return url; // MP4, Vimeo direct, etc.
    }

    const TYPE_LABEL = {
        writing:        'Open Writing',
        listen_write:   'Listen & Write',
        fill_sentence:  'Fill the Sentence',
        fill_paragraph: 'Fill the Paragraph',
        video_writing:  'Video + Writing',
    };

    /* ── Progress tracker ─────────────────────────────────── */
    function updateProgress() {
        const total = questions.length;
        let answered = 0;

        for (let i = 0; i < total; i++) {
            const ta   = listEl.querySelector('.wp-textarea[data-index="' + i + '"]');
            const val  = ta ? ta.value.trim() : '';
            const card = listEl.querySelector('.wp-card[data-index="' + i + '"]');

            if (val !== '') {
                answered++;
                if (ta)   { ta.classList.add('wp-textarea-answered'); }
                if (card) { card.classList.remove('wp-card-unanswered'); }
            } else {
                if (ta)   { ta.classList.remove('wp-textarea-answered'); }
                if (card) { card.classList.add('wp-card-unanswered'); }
            }
        }

        const pct = total > 0 ? Math.round((answered / total) * 100) : 0;
        if (answeredEl)   { answeredEl.textContent   = String(answered); }
        if (progressFill) { progressFill.style.width = pct + '%'; }
        if (progressPct)  { progressPct.textContent  = pct + '%'; }

        return { answered: answered, total: total };
    }

    /* ── Render question cards ────────────────────────────── */
    questions.forEach(function (q, idx) {
        const card = document.createElement('div');
        card.className = 'wp-card wp-card-unanswered';
        card.setAttribute('data-index', String(idx));

        const type  = String(q.type || 'writing');
        const label = TYPE_LABEL[type] || 'Writing';

        /* Question number + type badge */
        const numDiv = document.createElement('div');
        numDiv.className = 'wp-q-num';
        numDiv.innerHTML = (idx + 1) + '. Writing'
            + ' <span class="wp-q-type-badge">' + esc(label) + '</span>';
        card.appendChild(numDiv);

        /* ── Question text / prompt ─────────────────────────── */
        if (type === 'fill_sentence' || type === 'fill_paragraph') {
            // For fill-in-blank: render the sentence/paragraph with visual blank markers
            if (q.question) {
                const sentDiv = document.createElement('div');
                sentDiv.className = 'wp-q-sentence';
                sentDiv.innerHTML = esc(String(q.question)).replace(/_{2,}/g, function () {
                    return '<span class="wp-blank">___</span>';
                });
                card.appendChild(sentDiv);
            }
        } else {
            // For other types: show the question as a prominent text block
            if (q.question) {
                const qDiv = document.createElement('div');
                qDiv.className = 'wp-q-text';
                qDiv.textContent = String(q.question);
                card.appendChild(qDiv);
            }
        }

        /* Instruction line */
        if (q.instruction) {
            const iDiv = document.createElement('div');
            iDiv.className = 'wp-q-instruction';
            iDiv.textContent = String(q.instruction);
            card.appendChild(iDiv);
        }

        /* ── Type-specific media ────────────────────────────── */
        if (type === 'listen_write') {
            const audioWrap = document.createElement('div');
            audioWrap.className = 'wp-audio-wrap';

            if (q.media) {
                const audio = document.createElement('audio');
                audio.controls = true;
                audio.preload  = 'none';
                const src = document.createElement('source');
                src.src = String(q.media);
                audio.appendChild(src);
                audio.appendChild(document.createTextNode('Your browser does not support audio playback.'));
                audioWrap.appendChild(audio);
            }

            if (q.question) {
                const ttsBtn = document.createElement('button');
                ttsBtn.type      = 'button';
                ttsBtn.className = 'wp-tts-btn';
                ttsBtn.innerHTML = '&#x1F50A; Escuchar';
                ttsBtn.addEventListener('click', function () {
                    speechSynthesis.cancel();
                    var utter  = new SpeechSynthesisUtterance(String(q.question));
                    utter.lang = 'en-US';
                    utter.rate = 0.9;
                    speechSynthesis.speak(utter);
                });
                audioWrap.appendChild(ttsBtn);
            }

            card.appendChild(audioWrap);
        }

        if (type === 'video_writing' && q.media) {
            const embedUrl  = toEmbedUrl(String(q.media));
            const videoWrap = document.createElement('div');
            videoWrap.className = 'wp-video-wrap';

            const isDirectVideo = /\.(mp4|webm|ogg)(\?|$)/i.test(embedUrl);
            if (isDirectVideo) {
                const video  = document.createElement('video');
                video.controls = true;
                video.preload  = 'metadata';
                video.style.cssText = 'position:absolute;top:0;left:0;width:100%;height:100%;object-fit:contain;';
                const vsrc = document.createElement('source');
                vsrc.src   = embedUrl;
                video.appendChild(vsrc);
                videoWrap.appendChild(video);
            } else {
                const iframe = document.createElement('iframe');
                iframe.src            = embedUrl;
                iframe.loading        = 'lazy';
                iframe.allow          = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture';
                iframe.allowFullscreen = true;
                videoWrap.appendChild(iframe);
            }

            card.appendChild(videoWrap);
        }

        /* ── Auto-expanding textarea ────────────────────────── */
        const ta = document.createElement('textarea');
        ta.className = 'wp-textarea';
        ta.setAttribute('data-index', String(idx));
        ta.rows        = 2;
        ta.placeholder = 'Escribe tu respuesta aqu\u00ED...';
        card.appendChild(ta);

        /* Character counter */
        const counter = document.createElement('div');
        counter.className = 'wp-char-count';
        counter.textContent = '0 characters';
        card.appendChild(counter);

        /* Per-question feedback area (revealed after submit) */
        const feedback = document.createElement('div');
        feedback.className = 'wp-q-feedback';
        feedback.setAttribute('data-feedback', String(idx));
        card.appendChild(feedback);

        /* Input events */
        ta.addEventListener('input', function () {
            autoResize(ta);
            const len = ta.value.length;
            counter.textContent = len + ' character' + (len !== 1 ? 's' : '');
            updateProgress();
        });

        listEl.appendChild(card);
        autoResize(ta); // initial size
    });

    updateProgress();

    /* ── Score persistence (fire-and-forget) ──────────────── */
    function persistScoreSilently(url) {
        if (!url) { return Promise.resolve(false); }
        try {
            return fetch(url, {
                method:      'GET',
                credentials: 'same-origin',
                cache:       'no-store',
                keepalive:   true,
            })
            .then(function (r) { return !!(r && r.ok); })
            .catch(function () { return false; });
        } catch (e) {
            return Promise.resolve(false);
        }
    }

    function navigateToReturn(url) {
        if (!url) { return; }
        try {
            if (window.top && window.top !== window.self) {
                window.top.location.href = url;
                return;
            }
        } catch (e) { /* cross-origin */ }
        window.location.href = url;
    }

    /* ── Submit handler ────────────────────────────────────── */
    submitBtn.addEventListener('click', async function () {

        const progress = updateProgress();

        /* Guard: all questions must be answered */
        if (progress.answered < progress.total) {
            if (resultEl) {
                resultEl.style.display    = 'block';
                resultEl.style.background = '#fff2f2';
                resultEl.style.color      = '#9b1c1c';
                resultEl.textContent = 'Please answer all questions before submitting.';
            }
            // Scroll to first unanswered card
            const firstEmpty = listEl.querySelector('.wp-card.wp-card-unanswered');
            if (firstEmpty) {
                try { firstEmpty.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (e) {}
            }
            return;
        }

        if (resultEl) {
            resultEl.style.display = 'none';
            resultEl.textContent   = '';
        }

        /* ── Calculate score ─────────────────────────────────── */
        let earnedPoints = 0;
        let maxPoints    = 0;
        let errorCount   = 0;

        questions.forEach(function (q, idx) {
            const ta  = listEl.querySelector('.wp-textarea[data-index="' + idx + '"]');
            const val = ta ? ta.value.trim() : '';
            const pts = Math.max(1, Number(q.points) || 10);
            maxPoints += pts;

            const hasExpected = Array.isArray(q.correct_answers) && q.correct_answers.length > 0;
            const correct     = isAnswerCorrect(val, q.correct_answers);

            if (correct && val !== '') {
                earnedPoints += pts;
            } else if (hasExpected) {
                errorCount++;
            }

            /* Disable textarea */
            if (ta) { ta.disabled = true; }

            /* Show per-question feedback */
            const feedback = listEl.querySelector('.wp-q-feedback[data-feedback="' + idx + '"]');
            if (feedback) {
                feedback.style.display = 'block';

                if (!hasExpected) {
                    feedback.className   = 'wp-q-feedback open';
                    feedback.textContent = '✓ Submitted — open-ended response recorded.';
                } else if (correct) {
                    feedback.className   = 'wp-q-feedback correct';
                    feedback.textContent = '✓ Correct!';
                } else {
                    feedback.className   = 'wp-q-feedback wrong';
                    const accepted = q.correct_answers.slice(0, 2).join(' / ');
                    feedback.textContent = '✗ Expected: ' + accepted;
                }
            }
        });

        const percent = maxPoints > 0 ? Math.round((earnedPoints / maxPoints) * 100) : 100;

        /* Build score URL */
        const hasQuery = String(window.WP_RETURN_TO).indexOf('?') !== -1;
        const joiner   = hasQuery ? '&' : '?';
        const saveUrl  = window.WP_RETURN_TO
            + joiner
            + 'activity_percent=' + encodeURIComponent(String(percent))
            + '&activity_errors='  + encodeURIComponent(String(errorCount))
            + '&activity_total='   + encodeURIComponent(String(questions.length))
            + '&activity_id='      + encodeURIComponent(String(window.WP_ACTIVITY_ID || ''))
            + '&activity_type=writing_practice';

        submitBtn.disabled = true;

        /* Save open-writing responses for teacher review */
        try {
            var openResponses = [];
            questions.forEach(function (q, idx) {
                if (String(q.type) === 'writing') {
                    var taEl = listEl.querySelector('.wp-textarea[data-index="' + idx + '"]');
                    var val  = taEl ? taEl.value.trim() : '';
                    if (val !== '') {
                        openResponses.push({
                            question_id:   String(q.id || idx),
                            question_text: String(q.question || ''),
                            response_text: val,
                            max_points:    Math.max(1, Number(q.points) || 10),
                        });
                    }
                }
            });
            if (openResponses.length > 0) {
                var fd = new FormData();
                fd.append('activity_id',   String(window.WP_ACTIVITY_ID || ''));
                fd.append('unit_id',       String(window.WP_UNIT_ID || ''));
                fd.append('assignment_id', String(window.WP_ASSIGNMENT_ID || ''));
                fd.append('responses',     JSON.stringify(openResponses));
                await fetch('/lessons/lessons/activities/writing_practice/wp_save_response.php', {
                    method: 'POST',
                    body: fd,
                });
            }
        } catch (e) { /* non-critical – do not block score save */ }

        const ok = await persistScoreSilently(saveUrl);

        /* Show completed screen */
        if (questionsWrap)   { questionsWrap.style.display = 'none'; }
        if (scoreTextEl)     { scoreTextEl.textContent = 'Score: ' + earnedPoints + ' / ' + maxPoints + ' points (' + percent + '%)'; }
        if (completedScreen) { completedScreen.classList.add('active'); }

        if (!ok) {
            navigateToReturn(saveUrl);
        }
    });

})();
</script>

<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '✍️', $content);
