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

html,body{width:100%;min-height:100%}

body{
    margin:0!important;
    padding:0!important;
    background:#fff!important;
    font-family:'Nunito','Segoe UI',sans-serif!important;
}

.activity-wrapper{
    max-width:100%!important;
    margin:0!important;
    padding:0!important;
    min-height:0;
    display:flex!important;
    flex-direction:column!important;
    background:transparent!important;
}

.top-row,
.viewer-header,
.activity-header,
.activity-title,
.activity-subtitle{display:none!important}

.viewer-content{
    flex:1!important;
    display:flex!important;
    flex-direction:column!important;
    padding:0!important;
    margin:0!important;
    background:transparent!important;
    border:none!important;
    box-shadow:none!important;
    border-radius:0!important;
}

.tr-page{
    width:100%;
    flex:1;
    min-height:0;
    overflow-y:auto;
    padding:clamp(14px,2.5vw,34px);
    display:flex;
    align-items:flex-start;
    justify-content:center;
    background:#fff;
    box-sizing:border-box;
}

.tr-app{width:min(1120px,100%);margin:0 auto}

.tr-hero{text-align:center;margin-bottom:clamp(14px,2vw,22px)}

.tr-kicker{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:7px 14px;
    border-radius:999px;
    background:var(--tr-orange-soft);
    border:1px solid #FCDDBF;
    color:var(--tr-orange-dark);
    font-size:12px;
    font-weight:900;
    letter-spacing:.08em;
    text-transform:uppercase;
    margin-bottom:10px;
}

.tr-hero h1{
    font-family:'Fredoka',sans-serif;
    font-size:clamp(30px,5.5vw,58px);
    font-weight:700;
    color:var(--tr-orange);
    margin:0;
    line-height:1.03;
}

.tr-hero p{
    font-size:clamp(13px,1.8vw,17px);
    font-weight:800;
    color:var(--tr-muted);
    margin:8px 0 0;
}

.tr-stage{
    background:#fff;
    border:1px solid var(--tr-border);
    border-radius:34px;
    padding:clamp(16px,2.6vw,26px);
    box-shadow:0 8px 40px rgba(127,119,221,.13);
    box-sizing:border-box;
}

