<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

$activityId = isset($_GET['id'])        ? trim((string) $_GET['id'])        : '';
$unit       = isset($_GET['unit'])      ? trim((string) $_GET['unit'])      : '';
$returnTo   = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';

function stk_default_title(): string { return 'My Story'; }

function stk_normalize($raw): array
{
    $default = ['title' => stk_default_title(), 'pages' => []];
    if ($raw === null || $raw === '') return $default;
    $d = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($d)) return $default;
    $pages = [];
    foreach ($d['pages'] ?? [] as $p) {
        if (!is_array($p)) continue;
        $image_url = trim((string)($p['image_url'] ?? ''));
        $text      = trim((string)($p['text'] ?? ''));
        if ($image_url === '' && $text === '') continue;
        $kw = $p['keywords'] ?? [];
        if (!is_array($kw)) $kw = [];
        $wt = $p['word_timings'] ?? [];
        if (!is_array($wt)) $wt = [];
        $pages[] = [
            'id'          => (int)($p['id'] ?? 0),
            'image_url'   => $image_url,
            'text'        => $text,
            'audio_url'   => trim((string)($p['audio_url'] ?? '')),
            'keywords'    => array_values(array_filter(array_map('trim', array_map('strval', $kw)))),
            'word_timings'=> $wt,
        ];
    }
    return [
        'title' => trim((string)($d['title'] ?? '')) ?: stk_default_title(),
        'pages' => $pages,
    ];
}

