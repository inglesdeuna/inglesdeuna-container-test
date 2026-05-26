<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/tracing_functions.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

// Sanitize inputs
$unit       = isset($_GET['unit'])      ? trim((string) $_GET['unit'])      : '';
$returnTo   = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';
$activityId = isset($_GET['id'])        ? trim((string) $_GET['id'])        : '';

// Validate required params
if ($activityId === '' && $unit === '') {
    die('Activity not specified');
}

// Load activity
$activity       = load_tracing_activity($pdo, $unit, $activityId);
$images         = (!empty($activity['images']) && is_array($activity['images'])) ? array_values($activity['images']) : [];
$viewerTitle    = !empty($activity['title'])    ? (string) $activity['title']    : default_tracing_title();
$viewerSubtitle = !empty($activity['subtitle']) ? (string) $activity['subtitle'] : 'Choose a color, pick a pencil size, and trace each page in order.';

if ($activityId === '' && !empty($activity['id'])) {
    $activityId = (string) $activity['id'];
}
if (empty($images)) {
    die('No tracing images found for this activity');
}

ob_start();
?>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">

<style>
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

html,body{width:100%;height:100%;overflow:hidden}

body{
    margin:0!important;
    padding:0!important;
    background:#fff!important;
    font-family:'Nunito','Segoe UI',sans-serif!important;
}

.activity-wrapper{
    height:100vh!important;
    max-width:100%!important;
    margin:0!important;
    padding:0!important;
    display:flex!important;
    flex-direction:column!important;
    background:transparent!important;
    overflow:hidden!important;
}

.top-row,
.viewer-header,
.activity-header,
.activity-title,
.activity-subtitle{display:none!important}

.viewer-content{
    flex:1!important;
    min-height:0!important;
    display:flex!important;
    flex-direction:column!important;
    padding:0!important;
    margin:0!important;
    background:transparent!important;
    border:none!important;
    box-shadow:none!important;
    border-radius:0!important;
    overflow:hidden!important;
}

/* ── Full-height no-scroll page shell ── */
.tr-page{
    flex:1;
    min-height:0;
    overflow:hidden;
    padding:clamp(8px,1.2vw,16px) clamp(10px,1.5vw,20px);
    display:flex;
    flex-direction:column;
    background:#fff;
    box-sizing:border-box;
}

.tr-app{
    width:min(1200px,100%);
    margin:0 auto;
    flex:1;
    min-height:0;
    display:flex;
    flex-direction:column;
}

/* Compact hero */
.tr-hero{
    flex-shrink:0;
    text-align:center;
    padding-bottom:clamp(5px,0.8vh,10px);
}

.tr-kicker{display:none}

.tr-hero h1{
    font-family:'Fredoka',sans-serif;
    font-size:clamp(18px,2.8vw,34px);
    font-weight:700;
    color:var(--tr-orange);
    margin:0;
    line-height:1.1;
}

.tr-hero p{
    font-size:clamp(11px,1.1vw,13px);
    font-weight:700;
    color:var(--tr-muted);
    margin:3px 0 0;
}

/* Stage fills remaining space */
.tr-stage{
    flex:1;
    min-height:0;
    display:flex;
    flex-direction:column;
    background:#fff;
    border:1px solid var(--tr-border);
    border-radius:20px;
    padding:clamp(10px,1.4vw,18px);
    box-shadow:0 6px 28px rgba(127,119,221,.12);
    box-sizing:border-box;
    overflow:hidden;
}

