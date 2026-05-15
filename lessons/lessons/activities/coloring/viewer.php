<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/coloring_functions.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit       = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
$returnTo   = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';

$activity      = load_coloring_activity($pdo, $unit, $activityId);
$images        = isset($activity['images']) && is_array($activity['images']) ? $activity['images'] : array();
$activityTitle = isset($activity['title']) ? (string) $activity['title'] : default_coloring_title();
$nextUrl       = isset($_GET['next']) ? trim((string) $_GET['next']) : '';

/* Build a plain array of image URLs for JS */
$imageUrls = array_values(array_filter(array_map(function($img) {
    return isset($img['image']) ? (string) $img['image'] : '';
}, $images)));

ob_start();
?>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">
<style>
:root {
    --col-orange: #F97316;
    --col-orange-dark: #C2580A;
    --col-orange-soft: #FFF0E6;
    --col-purple: #7F77DD;
    --col-purple-dark: #534AB7;
    --col-muted: #9B94BE;
    --col-border: #F0EEF8;
}

* { box-sizing: border-box; }
.viewer-header { display: none !important; }

html,
body {
    width: 100%;
    min-height: 100%;
}

body {
    margin: 0 !important;
    padding: 0 !important;
    background: #fff !important;
    font-family: 'Nunito', 'Segoe UI', sans-serif !important;
}

.activity-wrapper {
    max-width: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
    min-height: 0;
    display: flex !important;
    flex-direction: column !important;
    background: transparent !important;
}

.top-row,
.viewer-header,
.activity-header,
.activity-title,
.activity-subtitle {
    display: none !important;
}

.viewer-content {
    flex: 1 !important;
    display: flex !important;
    flex-direction: column !important;
    min-height: 0 !important;
    padding: 0 !important;
    margin: 0 !important;
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
    border-radius: 0 !important;
}

.col-page {
    width: 100%;
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    padding: clamp(14px, 2.5vw, 34px);
    display: flex;
    align-items: flex-start;
    justify-content: center;
    background: #fff;
}

.col-app {
    width: min(1120px, 100%);
    margin: 0 auto;
}

.col-hero {
    text-align: center;
    margin-bottom: clamp(14px, 2vw, 22px);
}

.col-kicker {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 7px 14px;
    border-radius: 999px;
    background: var(--col-orange-soft);
    border: 1px solid #FCDDBF;
    color: var(--col-orange-dark);
    font-size: 12px;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
    margin-bottom: 10px;
}

.col-hero h1 {
    font-family: 'Fredoka', sans-serif;
    font-size: clamp(30px, 5.5vw, 58px);
    font-weight: 700;
    color: var(--col-orange);
    margin: 0;
    line-height: 1.03;
}

.col-hero p {
    font-size: clamp(13px, 1.8vw, 17px);
    font-weight: 800;
    color: var(--col-muted);
    margin: 8px 0 0;
}

.col-stage-shell {
    background: #fff;
    border: 1px solid var(--col-border);
    border-radius: 34px;
    padding: clamp(16px, 2.6vw, 26px);
    box-shadow: 0 8px 40px rgba(127, 119, 221, .13);
}

.board {
    width: min(100%, 980px);
    margin: 0 auto;
    border: 1px solid #EDE9FA;
    border-radius: 28px;
    background: #fff;
    box-shadow: 0 12px 36px rgba(127, 119, 221, .13);
    padding: clamp(14px, 2vw, 20px);
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.prog-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}

.prog-track {
    flex: 1;
    height: 12px;
    background: #F4F2FD;
    border: 1px solid #E4E1F8;
    border-radius: 999px;
    overflow: hidden;
}

