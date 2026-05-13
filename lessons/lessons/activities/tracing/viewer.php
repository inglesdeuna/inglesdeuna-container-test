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

html,
body{width:100%;min-height:100%}

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
.tracing-completed{display:none;text-align:center;padding:24px 16px}
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

#tracingCompletedTitle{
    font-family:'Fredoka',sans-serif;
    font-size:clamp(30px,5.5vw,58px);
    color:var(--tr-orange);
    margin:0;
    line-height:1.03;
}

#tracingCompletedText{
    color:var(--tr-muted);
    font-size:clamp(13px,1.8vw,17px);
    font-weight:800;
    line-height:1.5;
    margin:10px 0 14px;
}

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
                $colors = ['#2563eb','#ef4444','#f59e0b','#22c55e','#a855f7','#ec4899'];
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
            <div style="font-size:86px;line-height:1;">✍️</div>
            <h2 id="tracingCompletedTitle"></h2>
            <p id="tracingCompletedText"></p>
            <p id="tracingScoreText" style="font-weight:700;font-size:18px;color:#0f766e;"></p>
            <button type="button" class="tracing-btn tracing-btn-next" id="restartBtn">Restart</button>
        </div>

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
    var restartBtn = document.getElementById('restartBtn');
    var counterEl = document.getElementById('tracingCounter');
    var wrapEl = document.getElementById('tracingCanvasWrap');
    var toolbarEl = document.getElementById('tracingToolbar');
    var actionsEl = document.getElementById('tracingActions');
    var completedEl = document.getElementById('tracingCompleted');
    var completedTitleEl = document.getElementById('tracingCompletedTitle');
    var completedTextEl = document.getElementById('tracingCompletedText');
    var scoreTextEl = document.getElementById('tracingScoreText');

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


    function showCompleted() {
        counterEl.classList.add('is-hidden');
        wrapEl.classList.add('is-hidden');
        toolbarEl.classList.add('is-hidden');
        actionsEl.classList.add('is-hidden');
        completedEl.classList.add('active');

        if (completedTitleEl) completedTitleEl.textContent = activityTitle || 'Tracing Practice';
        if (completedTextEl) completedTextEl.textContent = "You've completed " + (activityTitle || 'this activity') + '. Great job practicing.';
        if (scoreTextEl) scoreTextEl.textContent = 'Score: ' + images.length + ' / ' + images.length + ' (100%)';

        if (RETURN_TO && ACTIVITY_ID) {
            var joiner = RETURN_TO.indexOf('?') !== -1 ? '&' : '?';
            var saveUrl = RETURN_TO + joiner +
                'activity_percent=100' +
                '&activity_errors=0' +
                '&activity_total=' + images.length +
                '&activity_id=' + encodeURIComponent(ACTIVITY_ID) +
                '&activity_type=tracing';
            persistScoreSilently(saveUrl).then(function (ok) {
                if (!ok) navigateToReturn(saveUrl);
            });
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

    restartBtn.addEventListener('click', () => {
        currentIdx = 0;
        counterEl.classList.remove('is-hidden');
        wrapEl.classList.remove('is-hidden');
        toolbarEl.classList.remove('is-hidden');
        actionsEl.classList.remove('is-hidden');
        completedEl.classList.remove('active');
        renderPage();
    });

    renderPage();
})();
</script>

<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '✏️', $content);