.tracing-viewer-shell{flex:1;min-height:0;display:flex;flex-direction:column;overflow:hidden}
.tracing-stage{flex:1;min-height:0;display:flex;flex-direction:column;gap:8px;overflow:hidden}
.tracing-counter{flex-shrink:0;display:flex;align-items:center;gap:8px;font-weight:800;font-size:14px;color:var(--tr-muted)}
.tracing-counter-dot{width:9px;height:9px;border-radius:50%;background:#2563eb;flex-shrink:0}

/* Canvas area fills available space */
.tracing-canvas-wrap{
    flex:1;
    min-height:0;
    overflow:hidden;
    display:flex;
    align-items:center;
    justify-content:center;
}
.tracing-canvas-shell{
    max-width:100%;
    max-height:100%;
    aspect-ratio:68 / 50;
    border:1px solid #EDE9FA;
    border-radius:18px;
    overflow:hidden;
    background:#fff;
    box-shadow:0 8px 28px rgba(127,119,221,.12);
}
.tracing-canvas{
    display:block;
    cursor:crosshair;
    touch-action:none;
    width:100%;
    height:100%;
}

.tracing-toolbar{flex-shrink:0;display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:8px;padding:6px 0 0}
.tracing-toolbar-label{font-size:12px;font-weight:800;color:var(--tr-muted)}
.tracing-color-group,.tracing-size-group{display:flex;gap:6px;align-items:center}
.tracing-color-swatch{width:26px;height:26px;border-radius:50%;border:2px solid transparent;cursor:pointer;transition:border-color .15s}
.tracing-color-swatch.active{border-color:#1e293b}
.tracing-size-btn{width:34px;height:34px;border-radius:8px;border:2px solid #e2e8f0;background:#f8fafc;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:border-color .15s}
.tracing-size-btn.active{border-color:#2563eb}
.tracing-size-btn .dot{border-radius:50%;background:#1e293b;display:block}
.tracing-actions{flex-shrink:0;display:flex;justify-content:center;gap:10px;padding-top:6px}
.tracing-completed{display:none;flex:1;min-height:0;align-items:center;justify-content:center;padding:16px}
.tracing-completed.active{display:flex}
.is-hidden{display:none!important}

/* ── Unified unscored completed screen (mirrors flashcards) ── */
.af-unscored__card{background:#fff;border:1.5px solid #EDE9FA;border-radius:14px;padding:28px 32px;width:100%;max-width:520px;box-sizing:border-box;font-family:'Nunito','Segoe UI',sans-serif;}
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
.af-unscored__chip-val--orange{color:#F97316;}
.af-unscored__chip-lbl{font-size:10px;color:#9B8FCC;font-weight:700;letter-spacing:.05em;margin-top:2px;text-transform:uppercase;}
.af-unscored__banner{border-radius:12px;padding:9px 14px;display:flex;align-items:center;gap:10px;margin-bottom:16px;}
.af-unscored__banner--orange{background:#FFF0E6;}
.af-unscored__banner-icon{width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.af-unscored__banner-icon--orange{background:#F97316;}
.af-unscored__banner-text{font-size:12px;font-weight:600;}
.af-unscored__banner-text--orange{color:#b85a10;}
.af-unscored__banner-title{font-family:'Fredoka','Trebuchet MS',sans-serif;font-size:15px;display:block;}
.af-unscored__btns{display:flex;gap:8px;}
.af-unscored__btn-primary{flex:1;background:#F97316;color:#fff;border:none;border-radius:10px;padding:11px 0;font-family:'Nunito','Segoe UI',sans-serif;font-size:14px;font-weight:700;cursor:pointer;}
.af-unscored__btn-secondary{flex:1;background:#fff;color:#7F77DD;border:1.5px solid #EDE9FA;border-radius:10px;padding:11px 0;font-family:'Nunito','Segoe UI',sans-serif;font-size:14px;font-weight:700;cursor:pointer;}

.tracing-btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:10px clamp(24px,3vw,40px);
    border:none;
    border-radius:10px;
    font-weight:900;
    font-family:'Nunito','Segoe UI',sans-serif;
    font-size:clamp(13px,1.4vw,15px);
    min-width:clamp(100px,14vw,140px);
    line-height:1;
    cursor:pointer;
    box-shadow:0 4px 14px rgba(127,119,221,.18);
    transition:transform .15s ease, filter .15s ease;
}
.tracing-btn:hover{filter:brightness(1.04);transform:translateY(-1px)}
.tracing-btn-next{background:var(--tr-purple);color:#fff;box-shadow:0 6px 18px rgba(127,119,221,.24)}
</style>

<div class="tr-page">
<div class="tr-app">
    <div class="tr-hero">
        <div class="tr-kicker">Activity</div>
        <h1><?= htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8') ?></h1>
        <p><?= htmlspecialchars($viewerSubtitle, ENT_QUOTES, 'UTF-8') ?></p>
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
                        $colors = ['#2563eb','#ef4444','#f59e0b','#22c55e','#a855f7','#ec4899','#92400e','#111827','#67e8f9','#fbcfe8','#fdba74','#86efac','#c4b5fd','#d1d5db'];
                        foreach ($colors as $i => $color):
                        ?>
                            <button
                                type="button"
                                class="tracing-color-swatch <?= $i === 0 ? 'active' : '' ?>"
                                data-color="<?= htmlspecialchars($color, ENT_QUOTES, 'UTF-8') ?>"
                                style="background:<?= htmlspecialchars($color, ENT_QUOTES, 'UTF-8') ?>;">
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <span class="tracing-toolbar-label">Pencil Size</span>

                    <div class="tracing-size-group">
                        <?php
                        $sizes = [4, 8, 14];
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

    var images     = <?= json_encode(array_values($images), JSON_UNESCAPED_UNICODE); ?>;
    var currentIdx = 0;
    var penSize    = 8;
    var penColor   = '#2563eb';
    var drawing    = false;

    var canvas      = document.getElementById('traceCanvas');
    var ctx         = canvas.getContext('2d');
    var counterText = document.getElementById('counterText');
    var nextBtn     = document.getElementById('nextBtn');
    var counterEl   = document.getElementById('tracingCounter');
    var wrapEl      = document.getElementById('tracingCanvasWrap');
    var toolbarEl   = document.getElementById('tracingToolbar');
    var actionsEl   = document.getElementById('tracingActions');
    var completedEl = document.getElementById('tracingCompleted');
    var tracingRounds = 0;

    var ACTIVITY_ID = <?= json_encode($activityId, JSON_UNESCAPED_UNICODE); ?>;
    var RETURN_TO   = <?= json_encode($returnTo,   JSON_UNESCAPED_UNICODE); ?>;

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

    function getScaledPos(clientX, clientY) {
        var rect = canvas.getBoundingClientRect();
        return {
            x: (clientX - rect.left) * (canvas.width  / rect.width),
            y: (clientY - rect.top)  * (canvas.height / rect.height)
        };
    }

    function drawGuide(url) {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        if (!url) return;
        var img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = function () {
            var scale = Math.min(canvas.width / img.width, canvas.height / img.height);
            var x = (canvas.width  - img.width  * scale) / 2;
            var y = (canvas.height - img.height * scale) / 2;
            ctx.drawImage(img, x, y, img.width * scale, img.height * scale);
        };
        img.src = url;
    }

    function renderPage() {
        if (!images.length) return;
        drawGuide(images[currentIdx].image);
        counterText.textContent = (currentIdx + 1) + ' / ' + images.length;
        nextBtn.textContent = currentIdx < images.length - 1 ? 'Next' : 'Finish';
    }

    function stroke(x, y) {
        ctx.lineWidth   = penSize;
        ctx.strokeStyle = penColor;
        ctx.lineCap     = 'round';
        ctx.lineJoin    = 'round';
        ctx.lineTo(x, y);
        ctx.stroke();
        ctx.beginPath();
        ctx.moveTo(x, y);
    }

    // ── Mouse events ──────────────────────────────────────────────
    canvas.addEventListener('mousedown', function (e) {
        drawing = true;
        var pos = getScaledPos(e.clientX, e.clientY);
        ctx.beginPath();
        ctx.moveTo(pos.x, pos.y);
    });

    // mouseup on document so drawing stops even if cursor leaves canvas
    document.addEventListener('mouseup', function () { drawing = false; });

    canvas.addEventListener('mousemove', function (e) {
        if (!drawing) return;
        var pos = getScaledPos(e.clientX, e.clientY);
        stroke(pos.x, pos.y);
    });

    // ── Touch events ──────────────────────────────────────────────
    canvas.addEventListener('touchstart', function (e) {
        e.preventDefault();
        drawing = true;
        var t = e.touches[0];
        var pos = getScaledPos(t.clientX, t.clientY);
        ctx.beginPath();
        ctx.moveTo(pos.x, pos.y);
    }, { passive: false });

    canvas.addEventListener('touchend',   function () { drawing = false; }, { passive: true });
    canvas.addEventListener('touchcancel',function () { drawing = false; }, { passive: true });

    canvas.addEventListener('touchmove', function (e) {
        e.preventDefault();
        if (!drawing) return;
        var t = e.touches[0];
        var pos = getScaledPos(t.clientX, t.clientY);
        stroke(pos.x, pos.y);
    }, { passive: false });

    // ── Toolbar ───────────────────────────────────────────────────
    document.querySelectorAll('.tracing-size-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.tracing-size-btn').forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            penSize = parseInt(btn.dataset.size) || 8;
        });
    });

    document.querySelectorAll('.tracing-color-swatch').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.tracing-color-swatch').forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            penColor = btn.dataset.color || '#2563eb';
        });
    });

    // ── Next / Finish ─────────────────────────────────────────────
    nextBtn.addEventListener('click', function () {
        if (currentIdx < images.length - 1) {
            currentIdx++;
            renderPage();
        } else {
            showCompleted();
        }
    });

    function showCompleted() {
        tracingRounds++;
        counterEl.classList.add('is-hidden');
        wrapEl.classList.add('is-hidden');
        toolbarEl.classList.add('is-hidden');
        actionsEl.classList.add('is-hidden');

        var n = images.length;
        var nextBtn2 = RETURN_TO
            ? '<button class="af-unscored__btn-primary" id="tracingNextBtn">Next →</button>'
            : '';

        completedEl.innerHTML =
            '<div class="af-unscored__card">' +
                '<p class="af-unscored__prog-label">Pages Traced</p>' +
                '<div class="af-unscored__prog-track">' +
                    '<div class="af-unscored__prog-fill" style="width:100%"></div>' +
                '</div>' +
                '<div class="af-unscored__prog-nums">' +
                    '<span>0</span><strong>' + n + ' / ' + n + '</strong>' +
                '</div>' +
                '<div class="af-unscored__icon">' +
                    '<svg width="22" height="22" fill="none" viewBox="0 0 24 24">' +
                        '<path stroke="#7F77DD" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>' +
                    '</svg>' +
                '</div>' +
                '<p class="af-unscored__title">Tracing complete!</p>' +
                '<p class="af-unscored__sub">Great job! You traced all the pages.</p>' +
                '<div class="af-unscored__chips af-unscored__chips--2">' +
                    '<div class="af-unscored__chip">' +
                        '<div class="af-unscored__chip-val af-unscored__chip-val--orange">' + n + '</div>' +
                        '<div class="af-unscored__chip-lbl">Pages</div>' +
                    '</div>' +
                    '<div class="af-unscored__chip">' +
                        '<div class="af-unscored__chip-val">' + tracingRounds + '</div>' +
                        '<div class="af-unscored__chip-lbl">Rounds</div>' +
                    '</div>' +
                '</div>' +
                '<div class="af-unscored__banner af-unscored__banner--orange">' +
                    '<div class="af-unscored__banner-icon af-unscored__banner-icon--orange">' +
                        '<svg width="16" height="16" fill="none" viewBox="0 0 24 24">' +
                            '<path stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/>' +
                        '</svg>' +
                    '</div>' +
                    '<div class="af-unscored__banner-text af-unscored__banner-text--orange">' +
                        '<span class="af-unscored__banner-title">Keep up the great work!</span>' +
                        'Try tracing again to build muscle memory.' +
                    '</div>' +
                '</div>' +
                '<div class="af-unscored__btns">' +
                    '<button class="af-unscored__btn-secondary" id="tracingRestartBtn">↺ Review again</button>' +
                    nextBtn2 +
                '</div>' +
            '</div>';

        completedEl.classList.add('active');

        document.getElementById('tracingRestartBtn').addEventListener('click', function () {
            completedEl.classList.remove('active');
            completedEl.innerHTML = '';
            counterEl.classList.remove('is-hidden');
            wrapEl.classList.remove('is-hidden');
            toolbarEl.classList.remove('is-hidden');
            actionsEl.classList.remove('is-hidden');
            currentIdx = 0;
            renderPage();
        });

        if (RETURN_TO) {
            document.getElementById('tracingNextBtn').addEventListener('click', function () {
                navigateToReturn(RETURN_TO);
            });
        }

        // Report completion
        if (RETURN_TO && ACTIVITY_ID) {
            var sep = RETURN_TO.indexOf('?') !== -1 ? '&' : '?';
            fetch(
                RETURN_TO + sep +
                'activity_percent=100&activity_errors=0&activity_total=' + n +
                '&activity_id=' + encodeURIComponent(ACTIVITY_ID) +
                '&activity_type=tracing',
                { method: 'GET', credentials: 'same-origin', cache: 'no-store' }
            ).catch(function () {});
        }
    }

    renderPage();
})();
</script>

<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '✏️', $content);