.tracing-viewer-shell{max-width:100%;margin:0 auto}
.tracing-stage{display:flex;flex-direction:column;gap:14px}
.tracing-counter{display:flex;align-items:center;gap:8px;font-weight:800;font-size:15px;color:var(--tr-muted)}
.tracing-counter-dot{width:10px;height:10px;border-radius:50%;background:#2563eb;flex-shrink:0}
.tracing-canvas-wrap{display:flex;justify-content:center}
.tracing-canvas-shell{width:min(100%,980px);border:1px solid #EDE9FA;border-radius:28px;overflow:hidden;background:#fff;box-shadow:0 12px 36px rgba(127,119,221,.13)}
.tracing-canvas{display:block;cursor:crosshair;touch-action:none;width:100%;height:auto;aspect-ratio:68 / 50}
.tracing-toolbar{display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:10px;padding:12px 0 2px}
.tracing-toolbar-label{font-size:13px;font-weight:800;color:var(--tr-muted)}
.tracing-color-group,.tracing-size-group{display:flex;gap:8px;align-items:center}
.tracing-color-swatch{width:30px;height:30px;border-radius:50%;border:2px solid transparent;cursor:pointer;transition:border-color .15s}
.tracing-color-swatch.active{border-color:#1e293b}
.tracing-size-btn{width:38px;height:38px;border-radius:50%;border:2px solid #e2e8f0;background:#f8fafc;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:border-color .15s}
.tracing-size-btn.active{border-color:#2563eb}
.tracing-size-btn .dot{border-radius:50%;background:#1e293b;display:block}
.tracing-actions{display:flex;justify-content:center;gap:10px;padding-top:6px}
.tracing-completed{display:none}
.tracing-completed.active{display:block}
.is-hidden{display:none!important}

.tracing-btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:13px clamp(20px,3vw,32px);
    border:none;
    border-radius:999px;
    font-weight:900;
    font-family:'Nunito','Segoe UI',sans-serif;
    font-size:clamp(13px,1.8vw,15px);
    min-width:clamp(104px,16vw,146px);
    line-height:1;
    cursor:pointer;
    box-shadow:0 6px 18px rgba(127,119,221,.18);
    transition:transform .15s ease, filter .15s ease;
}
.tracing-btn:hover{filter:brightness(1.04);transform:translateY(-1px)}
.tracing-btn-next{background:var(--tr-purple);color:#fff;box-shadow:0 10px 24px rgba(127,119,221,.24)}

/* passive-done completed card */
.passive-done{
    display:none;
    width:min(680px,100%);
    margin:24px auto 0;
    text-align:center;
    padding:clamp(28px,5vw,54px);
    border-radius:34px;
    background:#fff;
    border:1px solid #E2F7EF;
    box-shadow:0 8px 40px rgba(8,80,65,.12);
}
.passive-done.active{display:block;animation:passivePop .45s cubic-bezier(.2,.9,.2,1)}
@keyframes passivePop{from{opacity:0;transform:scale(.92)}to{opacity:1;transform:scale(1)}}
.passive-done-icon{font-size:clamp(66px,12vw,100px);margin-bottom:12px}
.passive-done-title{margin:0 0 10px;font-family:'Fredoka',sans-serif;font-size:clamp(34px,6vw,60px);color:#085041;line-height:1}
.passive-done-text{margin:0 auto 22px;max-width:520px;color:#7C739B;font-size:clamp(14px,2vw,17px);font-weight:800;line-height:1.5}
.passive-done-track{height:14px;max-width:420px;margin:0 auto 18px;border-radius:999px;background:#E2F7EF;overflow:hidden}
.passive-done-fill{height:100%;width:0%;border-radius:999px;background:linear-gradient(90deg,#1D9E75,#7F77DD,#EC4899);transition:width .8s cubic-bezier(.2,.9,.2,1)}
.passive-done-btn{display:inline-flex;align-items:center;gap:8px;padding:13px 28px;border-radius:999px;border:0;background:#1D9E75;color:#fff;font-family:'Nunito',sans-serif;font-size:15px;font-weight:900;cursor:pointer;box-shadow:0 6px 18px rgba(29,158,117,.30);transition:.18s}
.passive-done-btn:hover{transform:translateY(-2px)}

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

        completedEl.innerHTML =
            '<div class="passive-done" id="passive-done-card">' +
            '  <div class="passive-done-icon">🎉</div>' +
            '  <h2 class="passive-done-title">All Done!</h2>' +
            '  <p class="passive-done-text">You traced all ' + images.length + ' pages. Great job practicing!</p>' +
            '  <div class="passive-done-track"><div class="passive-done-fill" id="passive-fill"></div></div>' +
            '  <div><button class="passive-done-btn" id="passive-restart-btn">↻ Play Again</button></div>' +
            '</div>';

        var card       = document.getElementById('passive-done-card');
        var fill       = document.getElementById('passive-fill');
        var restartBtn = document.getElementById('passive-restart-btn');

        requestAnimationFrame(function () {
            card.classList.add('active');
            setTimeout(function () { if (fill) fill.style.width = '100%'; }, 80);
        });

        if (restartBtn) {
            restartBtn.addEventListener('click', function () {
                completedEl.classList.remove('active');
                completedEl.innerHTML = '';
                counterEl.classList.remove('is-hidden');
                wrapEl.classList.remove('is-hidden');
                toolbarEl.classList.remove('is-hidden');
                actionsEl.classList.remove('is-hidden');
                currentIdx = 0;
                renderPage();
            });
        }

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

    renderPage();
})();
</script>

<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '✏️', $content);