.prog-fill {
    height: 100%;
    background: linear-gradient(90deg, #F97316, #7F77DD);
    border-radius: 999px;
    transition: width .45s ease;
    width: 0%;
}

.prog-badge {
    background: var(--col-purple);
    color: #fff;
    font-family: 'Nunito', sans-serif;
    font-weight: 900;
    font-size: 12px;
    border-radius: 999px;
    padding: 5px 11px;
    white-space: nowrap;
}

.picker-section {
    background: #F5F3FF;
    border: 1px solid #EDE9FA;
    border-radius: 18px;
    padding: 14px 16px;
}

.picker-label {
    font-size: 11px;
    font-weight: 900;
    font-family: 'Nunito', sans-serif;
    color: var(--col-muted);
    letter-spacing: .08em;
    text-transform: uppercase;
    text-align: center;
    margin-bottom: 12px;
}

.colors-grid {
    display: grid;
    grid-template-columns: repeat(8, minmax(36px, 1fr));
    gap: 10px;
    justify-items: center;
}

.swatch {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    cursor: pointer;
    border: 3px solid transparent;
    transition: transform .15s, box-shadow .15s;
    box-shadow: 0 2px 8px rgba(0, 0, 0, .12);
    -webkit-tap-highlight-color: transparent;
}

.swatch:hover { transform: scale(1.12); box-shadow: 0 4px 12px rgba(0, 0, 0, .2); }
.swatch.active { border-color: #271B5D; box-shadow: 0 0 0 3px #fff inset, 0 4px 12px rgba(0, 0, 0, .2); }

.sel-bar {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 2px 0;
}

.sel-dot {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    border: 2px solid #EDE9FA;
    transition: background .2s;
    flex-shrink: 0;
    background: #ef4444;
}

.sel-label {
    font-size: 12px;
    font-weight: 900;
    font-family: 'Nunito', sans-serif;
    color: var(--col-purple-dark);
}

.canvas-wrap {
    border: 1px solid #EDE9FA;
    border-radius: 20px;
    background: #fff;
    overflow: auto;
    min-height: clamp(360px, 52vw, 520px);
    max-height: clamp(380px, 56vw, 560px);
    display: flex;
    justify-content: center;
    align-items: center;
    touch-action: manipulation;
    padding: 10px;
}

#coloringCanvas {
    max-width: 100%;
    max-height: 100%;
    width: auto;
    height: auto;
    display: block;
    touch-action: manipulation;
    cursor: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='40' height='40' viewBox='0 0 40 40'%3E%3Cpath d='M20 4l10 12h-6v12h-8V16h-6z' fill='%2322c55e' stroke='%230f172a' stroke-width='2' stroke-linejoin='round'/%3E%3Ccircle cx='20' cy='33' r='4' fill='%23facc15' stroke='%230f172a' stroke-width='2'/%3E%3C/svg%3E") 20 10, pointer;
}

.bottom-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    flex-wrap: wrap;
    padding-top: 6px;
}

.page-info {
    font-size: 13px;
    font-weight: 900;
    font-family: 'Nunito', sans-serif;
    color: var(--col-muted);
}

.btns { display: flex; gap: 10px; flex-wrap: wrap; }