function stk_load(PDO $pdo, string $activityId, string $unit): array
{
    $fallback = ['id' => '', 'title' => stk_default_title(), 'pages' => []];
    $row = null;
    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE id = :id AND type = 'story_kids' LIMIT 1");
        $stmt->execute(['id' => $activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row && $unit !== '') {
        $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE unit_id = :unit AND type = 'story_kids' ORDER BY id ASC LIMIT 1");
        $stmt->execute(['unit' => $unit]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row) return $fallback;
    $payload = stk_normalize($row['data'] ?? null);
    return array_merge($payload, ['id' => (string)($row['id'] ?? '')]);
}

if ($unit === '' && $activityId !== '') {
    $stmt = $pdo->prepare('SELECT unit_id FROM activities WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $activityId]);
    $r    = $stmt->fetch(PDO::FETCH_ASSOC);
    $unit = $r ? (string)($r['unit_id'] ?? '') : '';
}

$activity   = stk_load($pdo, $activityId, $unit);
$title      = $activity['title'];
$pages      = $activity['pages'];

if ($activityId === '' && !empty($activity['id'])) {
    $activityId = $activity['id'];
}

// Empty state
if (empty($pages)) {
    $isStaff = !empty($_SESSION['admin_logged']) || !empty($_SESSION['academic_logged']);
    ob_start();
    ?>
    <style>
    .stk-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:340px;gap:14px;text-align:center;}
    .stk-empty-icon{font-size:64px}
    .stk-empty-msg{font-family:'Fredoka',sans-serif;font-size:22px;color:#7F77DD}
    .stk-empty-sub{font-size:14px;color:#9B94BE}
    .stk-edit-link{display:inline-block;padding:10px 22px;background:#F97316;color:#fff;border-radius:12px;font-weight:700;text-decoration:none;margin-top:6px;}
    </style>
    <div class="stk-empty">
        <div class="stk-empty-icon">📖</div>
        <div class="stk-empty-msg">No pages yet!</div>
        <div class="stk-empty-sub">This story hasn't been configured yet.</div>
        <?php if ($isStaff && $activityId !== ''): ?>
        <a class="stk-edit-link"
           href="/lessons/lessons/activities/story_kids/editor.php?id=<?= rawurlencode($activityId) ?>&unit=<?= rawurlencode($unit) ?>">
            ✏️ Open Editor
        </a>
        <?php endif; ?>
    </div>
    <?php
    $content = ob_get_clean();
    render_activity_viewer($title, '📖', $content);
    exit;
}

ob_start();
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@500;700;800&display=swap');

/* Override body background */
body,
.activity-wrapper,
.viewer-content,
body.presentation-mode .viewer-content,
body.fullscreen-embedded .viewer-content {
    background: linear-gradient(160deg, #EDE9FA 0%, #FFF0E6 100%) !important;
}

.act-header { display: none !important; }

/* ── Story viewer ──────────────────────────────── */
.stk-viewer {
    max-width: 720px;
    margin: 0 auto;
    padding: 0 12px 32px;
    font-family: 'Nunito', sans-serif;
    position: relative;
    min-height: 70vh;
    /* perspective for 3-D flip children */
    perspective: 1200px;
}

/* Cover slide */
.stk-cover {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    min-height: 60vh;
    text-align: center;
    gap: 20px;
    padding: 20px;
    animation: stkFadeIn .5s ease;
}
.stk-cover-icon { font-size: 72px; }
.stk-cover-title {
    font-family: 'Fredoka', sans-serif;
    font-size: clamp(28px, 6vw, 52px);
    font-weight: 700;
    color: #F97316;
    line-height: 1.15;
}
.stk-cover-sub {
    font-size: clamp(14px, 2.5vw, 18px);
    color: #7F77DD;
    font-weight: 700;
}
.stk-start-btn {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    padding: 16px 36px;
    border: none;
    border-radius: 50px;
    background: linear-gradient(135deg, #F97316, #fb923c);
    color: #fff;
    font-family: 'Fredoka', sans-serif;
    font-size: 22px;
    font-weight: 700;
    cursor: pointer;
    box-shadow: 0 10px 28px rgba(249,115,22,.35);
    transition: transform .15s, filter .15s;
    margin-top: 8px;
}
.stk-start-btn:hover { transform: scale(1.04); filter: brightness(1.07); }

/* Page slide */
.stk-page {
    display: none;
    flex-direction: column;
    align-items: center;
    gap: 20px;
    transform-style: preserve-3d;
    backface-visibility: hidden;
}
.stk-page.active { display: flex; }

/* ── Page-flip animations ── */
.stk-page.flip-out-fwd {
    animation: stkFlipOutFwd 0.28s ease-in forwards;
}
.stk-page.flip-in-fwd {
    animation: stkFlipInFwd 0.28s ease-out forwards;
}
.stk-page.flip-out-bwd {
    animation: stkFlipOutBwd 0.28s ease-in forwards;
}
.stk-page.flip-in-bwd {
    animation: stkFlipInBwd 0.28s ease-out forwards;
}

@keyframes stkFlipOutFwd {
    from { transform: perspective(900px) rotateY(0deg);   opacity: 1; }
    to   { transform: perspective(900px) rotateY(-90deg); opacity: 0; }
}
@keyframes stkFlipInFwd {
    from { transform: perspective(900px) rotateY(90deg);  opacity: 0; }
    to   { transform: perspective(900px) rotateY(0deg);   opacity: 1; }
}
@keyframes stkFlipOutBwd {
    from { transform: perspective(900px) rotateY(0deg);  opacity: 1; }
    to   { transform: perspective(900px) rotateY(90deg); opacity: 0; }
}
@keyframes stkFlipInBwd {
    from { transform: perspective(900px) rotateY(-90deg); opacity: 0; }
    to   { transform: perspective(900px) rotateY(0deg);   opacity: 1; }
}

/* Progress bar */
.stk-progress-wrap {
    width: 100%;
    background: rgba(127,119,221,.15);
    border-radius: 50px;
    height: 8px;
    overflow: hidden;
    margin-top: 8px;
}
.stk-progress-bar {
    height: 100%;
    border-radius: 50px;
    background: linear-gradient(90deg, #F97316, #7F77DD);
    transition: width .35s ease;
}
.stk-progress-label {
    font-size: 13px;
    font-weight: 800;
    color: #9B94BE;
    text-align: right;
    width: 100%;
    margin-top: 4px;
}

/* Page image */
.stk-img-wrap {
    width: 100%;
    border-radius: 24px;
    overflow: hidden;
    box-shadow: 0 12px 36px rgba(127,119,221,.18);
    background: #fff;
    max-height: 55vh;
    display: flex;
    align-items: center;
    justify-content: center;
}
.stk-img-wrap img {
    width: 100%;
    max-height: 55vh;
    object-fit: contain;
    display: block;
    border-radius: 24px;
}
.stk-no-image {
    width: 100%;
    min-height: 180px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 64px;
    background: #F5F3FF;
    border-radius: 24px;
}

/* Page text + word highlighting */
.stk-text {
    width: 100%;
    background: #fff;
    border-left: 5px solid #7F77DD;
    border-radius: 16px;
    padding: 18px 22px;
    font-family: 'Fredoka', sans-serif;
    font-size: clamp(18px, 3.5vw, 28px);
    font-weight: 600;
    color: #1e1b4b;
    line-height: 1.6;
    box-shadow: 0 4px 16px rgba(127,119,221,.10);
    text-align: center;
}
/* Individual word spans */
.stk-word {
    display: inline;
    border-radius: 5px;
    padding: 1px 2px;
    transition: background-color .12s, color .12s;
    cursor: default;
}
/* Currently spoken word */
.stk-word.stk-word-active {
    background-color: #fde68a;
    color: #92400e;
}
/* Keyword — always red */
.stk-word.stk-keyword {
    color: #dc2626;
    font-weight: 700;
}
/* Keyword when also active */
.stk-word.stk-keyword.stk-word-active {
    background-color: #fde68a;
    color: #92400e;
}

/* Nav buttons */
.stk-nav {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 16px;
    width: 100%;
    margin-top: 4px;
    flex-wrap: wrap;
}
.stk-nav-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 14px 28px;
    border: none;
    border-radius: 50px;
    font-family: 'Fredoka', sans-serif;
    font-size: 18px;
    font-weight: 700;
    cursor: pointer;
    transition: transform .15s, filter .15s, opacity .15s;
    min-width: 130px;
    justify-content: center;
}
.stk-nav-btn:hover:not(:disabled) { transform: scale(1.05); filter: brightness(1.08); }
.stk-nav-btn:disabled { opacity: .35; cursor: default; }
.stk-btn-prev {
    background: #EDE9FA;
    color: #7F77DD;
}
.stk-btn-next {
    background: linear-gradient(135deg, #F97316, #fb923c);
    color: #fff;
    box-shadow: 0 8px 20px rgba(249,115,22,.28);
}
.stk-btn-audio {
    background: #F5F3FF;
    color: #7F77DD;
    border: 1.5px solid #EDE9FA;
    padding: 12px 18px;
    min-width: auto;
    font-size: 20px;
}
.stk-btn-audio:hover:not(:disabled) { background: #EDE9FA; }

/* End screen */
.stk-end {
    display: none;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    min-height: 60vh;
    text-align: center;
    gap: 18px;
    padding: 24px;
    animation: stkFadeIn .5s ease;
}
.stk-end.active { display: flex; }
.stk-end-icon { font-size: 72px; animation: stkBounce .6s ease; }
.stk-end-title {
    font-family: 'Fredoka', sans-serif;
    font-size: clamp(28px, 6vw, 52px);
    font-weight: 700;
    color: #F97316;
}
.stk-end-sub { font-size: clamp(14px, 2.5vw, 18px); color: #7F77DD; font-weight: 700; }
.stk-restart-btn {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    padding: 14px 32px;
    border: none;
    border-radius: 50px;
    background: linear-gradient(135deg, #7F77DD, #6366f1);
    color: #fff;
    font-family: 'Fredoka', sans-serif;
    font-size: 20px;
    font-weight: 700;
    cursor: pointer;
    box-shadow: 0 8px 24px rgba(127,119,221,.35);
    transition: transform .15s, filter .15s;
}
.stk-restart-btn:hover { transform: scale(1.04); filter: brightness(1.07); }

/* Confetti */
.stk-confetti-container {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    pointer-events: none;
    overflow: hidden;
    z-index: 1000;
}
.stk-confetti-piece {
    position: absolute;
    top: -20px;
    width: 12px; height: 12px;
    border-radius: 2px;
    animation: stkConfettiFall linear forwards;
}

/* Animations */
@keyframes stkFadeIn {
    from { opacity: 0; transform: translateY(12px); }
    to   { opacity: 1; transform: translateY(0); }
}
@keyframes stkBounce {
    0%   { transform: scale(.5); }
    60%  { transform: scale(1.15); }
    100% { transform: scale(1); }
}
@keyframes stkConfettiFall {
    to { transform: translateY(110vh) rotate(720deg); opacity: 0; }
}
</style>

<div class="stk-viewer" data-az-zoom>

    <!-- Cover -->
    <div class="stk-cover" id="stkCover">
        <div class="stk-cover-icon">📖</div>
        <div class="stk-cover-title"><?= h($title) ?></div>
        <div class="stk-cover-sub"><?= count($pages) ?> page<?= count($pages) !== 1 ? 's' : '' ?></div>
        <button class="stk-start-btn" id="stkStartBtn">
            ▶ Start Reading
        </button>
    </div>

    <!-- Pages -->
    <?php foreach ($pages as $i => $page): ?>
    <div class="stk-page" id="stkPage<?= $i ?>"
         data-audio="<?= h($page['audio_url']) ?>">

        <!-- Progress -->
        <div class="stk-progress-wrap">
            <div class="stk-progress-bar" style="width:<?= round(($i + 1) / count($pages) * 100) ?>%"></div>
        </div>
        <div class="stk-progress-label">Page <?= $i + 1 ?> / <?= count($pages) ?></div>

        <!-- Image -->
        <?php if ($page['image_url'] !== ''): ?>
        <div class="stk-img-wrap">
            <img src="<?= h($page['image_url']) ?>" alt="<?= h('Page ' . ($i + 1)) ?>"
                 loading="<?= $i === 0 ? 'eager' : 'lazy' ?>">
        </div>
        <?php else: ?>
        <div class="stk-no-image">📖</div>
        <?php endif; ?>

        <!-- Text -->
        <?php if ($page['text'] !== ''): ?>
        <div class="stk-text"><?= h($page['text']) ?></div>
        <?php endif; ?>

        <!-- Nav -->
        <div class="stk-nav">
            <button class="stk-nav-btn stk-btn-prev" onclick="stkGo(<?= $i - 1 ?>)"
                    <?= $i === 0 ? 'disabled' : '' ?>>
                ← Back
            </button>
            <?php if ($page['audio_url'] !== ''): ?>
            <button class="stk-nav-btn stk-btn-audio" onclick="stkPlayAudio()" title="Listen again">
                🔊
            </button>
            <?php endif; ?>
            <?php if ($i < count($pages) - 1): ?>
            <button class="stk-nav-btn stk-btn-next" onclick="stkGo(<?= $i + 1 ?>)">
                Next →
            </button>
            <?php else: ?>
            <button class="stk-nav-btn stk-btn-next" onclick="stkFinish()">
                Finish 🎉
            </button>
            <?php endif; ?>
        </div>

    </div>
    <?php endforeach; ?>

    <!-- End screen -->
    <div class="stk-end" id="stkEnd">
        <div class="stk-end-icon">⭐</div>
        <div class="stk-end-title">Great job!</div>
        <div class="stk-end-sub">You finished the story!</div>
        <button class="stk-restart-btn" onclick="stkRestart()">
            🔄 Read Again
        </button>
    </div>

</div>

<!-- Confetti container -->
<div class="stk-confetti-container" id="stkConfetti"></div>

<!-- Hidden audio element -->
<audio id="stkAudio" preload="auto"></audio>

<script>
(function () {
    const PAGES     = <?= json_encode(array_values($pages), JSON_UNESCAPED_UNICODE) ?>;
    const cover     = document.getElementById('stkCover');
    const endScreen = document.getElementById('stkEnd');
    const audioEl   = document.getElementById('stkAudio');
    const confetti  = document.getElementById('stkConfetti');

    let current = -1; // -1 = cover

    /* ── Word highlighting state ── */
    let activeSpans  = [];
    let activeTimings = null; // null = use proportional fallback

    /* ── Page-flip sound via Web Audio API ── */
    function playFlipSound() {
        try {
            const AC = window.AudioContext || window.webkitAudioContext;
            if (!AC) return;
            const ctx    = new AC();
            const dur    = 0.14;
            const sr     = ctx.sampleRate;
            const buf    = ctx.createBuffer(1, Math.floor(sr * dur), sr);
            const data   = buf.getChannelData(0);
            for (let i = 0; i < data.length; i++) {
                const env = Math.pow(1 - i / data.length, 2.2);
                data[i] = (Math.random() * 2 - 1) * env * 0.35;
            }
            const src  = ctx.createBufferSource();
            src.buffer = buf;
            const bpf  = ctx.createBiquadFilter();
            bpf.type   = 'bandpass';
            bpf.frequency.value = 3200;
            bpf.Q.value = 0.6;
            const gain = ctx.createGain();
            gain.gain.setValueAtTime(0.7, ctx.currentTime);
            gain.gain.linearRampToValueAtTime(0, ctx.currentTime + dur);
            src.connect(bpf);
            bpf.connect(gain);
            gain.connect(ctx.destination);
            src.start();
            src.stop(ctx.currentTime + dur);
        } catch (_) {}
    }

    /* ── Build word spans inside .stk-text ── */
    function buildWordSpans(pageIdx) {
        const pageData = PAGES[pageIdx];
        if (!pageData) return;
        const textEl = document.querySelector('#stkPage' + pageIdx + ' .stk-text');
        if (!textEl) return;

        const rawText  = pageData.text || '';
        const keywords = new Set((pageData.keywords || []).map(k => k.toLowerCase().trim()));
        // Tokenise: keep words and whitespace/punctuation groups separately
        const tokens   = rawText.split(/(\s+)/);

        textEl.innerHTML = '';
        const spans = [];

        tokens.forEach(function (tok) {
            if (/^\s+$/.test(tok)) {
                textEl.appendChild(document.createTextNode(tok));
            } else {
                const sp  = document.createElement('span');
                sp.className = 'stk-word';
                // Strip punctuation for keyword matching
                const clean = tok.replace(/[^a-zA-Z0-9áéíóúÁÉÍÓÚñÑüÜ]/g, '').toLowerCase();
                if (keywords.has(clean)) sp.classList.add('stk-keyword');
                sp.textContent = tok;
                textEl.appendChild(sp);
                spans.push(sp);
            }
        });

        activeSpans   = spans;
        activeTimings = (pageData.word_timings && pageData.word_timings.length > 0)
            ? pageData.word_timings
            : null;
    }

    /* ── Single timeupdate listener (delegates to active spans) ── */
    audioEl.addEventListener('timeupdate', function () {
        if (!activeSpans.length) return;
        const t = audioEl.currentTime;
        let hit = -1;

        if (activeTimings && activeTimings.length === activeSpans.length) {
            // Use precise ElevenLabs timings
            for (let i = 0; i < activeTimings.length; i++) {
                if (t >= activeTimings[i].start && t <= activeTimings[i].end) {
                    hit = i;
                    break;
                }
            }
            // Fallback: last word whose start time has passed
            if (hit < 0) {
                for (let i = activeTimings.length - 1; i >= 0; i--) {
                    if (t >= activeTimings[i].start) { hit = i; break; }
                }
            }
        } else {
            // Proportional fallback: spread evenly across audio duration
            const dur = audioEl.duration;
            if (dur > 0 && isFinite(dur)) {
                hit = Math.min(Math.floor(t / dur * activeSpans.length), activeSpans.length - 1);
            }
        }

        activeSpans.forEach(function (sp, i) {
            sp.classList.toggle('stk-word-active', i === hit);
        });
    });

    audioEl.addEventListener('ended', function () {
        activeSpans.forEach(function (sp) { sp.classList.remove('stk-word-active'); });
    });

    function getPageEl(idx) {
        return document.getElementById('stkPage' + idx);
    }

    /* ── Show a specific page with flip animation ── */
    const FLIP_HALF = 160; // ms — half of flip animation (0.28s / 2 ≈ 140ms)

    window.stkGo = function (idx) {
        const dir = (current < 0 || idx > current) ? 'fwd' : 'bwd';

        // Clear word highlights immediately
        activeSpans.forEach(function (sp) { sp.classList.remove('stk-word-active'); });
        activeSpans   = [];
        activeTimings = null;

        // Flip sound
        playFlipSound();

        // Animate old page out
        cover.style.display = 'none';
        endScreen.classList.remove('active');

        if (current >= 0) {
            const oldEl = getPageEl(current);
            if (oldEl) {
                const outClass = dir === 'fwd' ? 'flip-out-fwd' : 'flip-out-bwd';
                oldEl.classList.add(outClass);
                setTimeout(function () {
                    oldEl.classList.remove('active', outClass);
                }, FLIP_HALF * 2);
            }
        }

        current = idx;

        // Bring in new page after half-flip delay
        setTimeout(function () {
            const newEl = getPageEl(idx);
            if (!newEl) return;
            const inClass = dir === 'fwd' ? 'flip-in-fwd' : 'flip-in-bwd';
            newEl.classList.add('active', inClass);
            setTimeout(function () { newEl.classList.remove(inClass); }, FLIP_HALF * 2);

            // Build word spans for highlighting
            buildWordSpans(idx);

            // Play audio
            const audioUrl = PAGES[idx] && PAGES[idx].audio_url ? PAGES[idx].audio_url : '';
            playAudio(audioUrl);
        }, FLIP_HALF);

        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    /* ── Start reading ── */
    document.getElementById('stkStartBtn').addEventListener('click', function () {
        stkGo(0);
    });

    /* ── Replay audio for current page ── */
    window.stkPlayAudio = function () {
        const url = PAGES[current] && PAGES[current].audio_url ? PAGES[current].audio_url : '';
        // Reset word spans for re-highlight
        activeSpans.forEach(function (sp) { sp.classList.remove('stk-word-active'); });
        playAudio(url);
    };

    function playAudio(url) {
        if (!url) return;
        audioEl.pause();
        audioEl.src = url;
        audioEl.load();
        audioEl.play().catch(function () {
            // Autoplay blocked — user can tap 🔊 button
        });
    }

    /* ── Finish ── */
    window.stkFinish = function () {
        playFlipSound();
        activeSpans.forEach(function (sp) { sp.classList.remove('stk-word-active'); });
        activeSpans   = [];
        activeTimings = null;
        if (current >= 0) {
            const el = getPageEl(current);
            if (el) el.classList.remove('active');
        }
        current = -1;
        audioEl.pause();
        endScreen.classList.add('active');
        launchConfetti();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    /* ── Restart ── */
    window.stkRestart = function () {
        endScreen.classList.remove('active');
        cover.style.display = '';
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    /* ── Confetti ── */
    function launchConfetti() {
        confetti.innerHTML = '';
        const colors = ['#F97316', '#7F77DD', '#22c55e', '#facc15', '#ec4899', '#38bdf8'];
        for (let i = 0; i < 60; i++) {
            const el = document.createElement('div');
            el.className = 'stk-confetti-piece';
            el.style.left  = Math.random() * 100 + 'vw';
            el.style.background = colors[Math.floor(Math.random() * colors.length)];
            el.style.width  = (8 + Math.random() * 10) + 'px';
            el.style.height = (8 + Math.random() * 10) + 'px';
            el.style.borderRadius = Math.random() > .5 ? '50%' : '2px';
            const dur   = (1.5 + Math.random() * 2).toFixed(2) + 's';
            const delay = (Math.random() * .8).toFixed(2) + 's';
            el.style.animationDuration = dur;
            el.style.animationDelay    = delay;
            confetti.appendChild(el);
        }
        setTimeout(function () { confetti.innerHTML = ''; }, 4000);
    }

    /* ── Swipe support ── */
    let touchStartX = 0;
    document.addEventListener('touchstart', function (e) {
        touchStartX = e.changedTouches[0].clientX;
    }, { passive: true });
    document.addEventListener('touchend', function (e) {
        const dx = e.changedTouches[0].clientX - touchStartX;
        if (Math.abs(dx) < 50) return;
        if (current < 0) return;
        if (dx < 0 && current < PAGES.length - 1) {
            stkGo(current + 1);
        } else if (dx > 0 && current > 0) {
            stkGo(current - 1);
        }
    }, { passive: true });

    /* ── Keyboard support ── */
    document.addEventListener('keydown', function (e) {
        if (current < 0) return;
        if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
            if (current < PAGES.length - 1) stkGo(current + 1);
            else stkFinish();
        } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
            if (current > 0) stkGo(current - 1);
        }
    });
})();
</script>

<?php
$content = ob_get_clean();
render_activity_viewer($title, '📖', $content);
