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
.tracing-completed{
    display:none;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    text-align:center;
    padding:40px 24px;
    gap:12px;
    flex:1;
    min-height:0;
}
.tracing-completed.active{display:flex}
.tr-done-icon{font-size:56px;line-height:1}
.tr-done-title{font-family:'Fredoka',sans-serif;font-size:26px;font-weight:700;color:var(--tr-purple-dark);margin:0}
.tr-done-msg{font-size:13px;font-weight:600;color:#5a7a6a;margin:0}
.is-hidden{display:none!important}

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
                        $colors = ['#2563eb','#ef4444','#f59e0b','#22c55e','#a855f7','#ec4899'];
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

                <div class="tracing-completed" id="tracingCompleted">
                    <div class="tr-done-icon">&#x2705;</div>
                    <h2 class="tr-done-title">All done!</h2>
                    <p class="tr-done-msg">You traced all the pages. Great job practicing!</p>
                    <button type="button" class="tracing-btn tracing-btn-next" id="tracingRestartBtn">&#8635; Play Again</button>
                </div>

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
        counterEl.classList.add('is-hidden');
        wrapEl.classList.add('is-hidden');
        toolbarEl.classList.add('is-hidden');
        actionsEl.classList.add('is-hidden');
        completedEl.classList.add('active');

        // Report completion score
        if (RETURN_TO && ACTIVITY_ID) {
            var sep = RETURN_TO.indexOf('?') !== -1 ? '&' : '?';
            fetch(
                RETURN_TO + sep +
                'activity_percent=100&activity_errors=0&activity_total=' + images.length +
                '&activity_id=' + encodeURIComponent(ACTIVITY_ID) +
                '&activity_type=tracing',
                { method: 'GET', credentials: 'same-origin', cache: 'no-store' }
            ).catch(function () {});
        }
    }

    document.getElementById('tracingRestartBtn').addEventListener('click', function () {
        completedEl.classList.remove('active');
        counterEl.classList.remove('is-hidden');
        wrapEl.classList.remove('is-hidden');
        toolbarEl.classList.remove('is-hidden');
        actionsEl.classList.remove('is-hidden');
        currentIdx = 0;
        renderPage();
    });

    renderPage();
})();
</script>

<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '✏️', $content);