.btn-purple,
.btn-orange {
    border: none;
    border-radius: 999px;
    padding: 13px clamp(20px, 3vw, 32px);
    font-family: 'Nunito', sans-serif;
    font-weight: 900;
    font-size: clamp(13px, 1.8vw, 15px);
    cursor: pointer;
    min-width: clamp(104px, 16vw, 146px);
    transition: transform .15s, filter .15s;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.btn-purple {
    background: var(--col-purple);
    color: #fff;
    box-shadow: 0 6px 18px rgba(127, 119, 221, .18);
}

.btn-orange {
    background: var(--col-orange);
    color: #fff;
    box-shadow: 0 6px 18px rgba(249, 115, 22, .22);
}

.btn-purple:hover,
.btn-orange:hover { transform: translateY(-1px); filter: brightness(1.07); }

.coloring-completed {
    display: none;
    text-align: center;
    padding: 36px 20px 28px;
    flex-direction: column;
    align-items: center;
}

.coloring-completed.active {
    display: flex;
}

.board.is-completed .prog-row,
.board.is-completed .picker-section,
.board.is-completed .sel-bar,
.board.is-completed .canvas-wrap,
.board.is-completed .bottom-row {
    display: none;
}

@media (max-width: 900px) {
    .col-page { padding: 12px; }
    .col-stage-shell { border-radius: 26px; padding: 14px; }
    .board { border-radius: 22px; padding: 12px; }
    .colors-grid { grid-template-columns: repeat(4, minmax(36px, 1fr)); }
    .canvas-wrap { min-height: 300px; }
}
</style>

<div class="col-page">
    <div class="col-app">
        <div class="col-hero">
            <div class="col-kicker">Activity</div>
            <h1><?= htmlspecialchars($activityTitle, ENT_QUOTES, 'UTF-8') ?></h1>
            <p>Color the page below</p>
        </div>

        <div class="col-stage-shell">
            <div class="board" id="coloringStage">
                <div class="prog-row">
                    <div class="prog-track"><div class="prog-fill" id="progFill"></div></div>
                    <span class="prog-badge" id="progBadge">0/0</span>
                </div>

                <div class="picker-section">
                    <div class="picker-label">Select a color</div>
                    <div class="colors-grid" id="coloringPalette"></div>
                </div>

                <div class="sel-bar">
                    <div class="sel-dot" id="sel-dot"></div>
                    <span class="sel-label" id="sel-label">Red selected</span>
                </div>

                <div class="canvas-wrap">
                    <canvas id="coloringCanvas" width="600" height="600"></canvas>
                </div>

                <div class="bottom-row">
                    <span class="page-info" id="progressText">Page 1 of 1</span>
                    <div class="btns">
                        <button class="btn-orange" id="btn-reset" type="button">Clear</button>
                        <button class="btn-purple" id="btn-finish" type="button">Next</button>
                    </div>
                </div>

                <div class="coloring-completed" id="coloringCompleted">
                    <h2 style="font-family:'Fredoka';font-weight:700;font-size:28px;color:#F97316;margin:0 0 12px;">Perfect!</h2>
                    <p style="font-family:'Nunito';font-weight:700;font-size:14px;color:#9B94BE;margin:0;">You've finished coloring!</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    /* ── data from PHP ───────────────────────────────── */
    var uploadedImages = <?= json_encode($imageUrls, JSON_UNESCAPED_UNICODE) ?>;
    var nextActivityUrl = <?= json_encode($nextUrl, JSON_UNESCAPED_UNICODE) ?>;
    var COLORING_RETURN_TO = <?= json_encode($returnTo, JSON_UNESCAPED_UNICODE) ?>;
    var COLORING_ACTIVITY_ID = <?= json_encode($activityId, JSON_UNESCAPED_UNICODE) ?>;

    /* ── palette colors ──────────────────────────────── */
    var colors = [
        '#ef4444', '#f97316', '#cc7722', '#f5e6c8', '#facc15', '#22c55e', '#14b8a6', '#3b82f6',
        '#8b5cf6', '#c4b5fd', '#ec4899', '#8b5a2b', '#84cc16', '#9ca3af', '#111827', '#ffffff'
    ];
    var selectedColor = colors[0];

    /* ── state ───────────────────────────────────────── */
    var currentIndex      = 0;
    var originalImageData = null;

    /* ── color names ─────────────────────────────────── */
    var colorNames = {
        '#ef4444':'Red','#f97316':'Orange','#cc7722':'Brown','#f5e6c8':'Cream',
        '#facc15':'Yellow','#22c55e':'Green','#14b8a6':'Teal','#3b82f6':'Blue',
        '#8b5cf6':'Violet','#c4b5fd':'Lavender','#ec4899':'Pink','#8b5a2b':'Dark Brown',
        '#84cc16':'Lime','#9ca3af':'Gray','#111827':'Black','#ffffff':'White'
    };

    /* ── DOM refs ────────────────────────────────────── */
    var canvas       = document.getElementById('coloringCanvas');
    var ctx          = canvas.getContext('2d', { willReadFrequently: true });
    var progressText = document.getElementById('progressText');
    var progFill     = document.getElementById('progFill');
    var progBadge    = document.getElementById('progBadge');
    var selDot       = document.getElementById('sel-dot');
    var selLabel     = document.getElementById('sel-label');
    var stage        = document.getElementById('coloringStage');
    var completedEl  = document.getElementById('coloringCompleted');
    var finishBtn    = document.getElementById('btn-finish');
    var resetBtn     = document.getElementById('btn-reset');
    var restartBtn   = document.getElementById('restartBtn');
    var paletteEl    = document.getElementById('coloringPalette');
    var clickAudioCtx = null;

    function playClickSound() {
        try {
            var AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (!AudioCtx) return;
            if (!clickAudioCtx) clickAudioCtx = new AudioCtx();
            if (clickAudioCtx.state === 'suspended') {
                clickAudioCtx.resume();
            }

            var now = clickAudioCtx.currentTime;
            var osc = clickAudioCtx.createOscillator();
            var gain = clickAudioCtx.createGain();

            osc.type = 'triangle';
            osc.frequency.setValueAtTime(720, now);
            osc.frequency.exponentialRampToValueAtTime(980, now + 0.05);

            gain.gain.setValueAtTime(0.0001, now);
            gain.gain.exponentialRampToValueAtTime(0.12, now + 0.01);
            gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.08);

            osc.connect(gain);
            gain.connect(clickAudioCtx.destination);
            osc.start(now);
            osc.stop(now + 0.09);
        } catch (e) {
            /* no-op if audio is not available */
        }
    }

    /* ── build palette ───────────────────────────────── */
    function buildPalette() {
        paletteEl.innerHTML = '';
        colors.forEach(function (color) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'swatch' + (color === selectedColor ? ' active' : '');
            btn.style.background = color;
            btn.addEventListener('click', function () {
                playClickSound();
                selectedColor = color;
                paletteEl.querySelectorAll('.swatch').forEach(function (b) { b.classList.remove('active'); });
                btn.classList.add('active');
                selDot.style.background = color;
                selLabel.textContent = (colorNames[color] || color) + ' selected';
            });
            paletteEl.appendChild(btn);
        });
    }

    /* ── progress text ───────────────────────────────── */
    function updateProgress() {
        if (!uploadedImages.length) {
            progressText.textContent = 'No images';
            progBadge.textContent = '\u2014';
            progFill.style.width = '0%';
            return;
        }
        progressText.textContent = 'Page ' + (currentIndex + 1) + ' of ' + uploadedImages.length;
        progBadge.textContent = (currentIndex + 1) + ' / ' + uploadedImages.length;
        progFill.style.width = (((currentIndex + 1) / uploadedImages.length) * 100) + '%';
        finishBtn.textContent = currentIndex < uploadedImages.length - 1 ? 'Next →' : 'Finish ✓';
    }

    /* ── show / hide sections ────────────────────────── */
    function showCompleted() {
        stage.classList.add('is-completed');
        completedEl.classList.add('active');
        if (COLORING_RETURN_TO && COLORING_ACTIVITY_ID) {
            var joiner = COLORING_RETURN_TO.indexOf('?') !== -1 ? '&' : '?';
            var saveUrl = COLORING_RETURN_TO + joiner +
                'activity_percent=100&activity_errors=0&activity_total=1' +
                '&activity_id=' + encodeURIComponent(COLORING_ACTIVITY_ID) +
                '&activity_type=coloring';
            fetch(saveUrl, { method: 'GET', credentials: 'same-origin', cache: 'no-store' })
                .then(function (r) { if (!r.ok) throw new Error(); })
                .catch(function () {
                    try {
                        if (window.top && window.top !== window.self) { window.top.location.href = saveUrl; return; }
                    } catch (e) { /* no-op */ }
                });
        }
    }

    /* ── resize canvas on window resize ──────────────– */
    function resizeCanvas() {
        if (!uploadedImages.length || currentIndex >= uploadedImages.length) return;
        loadImageAt(currentIndex);
    }

    /* ── paint system ────────────────────────────────– */
    function getPixels(x, y) {
        if (!ctx || !canvas) return null;
        try {
            var imgData = ctx.getImageData(x, y, 1, 1);
            return imgData.data;
        } catch (e) {
            return null;
        }
    }

    function hex2rgb(hex) {
        var result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
        return result ? [
            parseInt(result[1], 16),
            parseInt(result[2], 16),
            parseInt(result[3], 16)
        ] : [0, 0, 0];
    }

    function pixelsMatch(px, rgb, tolerance) {
        if (!px) return false;
        tolerance = tolerance || 30;
        return Math.abs(px[0] - rgb[0]) < tolerance &&
               Math.abs(px[1] - rgb[1]) < tolerance &&
               Math.abs(px[2] - rgb[2]) < tolerance;
    }

    function floodFill(x, y, newColor) {
        if (!ctx || !canvas) return;
        x = Math.floor(x);
        y = Math.floor(y);
        if (x < 0 || y < 0 || x >= canvas.width || y >= canvas.height) return;
        
        var newRgb = hex2rgb(newColor);
        var targetPixels = getPixels(x, y);
        if (!targetPixels || pixelsMatch(targetPixels, newRgb, 10)) return;

        var imgData = ctx.getImageData(0, 0, canvas.width, canvas.height);
        var data = imgData.data;
        var stack = [[x, y]];
        var visited = {};

        while (stack.length) {
            var coord = stack.pop();
            var cx = coord[0];
            var cy = coord[1];
            var key = cx + ',' + cy;
            if (visited[key]) continue;
            visited[key] = true;

            if (cx < 0 || cy < 0 || cx >= canvas.width || cy >= canvas.height) continue;
            var idx = (cy * canvas.width + cx) * 4;
            var px = [data[idx], data[idx+1], data[idx+2], data[idx+3]];
            if (!pixelsMatch(px, targetPixels)) continue;

            data[idx] = newRgb[0];
            data[idx+1] = newRgb[1];
            data[idx+2] = newRgb[2];
            stack.push([cx+1, cy], [cx-1, cy], [cx, cy+1], [cx, cy-1]);
        }
        ctx.putImageData(imgData, 0, 0);
    }

    function handleFill(e) {
        if (!canvas || !ctx) return;
        var rect = canvas.getBoundingClientRect();
        var x = (e.touches ? e.touches[0].clientX : e.clientX) - rect.left;
        var y = (e.touches ? e.touches[0].clientY : e.clientY) - rect.top;
        floodFill(x, y, selectedColor);
        if (e.preventDefault) e.preventDefault();
    }

    /* ── load image ──────────────────────────────────– */
    function loadImageAt(idx) {
        if (!uploadedImages.length || idx >= uploadedImages.length || !ctx || !canvas) return;
        
        var img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = function () {
            var ratio = img.width / img.height;
            var maxW = canvas.parentElement.clientWidth - 20;
            var maxH = canvas.parentElement.clientHeight - 20;
            var w = Math.min(600, maxW);
            var h = w / ratio;
            if (h > maxH) {
                h = maxH;
                w = h * ratio;
            }
            canvas.width = w;
            canvas.height = h;
            ctx.drawImage(img, 0, 0, w, h);
            try {
                originalImageData = ctx.getImageData(0, 0, w, h);
            } catch (e) { /* no-op */ }
        };
        img.onerror = function () {
            console.warn('Failed to load image:', uploadedImages[idx]);
        };
        img.src = uploadedImages[idx];
    }

    /* ── canvas fill handlers ───────────────────────── */
    canvas.addEventListener('click',      handleFill);
    canvas.addEventListener('touchstart', handleFill, { passive: false });

    /* ── button handlers ─────────────────────────────– */
    finishBtn.addEventListener('click', function () {
        if (currentIndex < uploadedImages.length - 1) {
            currentIndex++;
            loadImageAt(currentIndex);
            updateProgress();
        } else if (nextActivityUrl) {
            window.location.href = nextActivityUrl;
        } else {
            showCompleted();
        }
    });

    resetBtn.addEventListener('click', function () {
        if (uploadedImages.length && currentIndex < uploadedImages.length) {
            loadImageAt(currentIndex);
        }
    });

    window.addEventListener('resize', function () {
        if (!uploadedImages.length || currentIndex >= uploadedImages.length) return;
        loadImageAt(currentIndex);
    });

    /* ── init ────────────────────────────────────────── */
    buildPalette();
    if (uploadedImages.length > 0) {
        loadImageAt(0);
    } else {
        progressText.textContent = 'No images uploaded for this activity.';
    }
}());
</script>
<?php
$content = ob_get_clean();
render_activity_viewer($activityTitle, '&#x1F3A8;', $content);
?>
