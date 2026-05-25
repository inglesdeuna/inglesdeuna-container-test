<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/tracing_functions.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

// Sanitize inputs

$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
$returnTo = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';
$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';

// Validate required params
if ($activityId === '' && $unit === '') {
    die('Activity not specified');
}

// Load activity
$activity = load_tracing_activity($pdo, $unit, $activityId);
$images = (!empty($activity['images']) && is_array($activity['images'])) ? array_values($activity['images']) : [];
$viewerTitle = !empty($activity['title']) ? (string) $activity['title'] : default_tracing_title();
if ($activityId === '' && !empty($activity['id'])) {
    $activityId = (string) $activity['id'];
}
if (empty($images)) {
    die('No tracing images found for this activity');
}

ob_start();
?>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">

:root{
    --tr-orange:#F97316;
    --tr-orange-dark:#C2580A;
    --tr-orange-soft:#FFF0E6;
    --tr-purple:#7F77DD;
    --tr-purple-dark:#534AB7;
    --tr-purple-soft:#EEEDFE;
    --tr-muted:#9B94BE;
    --tr-border:#F0EEF8;
}
/* --- Unscored completed screen styles (copied from flashcards/powerpoint) --- */
.af-unscored__card{background:#fff;border:1.5px solid #EDE9FA;border-radius:14px;padding:28px 32px;width:100%;max-width:100%;box-sizing:border-box;font-family:'Nunito','Segoe UI',sans-serif;}
.af-unscored__prog-label{font-size:11px;color:#9B8FCC;font-weight:700;letter-spacing:.06em;text-align:center;margin-bottom:6px;text-transform:uppercase;}
.af-unscored__prog-track{background:#EDE9FA;border-radius:99px;height:9px;overflow:hidden;margin-bottom:4px;}
.af-unscored__prog-fill{height:100%;border-radius:99px;background:linear-gradient(90deg,#F97316,#7F77DD);transition:width .4s ease;}
.af-unscored__prog-nums{display:flex;justify-content:space-between;font-size:11px;color:#9B8FCC;margin-bottom:16px;}
.af-unscored__prog-nums strong{color:#7F77DD;}
.af-unscored__icon{width:48px;height:48px;border-radius:50%;background:#EDE9FA;display:flex;align-items:center;justify-content:center;margin:0 auto 10px;}
.af-unscored__title{font-family:'Fredoka','Trebuchet MS',sans-serif;font-size:20px;font-weight:600;color:#7F77DD;text-align:center;margin:0 0 3px;}
.af-unscored__sub{font-size:13px;color:#9B8FCC;font-weight:600;text-align:center;margin:0 0 16px;}
.af-unscored__chips{display:grid;gap:8px;margin-bottom:16px;}
.af-unscored__chips--2{grid-template-columns:1fr 1fr;}
.af-unscored__chip{background:#F9F8FF;border:1.5px solid #EDE9FA;border-radius:12px;padding:10px 6px;text-align:center;}
.af-unscored__chip-val{font-family:'Fredoka','Trebuchet MS',sans-serif;font-size:24px;color:#7F77DD;line-height:1;}
.af-unscored__chip-lbl{font-size:10px;color:#9B8FCC;font-weight:700;letter-spacing:.05em;margin-top:2px;text-transform:uppercase;}
.af-unscored__banner{border-radius:12px;padding:9px 14px;display:flex;align-items:center;gap:10px;margin-bottom:16px;}
.af-unscored__banner--purple{background:#F5F3FF;}
.af-unscored__banner-icon{width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.af-unscored__banner-icon--purple{background:#7F77DD;}
.af-unscored__banner-text{font-size:12px;font-weight:600;}
.af-unscored__banner-text--purple{color:#5046a6;}
.af-unscored__banner-title{font-family:'Fredoka','Trebuchet MS',sans-serif;font-size:15px;display:block;}
.af-unscored__btns{display:flex;gap:8px;}
.af-unscored__btn-primary{flex:1;background:#F97316;color:#fff;border:none;border-radius:10px;padding:11px 0;font-family:'Nunito','Segoe UI',sans-serif;font-size:14px;font-weight:700;cursor:pointer;}
.af-unscored__btn-secondary{flex:1;background:#fff;color:#7F77DD;border:1.5px solid #EDE9FA;border-radius:10px;padding:11px 0;font-family:'Nunito','Segoe UI',sans-serif;font-size:14px;font-weight:700;cursor:pointer;}
:root{
    --tr-orange:#F97316;
    --tr-orange-dark:#C2580A;
    --tr-orange-soft:#FFF0E6;
    --tr-purple:#7F77DD;
    --tr-purple-dark:#534AB7;
    --tr-purple-soft:#EEEDFE;
    --tr-muted:#9B94BE;
    --tr-border:#F0EEF8;
}

.tr-page {
    width: 100%;
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    padding: clamp(14px,2.5vw,34px);
    display: flex;
    align-items: center;
    justify-content: center;
    background: #fff;
    box-sizing: border-box;
    height: 100%;
}
.tr-app {
    width: min(1120px, 100%);
    margin: 0 auto;
    height: 100%;
    display: flex;
    flex-direction: column;
    justify-content: center;
}
.tr-stage {
    background: #fff;
    border: 1px solid var(--tr-border);
    border-radius: 34px;
    padding: clamp(16px,2.6vw,26px);
    box-shadow: 0 8px 40px rgba(127,119,221,.13);
    box-sizing: border-box;
    display: flex;
    flex-direction: column;
    justify-content: center;
    height: 100%;
}
.tracing-viewer-shell {
    max-width: 100%;
    margin: 0 auto;
    height: 100%;
    display: flex;
    flex-direction: column;
    justify-content: center;
}
.tracing-stage {
    display: flex;
    flex-direction: column;
    gap: 14px;
    height: 100%;
    justify-content: center;
}
.tracing-canvas-wrap {
    display: flex;
    justify-content: center;
    align-items: center;
    height: 60vh;
    min-height: 320px;
    max-height: 70vh;
}
.tracing-canvas-shell {
    width: min(100%, 980px);
    border: 1px solid #EDE9FA;
    border-radius: 28px;
    overflow: hidden;
    background: #fff;
    box-shadow: 0 12px 36px rgba(127,119,221,.13);
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100%;
}
.tracing-canvas {
    display: block;
    cursor: crosshair;
    touch-action: none;
    width: 100%;
    height: auto;
    aspect-ratio: 68 / 50;
    max-height: 56vh;
}
.tracing-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 12px 0 2px;
}
.tracing-toolbar-label {
    font-size: 13px;
    font-weight: 800;
    color: var(--tr-muted);
}
.tracing-color-group, .tracing-size-group {
    display: flex;
    gap: 8px;
    align-items: center;
}
.tracing-color-swatch {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    border: 2px solid transparent;
    cursor: pointer;
    transition: border-color .15s;
}
.tracing-color-swatch.active {
    border-color: #1e293b;
}
.tracing-size-btn {
    width: 38px;
    height: 38px;
    border-radius: 12px;
    border: 2px solid #e2e8f0;
    background: #f8fafc;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: border-color .15s;
}
.tracing-size-btn.active {
    border-color: #2563eb;
}
.tracing-size-btn .dot {
    border-radius: 50%;
    background: #1e293b;
    display: block;
}
.tracing-actions {
    display: flex;
    justify-content: center;
    gap: 10px;
    padding-top: 6px;
}
.tracing-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 13px 32px;
    border: none;
    border-radius: 12px;
    font-weight: 900;
    font-family: 'Nunito','Segoe UI',sans-serif;
    font-size: clamp(15px,2vw,18px);
    min-width: 146px;
    line-height: 1;
    cursor: pointer;
    box-shadow: 0 6px 18px rgba(127,119,221,.18);
    transition: transform .15s ease, filter .15s ease;
}
.tracing-btn-next {
    background: var(--tr-purple);
    color: #fff;
    box-shadow: 0 10px 24px rgba(127,119,221,.24);
    border-radius: 12px;
}
.tracing-btn:hover {
    filter: brightness(1.04);
    transform: translateY(-1px);
}
.tracing-counter {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 800;
    font-size: 15px;
    color: var(--tr-muted);
}
.tracing-counter-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: #2563eb;
    flex-shrink: 0;
}
.tracing-completed { display: none; }
.tracing-completed.active { display: block; }
.is-hidden { display: none !important; }
@media (max-width:900px){
  .tr-page{padding:12px}
  .tr-stage{border-radius:26px;padding:14px}
}
</style>

<div class="tr-page">
<div class="tr-app">
    <div class="tr-hero">
        <div class="tr-kicker">Activity</div>
        <h1><?= htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8') ?></h1>
        <p>Choose a color, pick a pencil size, and trace each page in order.</p>
    </div>

    <div class="tr-stage">
<div class="tracing-viewer-shell">
    <div class="tracing-stage">

        <div class="tracing-counter" id="tracingCounter">
            <span class="tracing-counter-dot"></span>
            <span id="counterText">- / -</span>
        </div>

        <div class="tracing-canvas-wrap" id="tracingCanvasWrap">
            <div class="tracing-canvas-shell">
                <canvas id="traceCanvas" class="tracing-canvas" width="680" height="500"></canvas>
            </div>
        </div>

        <div class="tracing-toolbar" id="tracingToolbar">
            <span class="tracing-toolbar-label">Pencil Color</span>

            <div class="tracing-color-group">
                <?php
                $colors = [
                    '#2563eb', // blue
                    '#ef4444', // red
                    '#f59e0b', // orange
                    '#22c55e', // green
                    '#a855f7', // purple
                    '#ec4899', // pink
                    '#000000', // black
                    '#6b7280', // gray
                    '#38bdf8', // sky blue
                    '#fde047', // yellow
                    '#fbbf24', // amber
                    '#fff',    // white
                ];
                foreach ($colors as $i => $color):
                ?>
                    <button
                        type="button"
                        class="tracing-color-swatch <?= $i === 0 ? 'active' : '' ?>"
                        data-color="<?= htmlspecialchars($color) ?>"
                        style="background:<?= htmlspecialchars($color) ?>;">
                    </button>
                <?php endforeach; ?>
            </div>

            <span class="tracing-toolbar-label">Pencil Size</span>

            <div class="tracing-size-group">
                <?php
                $sizes = [4, 8, 14, 24];
                foreach ($sizes as $size):
                ?>
                    <button
                        type="button"
                        class="tracing-size-btn <?= $size === 8 ? 'active' : '' ?>"
                        data-size="<?= $size ?>">
                        <span class="dot" style="width:<?= $size ?>px;height:<?= $size ?>px;"></span>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="tracing-actions" id="tracingActions">
            <button type="button" class="tracing-btn tracing-btn-next" id="nextBtn">Next</button>
        </div>

        <div class="tracing-completed" id="tracingCompleted"></div>

    </div>
</div>
</div>
</div>
</div>

<script>
(function () {

    var images = <?= json_encode(array_values($images), JSON_UNESCAPED_UNICODE); ?>;
    var currentIdx = 0;
    var penSize = 8;
    var penColor = '#2563eb';
    var drawing = false;

    var canvas = document.getElementById('traceCanvas');
    var ctx = canvas.getContext('2d');
    var counterText = document.getElementById('counterText');
    var nextBtn = document.getElementById('nextBtn');
    var counterEl = document.getElementById('tracingCounter');
    var wrapEl = document.getElementById('tracingCanvasWrap');
    var toolbarEl = document.getElementById('tracingToolbar');
    var actionsEl = document.getElementById('tracingActions');
    var completedEl = document.getElementById('tracingCompleted');

    var activityTitle = <?= json_encode($viewerTitle, JSON_UNESCAPED_UNICODE); ?>;
    var ACTIVITY_ID = <?= json_encode($activityId, JSON_UNESCAPED_UNICODE); ?>;
    var RETURN_TO = <?= json_encode($returnTo, JSON_UNESCAPED_UNICODE); ?>;

    function persistScoreSilently(targetUrl) {
        if (!targetUrl) return Promise.resolve(false);
        return fetch(targetUrl, {
            method: 'GET', credentials: 'same-origin', cache: 'no-store',
        }).then(function (response) {
            return !!(response && response.ok);
        }).catch(function () { return false; });
    }

    function navigateToReturn(targetUrl) {
        if (!targetUrl) return;
        try {
            if (window.top && window.top !== window.self) {
                window.top.location.href = targetUrl;
                return;
            }
        } catch (e) {}
        window.location.href = targetUrl;
    }

    function getScaledPos(e, isTouch) {
        const rect = canvas.getBoundingClientRect();
        const clientX = isTouch ? e.touches[0].clientX : e.clientX;
        const clientY = isTouch ? e.touches[0].clientY : e.clientY;

        return {
            x: (clientX - rect.left) * (canvas.width / rect.width),
            y: (clientY - rect.top) * (canvas.height / rect.height)
        };
    }

    function drawGuide(url) {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        if (!url) return;

        const img = new Image();
        img.crossOrigin = 'anonymous';

        img.onload = () => {
            const scale = Math.min(canvas.width / img.width, canvas.height / img.height);
            const x = (canvas.width - img.width * scale) / 2;
            const y = (canvas.height - img.height * scale) / 2;
            ctx.drawImage(img, x, y, img.width * scale, img.height * scale);
        };

        img.src = url;
    }

    function renderPage() {
        if (!images.length) return;

        drawGuide(images[currentIdx].image);
        counterText.textContent = `${currentIdx + 1} / ${images.length}`;
        nextBtn.textContent = currentIdx < images.length - 1 ? 'Next' : 'Finish';
    }


    // --- Unscored completed page (flashcards/powerpoint style) ---
    var tracingRounds = 0;
    function showCompleted() {
        counterEl.classList.add('is-hidden');
        wrapEl.classList.add('is-hidden');
        toolbarEl.classList.add('is-hidden');
        actionsEl.classList.add('is-hidden');
        completedEl.classList.add('active');
        tracingRounds += 1;
        var total = images.length;
        completedEl.innerHTML =
            '<div class="af-unscored__card">' +
            '  <div class="af-unscored__prog-label">PAGES TRACED</div>' +
            '  <div class="af-unscored__prog-track"><div class="af-unscored__prog-fill" id="af-prog-fill" style="width:0%"></div></div>' +
            '  <div class="af-unscored__prog-nums"><span>0</span><strong id="af-prog-text">0 / 0</strong></div>' +
            '  <div class="af-unscored__icon"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#7F77DD" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg></div>' +
            '  <p class="af-unscored__title">All pages traced!</p>' +
            '  <p class="af-unscored__sub">You\'ve traced all the pages.</p>' +
            '  <div class="af-unscored__chips af-unscored__chips--2">' +
            '    <div class="af-unscored__chip"><div class="af-unscored__chip-val" id="af-stat1-val">0</div><div class="af-unscored__chip-lbl">PAGES</div></div>' +
            '    <div class="af-unscored__chip"><div class="af-unscored__chip-val" id="af-stat2-val">0</div><div class="af-unscored__chip-lbl">ROUNDS</div></div>' +
            '  </div>' +
            '  <div class="af-unscored__banner af-unscored__banner--purple">' +
            '    <div class="af-unscored__banner-icon af-unscored__banner-icon--purple"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></div>' +
            '    <div class="af-unscored__banner-text af-unscored__banner-text--purple"><span class="af-unscored__banner-title">Keep it up!</span>Practice makes perfect. Try the next activity.</div>' +
            '  </div>' +
            '  <div class="af-unscored__btns">' +
            '    <button class="af-unscored__btn-secondary" id="af-btn-retry">↺ Review again</button>' +
            '    <button class="af-unscored__btn-primary" id="af-btn-next" style="' + (RETURN_TO ? '' : 'display:none') + '">Next →</button>' +
            '  </div>' +
            '</div>';

        // Populate stats
        var fillEl    = document.getElementById('af-prog-fill');
        var textEl    = document.getElementById('af-prog-text');
        var stat1El   = document.getElementById('af-stat1-val');
        var stat2El   = document.getElementById('af-stat2-val');
        var retryBtn  = document.getElementById('af-btn-retry');
        var nextBtn2  = document.getElementById('af-btn-next');
        if (fillEl)  fillEl.style.width  = '100%';
        if (textEl)  textEl.textContent  = total + ' / ' + total;
        if (stat1El) stat1El.textContent = String(total);
        if (stat2El) stat2El.textContent = String(tracingRounds);
        if (retryBtn) retryBtn.onclick = function() {
            completedEl.classList.remove('active');
            completedEl.innerHTML = '';
            counterEl.classList.remove('is-hidden');
            wrapEl.classList.remove('is-hidden');
            toolbarEl.classList.remove('is-hidden');
            actionsEl.classList.remove('is-hidden');
            currentIdx = 0;
            renderPage();
        };
        if (nextBtn2) nextBtn2.onclick = function() {
            if (RETURN_TO) navigateToReturn(RETURN_TO);
        };
    }

    function showPassiveDone(containerEl, opts) {
        containerEl.innerHTML =
            '<div class="passive-done" id="passive-done-card">' +
            '  <div class="passive-done-icon">🎉</div>' +
            '  <h2 class="passive-done-title">All Done!</h2>' +
            '  <p class="passive-done-text">' + (opts.text || 'Great work!') + '</p>' +
            '  <div class="passive-done-track"><div class="passive-done-fill" id="passive-fill"></div></div>' +
            '  <div><button class="passive-done-btn" id="passive-restart-btn">&#8635; ' + (opts.restartLabel || 'Play Again') + '</button></div>' +
            '</div>';
        var card = document.getElementById('passive-done-card');
        var fill = document.getElementById('passive-fill');
        var btn  = document.getElementById('passive-restart-btn');
        requestAnimationFrame(function () {
            card.classList.add('active');
            setTimeout(function () { if (fill) fill.style.width = '100%'; }, 80);
        });
        if (btn && opts.onRestart) btn.addEventListener('click', opts.onRestart);
        if (opts.winAudio) { try { opts.winAudio.currentTime = 0; opts.winAudio.play(); } catch(e){} }
        if (opts.returnTo && opts.activityId) {
            var sep = opts.returnTo.indexOf('?') !== -1 ? '&' : '?';
            fetch(opts.returnTo + sep + 'activity_percent=100&activity_errors=0&activity_total=' + (opts.total||1) +
                '&activity_id=' + encodeURIComponent(opts.activityId) +
                '&activity_type=' + encodeURIComponent(opts.activityType || 'activity'),
                { method: 'GET', credentials: 'same-origin', cache: 'no-store' }).catch(function(){});
        }
    }

    function stroke(x, y) {
        ctx.lineWidth = penSize;
        ctx.strokeStyle = penColor;
        ctx.lineCap = 'round';

        ctx.lineTo(x, y);
        ctx.stroke();

        ctx.beginPath();
        ctx.moveTo(x, y);
    }

    canvas.addEventListener('mousedown', e => {
        drawing = true;
        const pos = getScaledPos(e, false);
        ctx.beginPath();
        ctx.moveTo(pos.x, pos.y);
    });

    canvas.addEventListener('mouseup', () => drawing = false);

    canvas.addEventListener('mousemove', e => {
        if (!drawing) return;
        const pos = getScaledPos(e, false);
        stroke(pos.x, pos.y);
    });

    document.querySelectorAll('.tracing-size-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.tracing-size-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            penSize = parseInt(btn.dataset.size) || 8;
        });
    });

    document.querySelectorAll('.tracing-color-swatch').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.tracing-color-swatch').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            penColor = btn.dataset.color || '#2563eb';
        });
    });

    nextBtn.addEventListener('click', () => {
        if (currentIdx < images.length - 1) {
            currentIdx++;
            renderPage();
        } else {
            showCompleted();
        }
    });

    // restartBtn is now injected by showPassiveDone inside completedEl

    renderPage();
})();
</script>

<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '✏️', $content);
