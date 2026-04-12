<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/coloring_functions.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit       = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';

$activity      = load_coloring_activity($pdo, $unit, $activityId);
$images        = isset($activity['images']) && is_array($activity['images']) ? $activity['images'] : array();
$activityTitle = isset($activity['title']) ? (string) $activity['title'] : default_coloring_title();

/* Build a plain array of image URLs for JS */
$imageUrls = array_values(array_filter(array_map(function($img) {
    return isset($img['image']) ? (string) $img['image'] : '';
}, $images)));

ob_start();
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800&display=swap');
* { box-sizing: border-box; }
.viewer-header { display: none !important; }
/* ── app wrapper ─────────────────────────────────────── */
.coloring-app { max-width: 980px; margin: 0 auto; padding: 16px; font-family: 'Nunito','Segoe UI',sans-serif; color: #4b3b47; }
.coloring-title-bar { text-align: center; font-family: 'Fredoka','Trebuchet MS',sans-serif; font-size: 28px; font-weight: 700; color: #7c3aed; margin-bottom: 14px; }
/* ── controls ───────────────────────────────────────── */
.coloring-controls { display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; align-items: center; padding: 14px 0 4px; margin-top: 12px; }
.coloring-reset-btn,.coloring-next-btn { border: none; border-radius: 999px; padding: 11px 22px; font-size: 14px; font-weight: 800; cursor: pointer; font-family: inherit; color: #fff; min-width: 130px; box-shadow: 0 10px 22px rgba(15,23,42,.14); transition: transform .15s, filter .15s; }
.coloring-reset-btn:hover,.coloring-next-btn:hover { filter: brightness(1.04); transform: translateY(-1px); }
.coloring-reset-btn { background: linear-gradient(180deg, #f59eb2 0%, #ec4899 100%); }
.coloring-next-btn  { background: linear-gradient(180deg, #5eead4 0%, #14b8a6 100%); }
.coloring-progress  { font-size: 15px; font-weight: 800; color: #7c3aed; }
/* ── palette ─────────────────────────────────────────── */
.coloring-palette-wrap { background: #fff; border-radius: 22px; padding: 14px; box-shadow: 0 6px 20px rgba(0,0,0,.08); margin-bottom: 16px; }
.coloring-palette-heading { text-align: center; font-size: 16px; font-weight: 800; color: #4b3b47; margin-bottom: 10px; }
.coloring-palette { display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; }
.coloring-color-btn {
    width: 50px; height: 50px; border-radius: 50%; border: 4px solid transparent;
    cursor: pointer; transition: transform .15s; -webkit-tap-highlight-color: transparent;
}
.coloring-color-btn:hover { transform: scale(1.07); }
.coloring-color-btn.active { border-color: #444; transform: scale(1.12); }
/* ── stage ───────────────────────────────────────────── */
.coloring-stage { background: #fff; border-radius: 28px; box-shadow: 0 10px 28px rgba(0,0,0,.08); padding: 16px; }
.coloring-canvas-wrap { display: flex; justify-content: center; align-items: center; overflow: hidden; border-radius: 16px; background: #fff; touch-action: manipulation; }
#coloringCanvas { max-width: 100%; height: auto; display: block; touch-action: manipulation; border-radius: 14px; cursor: default; }
.coloring-helper { text-align: center; margin-top: 10px; font-size: 14px; color: #7a6874; font-weight: 700; }
/* ── completed ───────────────────────────────────────── */
.coloring-completed { display: none; text-align: center; padding: 50px 20px 30px; flex-direction: column; align-items: center; }
.coloring-completed.active { display: flex; }
.coloring-completed-emoji { font-size: 88px; line-height: 1; margin-bottom: 14px; }
.coloring-completed-title { font-family: 'Fredoka','Trebuchet MS',sans-serif; font-size: clamp(32px,4vw,48px); font-weight: 700; color: #ea580c; margin: 0 0 8px; }
.coloring-completed-sub { font-size: 17px; font-weight: 700; color: #374151; margin: 0 0 26px; }
.coloring-btn-restart { padding: 13px 36px; font-size: 17px; font-weight: 800; font-family: 'Fredoka','Nunito',sans-serif; border: none; border-radius: 999px; cursor: pointer; color: #fff; background: linear-gradient(180deg, #8b5cf6 0%, #7c3aed 100%); box-shadow: 0 10px 24px rgba(0,0,0,.14); }
.coloring-no-images { text-align: center; padding: 40px 20px; font-size: 16px; font-weight: 700; color: #7c3aed; }
@media (max-width: 640px) {
    .coloring-title-bar { font-size: 22px; }
    .coloring-reset-btn,.coloring-next-btn { width: 100%; }
    .coloring-color-btn { width: 44px; height: 44px; }
}
</style>

<div class="coloring-app">

    <!-- color palette -->
    <div class="coloring-palette-wrap" id="coloringPaletteWrap">
        <div class="coloring-palette-heading">Choose a color</div>
        <div class="coloring-palette" id="coloringPalette"></div>
    </div>

    <!-- canvas stage -->
    <div class="coloring-stage" id="coloringStage">
        <div class="coloring-canvas-wrap">
            <canvas id="coloringCanvas"></canvas>
        </div>
        <div class="coloring-helper">Tap or click inside a closed area to fill it with color.</div>
        <!-- controls below image -->
        <div class="coloring-controls" id="coloringTopbar">
            <span class="coloring-progress" id="progressText">
                <?= count($imageUrls) > 0 ? 'Page 1 of ' . count($imageUrls) : 'No images' ?>
            </span>
            <button id="resetBtn"  class="coloring-reset-btn" type="button">&#x21BA; Reset Page</button>
            <button id="nextBtn"   class="coloring-next-btn"  type="button">Next &#x2192;</button>
        </div>
    </div>

    <!-- completed screen -->
    <div class="coloring-completed" id="coloringCompleted">
        <div class="coloring-completed-emoji">&#x1F31F;</div>
        <h2 class="coloring-completed-title">Completed!</h2>
        <p class="coloring-completed-sub">Amazing job! You colored all the pages.</p>
        <button type="button" class="coloring-btn-restart" id="restartBtn">Start Again</button>
    </div>

</div><!-- /.coloring-app -->

<script>
(function () {
    'use strict';

    /* ── data from PHP ───────────────────────────────── */
    var uploadedImages = <?= json_encode($imageUrls, JSON_UNESCAPED_UNICODE) ?>;

    /* ── palette colors ──────────────────────────────── */
    var colors = [
        '#ff4fa3', '#ff7f50', '#ffd54f', '#7ed957',
        '#4fc3f7', '#7e57c2', '#ff8a80', '#8d6e63',
        '#22c55e', '#3b82f6', '#000000', '#ffffff'
    ];
    var selectedColor = colors[0];

    /* ── state ───────────────────────────────────────── */
    var currentIndex      = 0;
    var originalImageData = null;

    /* ── DOM refs ────────────────────────────────────── */
    var canvas      = document.getElementById('coloringCanvas');
    var ctx         = canvas.getContext('2d', { willReadFrequently: true });
    var progressText= document.getElementById('progressText');
    var topbar      = document.getElementById('coloringTopbar');
    var paletteWrap = document.getElementById('coloringPaletteWrap');
    var stage       = document.getElementById('coloringStage');
    var completedEl = document.getElementById('coloringCompleted');
    var nextBtn     = document.getElementById('nextBtn');
    var resetBtn    = document.getElementById('resetBtn');
    var restartBtn  = document.getElementById('restartBtn');
    var paletteEl   = document.getElementById('coloringPalette');

    /* ── build palette ───────────────────────────────── */
    function buildPalette() {
        paletteEl.innerHTML = '';
        colors.forEach(function (color) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'coloring-color-btn' + (color === selectedColor ? ' active' : '');
            btn.style.background = color;
            btn.addEventListener('click', function () {
                selectedColor = color;
                paletteEl.querySelectorAll('.coloring-color-btn').forEach(function (b) { b.classList.remove('active'); });
                btn.classList.add('active');
            });
            paletteEl.appendChild(btn);
        });
    }

    /* ── progress text ───────────────────────────────── */
    function updateProgress() {
        if (!uploadedImages.length) {
            progressText.textContent = 'No images';
            return;
        }
        progressText.textContent = 'Page ' + (currentIndex + 1) + ' of ' + uploadedImages.length;
        nextBtn.textContent = (currentIndex < uploadedImages.length - 1) ? 'Next \u2192' : 'Finish \u2713';
    }

    /* ── show / hide sections ────────────────────────── */
    function showCompleted() {
        topbar.style.display      = 'none';
        paletteWrap.style.display = 'none';
        stage.style.display       = 'none';
        completedEl.classList.add('active');
    }
    function showStage() {
        topbar.style.display      = '';
        paletteWrap.style.display = '';
        stage.style.display       = '';
        completedEl.classList.remove('active');
    }

    /* ── image to coloring page ──────────────────────── */
    function convertToColoringPage() {
        var imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
        var data = imageData.data;
        for (var i = 0; i < data.length; i += 4) {
            var gray = 0.299 * data[i] + 0.587 * data[i + 1] + 0.114 * data[i + 2];
            var val  = gray > 180 ? 255 : 0;
            data[i] = data[i + 1] = data[i + 2] = val;
            data[i + 3] = 255;
        }
        ctx.putImageData(imageData, 0, 0);
        thickenBlackLines();
    }

    function thickenBlackLines() {
        var imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
        var src = new Uint8ClampedArray(imageData.data);
        var dst = imageData.data;
        var w = canvas.width, h = canvas.height;
        function isBlack(idx) { return src[idx] < 30 && src[idx + 1] < 30 && src[idx + 2] < 30; }
        for (var y = 1; y < h - 1; y++) {
            for (var x = 1; x < w - 1; x++) {
                var idx = (y * w + x) * 4;
                var nearBlack = false;
                outer: for (var dy = -1; dy <= 1; dy++) {
                    for (var dx = -1; dx <= 1; dx++) {
                        if (isBlack(((y + dy) * w + (x + dx)) * 4)) { nearBlack = true; break outer; }
                    }
                }
                if (nearBlack) {
                    dst[idx] = dst[idx + 1] = dst[idx + 2] = 0; dst[idx + 3] = 255;
                } else {
                    dst[idx] = dst[idx + 1] = dst[idx + 2] = 255; dst[idx + 3] = 255;
                }
            }
        }
        ctx.putImageData(imageData, 0, 0);
    }

    /* ── load image ──────────────────────────────────── */
    function loadImageAt(index) {
        if (index >= uploadedImages.length) { showCompleted(); return; }
        showStage();
        var img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = function () {
            var maxWidth = Math.min(800, window.innerWidth - 60);
            var scale    = img.width > maxWidth ? maxWidth / img.width : 1;
            canvas.width  = Math.round(img.width  * scale);
            canvas.height = Math.round(img.height * scale);
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
            convertToColoringPage();
            originalImageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
            updateProgress();
        };
        img.onerror = function () { loadImageAt(index + 1); };
        img.src = uploadedImages[index];
    }

    /* ── reset page ──────────────────────────────────── */
    function resetCurrentPage() {
        if (originalImageData) ctx.putImageData(originalImageData, 0, 0);
    }

    /* ── flood fill ──────────────────────────────────── */
    function hexToRgba(hex) {
        var clean  = hex.replace('#', '');
        var n      = parseInt(clean, 16);
        return { r: (n >> 16) & 255, g: (n >> 8) & 255, b: n & 255, a: 255 };
    }
    function isBlackPixel(data, idx) {
        return data[idx] < 40 && data[idx + 1] < 40 && data[idx + 2] < 40;
    }
    function colorsMatch(data, idx, tgt, tol) {
        return Math.abs(data[idx]     - tgt.r) <= tol
            && Math.abs(data[idx + 1] - tgt.g) <= tol
            && Math.abs(data[idx + 2] - tgt.b) <= tol
            && Math.abs(data[idx + 3] - tgt.a) <= tol;
    }
    function floodFill(startX, startY, fill) {
        var imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
        var data = imageData.data, w = canvas.width, h = canvas.height;
        if (startX < 0 || startY < 0 || startX >= w || startY >= h) return;
        var si = (startY * w + startX) * 4;
        if (isBlackPixel(data, si)) return;
        var tgt = { r: data[si], g: data[si + 1], b: data[si + 2], a: data[si + 3] };
        if (tgt.r === fill.r && tgt.g === fill.g && tgt.b === fill.b && tgt.a === fill.a) return;
        var stack   = [[startX, startY]];
        var visited = new Uint8Array(w * h);
        while (stack.length) {
            var pt = stack.pop();
            var x = pt[0], y = pt[1];
            if (x < 0 || y < 0 || x >= w || y >= h) continue;
            var pos = y * w + x;
            if (visited[pos]) continue;
            visited[pos] = 1;
            var i = pos * 4;
            if (isBlackPixel(data, i)) continue;
            if (!colorsMatch(data, i, tgt, 18)) continue;
            data[i] = fill.r; data[i+1] = fill.g; data[i+2] = fill.b; data[i+3] = fill.a;
            stack.push([x+1,y],[x-1,y],[x,y+1],[x,y-1]);
        }
        ctx.putImageData(imageData, 0, 0);
    }

    /* ── canvas pointer ──────────────────────────────── */
    function getCanvasPoint(event) {
        var rect    = canvas.getBoundingClientRect();
        var clientX = event.clientX !== undefined ? event.clientX
                    : (event.touches && event.touches[0] ? event.touches[0].clientX : 0);
        var clientY = event.clientY !== undefined ? event.clientY
                    : (event.touches && event.touches[0] ? event.touches[0].clientY : 0);
        return {
            x: Math.floor((clientX - rect.left) * canvas.width  / rect.width),
            y: Math.floor((clientY - rect.top)  * canvas.height / rect.height)
        };
    }
    function handleFill(event) {
        if (!uploadedImages.length) return;
        event.preventDefault();
        var pt = getCanvasPoint(event);
        floodFill(pt.x, pt.y, hexToRgba(selectedColor));
    }

    /* ── button events ───────────────────────────────── */
    nextBtn.addEventListener('click', function () {
        if (!uploadedImages.length) return;
        currentIndex++;
        if (currentIndex >= uploadedImages.length) { showCompleted(); return; }
        loadImageAt(currentIndex);
    });
    resetBtn.addEventListener('click', resetCurrentPage);
    restartBtn.addEventListener('click', function () {
        currentIndex = 0;
        showStage();
        loadImageAt(0);
    });

    canvas.addEventListener('click',      handleFill);
    canvas.addEventListener('touchstart', handleFill, { passive: false });

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