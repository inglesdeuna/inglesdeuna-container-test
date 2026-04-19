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
$nextUrl       = isset($_GET['next']) ? trim((string) $_GET['next']) : '';

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
.coloring-app {
    max-width: 1060px;
    margin: 0 auto;
    padding: clamp(8px, 1.4vw, 16px);
    font-family: 'Nunito','Segoe UI',sans-serif;
    color: #334155;
}
.coloring-intro {
    background: linear-gradient(135deg, #fff8df 0%, #eef8ff 52%, #f8fbff 100%);
    border: 1px solid #dbe7f5;
    border-radius: 26px;
    padding: 24px 26px;
    box-shadow: 0 16px 34px rgba(15, 23, 42, .09);
    margin-bottom: 14px;
}
.coloring-intro h2 {
    margin: 0 0 8px;
    font-family: 'Fredoka', 'Trebuchet MS', sans-serif;
    font-size: clamp(24px, 3.2vw, 32px);
    font-weight: 700;
    color: #0f172a;
    letter-spacing: .3px;
}
.coloring-intro p {
    margin: 0;
    font-size: 16px;
    color: #334155;
    line-height: 1.6;
}
/* ── controls ───────────────────────────────────────── */
.coloring-controls {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    justify-content: center;
    align-items: center;
    padding: 14px 0 4px;
    margin-top: 12px;
}
.coloring-action-btn {
    display: inline-flex;
    justify-content: center;
    align-items: center;
    min-width: 146px;
    border: none;
    border-radius: 999px;
    padding: 12px 24px;
    font-size: 15px;
    font-weight: 800;
    font-family: inherit;
    cursor: pointer;
    color: #fff;
    text-decoration: none;
    box-shadow: 0 10px 24px rgba(0,0,0,.14);
    transition: transform .18s ease, filter .18s ease;
}
.coloring-action-btn:hover {
    transform: scale(1.05);
    filter: brightness(1.07);
}
.coloring-action-btn-secondary { background: linear-gradient(180deg, #60a5fa 0%, #2563eb 100%); }
.coloring-action-btn-primary { background: linear-gradient(180deg, #db2777 0%, #be185d 100%); }
.coloring-progress  { font-size: 15px; font-weight: 800; color: #0f172a; }
/* ── palette ─────────────────────────────────────────── */
.coloring-palette-wrap {
    background: #ffffff;
    border: 1px solid #dbe7f5;
    border-radius: 24px;
    padding: 16px;
    box-shadow: 0 14px 28px rgba(15, 23, 42, .07);
    margin-bottom: 14px;
}
.coloring-palette-heading { text-align: center; font-size: 16px; font-weight: 800; color: #0f172a; margin-bottom: 10px; }
.coloring-palette {
    display: grid;
    grid-template-columns: repeat(8, 50px);
    gap: 10px;
    justify-content: center;
}
.coloring-color-btn {
    width: 50px; height: 50px; border-radius: 50%; border: 4px solid transparent;
    cursor: pointer; transition: transform .15s; -webkit-tap-highlight-color: transparent;
}
.coloring-color-btn:hover { transform: scale(1.07); }
.coloring-color-btn.active { border-color: #0f172a; transform: scale(1.12); }
/* ── stage ───────────────────────────────────────────── */
.coloring-stage {
    background: linear-gradient(180deg, #fffdf7 0%, #ffffff 100%);
    border: 1px solid #dbe7f5;
    border-radius: 24px;
    box-shadow: 0 14px 28px rgba(15, 23, 42, .07);
    padding: 16px;
}
.coloring-canvas-wrap { display: flex; justify-content: center; align-items: center; overflow: visible; border-radius: 16px; background: #fff; touch-action: manipulation; }
#coloringCanvas {
    max-width: 100%;
    max-height: calc(100vh - 360px);
    width: auto;
    height: auto;
    display: block;
    touch-action: manipulation;
    border-radius: 14px;
    cursor: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='44' height='44' viewBox='0 0 44 44'%3E%3Cg transform='rotate(20 22 22)'%3E%3Crect x='17' y='6' width='10' height='20' rx='4' fill='%23f59e0b'/%3E%3Crect x='16' y='11' width='12' height='7' rx='3.5' fill='%23fde68a' opacity='.9'/%3E%3Crect x='16' y='24' width='12' height='8' rx='2.6' fill='%23fff7ed' stroke='%23b45309' stroke-width='1.4'/%3E%3Cpath d='M16 28h12l-6 10z' fill='%238b5a2b'/%3E%3Cpath d='M21 38l1.2-2.2L23.5 38z' fill='%230ea5e9'/%3E%3C/g%3E%3C/svg%3E") 22 22, pointer;
}
.coloring-helper { text-align: center; margin-top: 10px; font-size: 14px; color: #475569; font-weight: 700; }
/* ── completed ───────────────────────────────────────── */
.coloring-completed { display: none; text-align: center; padding: 50px 20px 30px; flex-direction: column; align-items: center; }
.coloring-completed.active { display: flex; }
.coloring-completed-emoji { font-size: 88px; line-height: 1; margin-bottom: 14px; }
.coloring-completed-title { font-family: 'Fredoka','Trebuchet MS',sans-serif; font-size: clamp(32px,4vw,48px); font-weight: 700; color: #be185d; margin: 0 0 8px; }
.coloring-completed-sub { font-size: 17px; font-weight: 700; color: #374151; margin: 0 0 26px; }
.coloring-completed-actions { display: flex; flex-wrap: wrap; justify-content: center; gap: 12px; }
.coloring-no-images { text-align: center; padding: 40px 20px; font-size: 16px; font-weight: 700; color: #0f172a; }
@media (max-width: 640px) {
    .coloring-action-btn { width: 100%; }
    .coloring-color-btn { width: 44px; height: 44px; }
    .coloring-palette {
        grid-template-columns: repeat(8, 44px);
    }
}
</style>

<div class="coloring-app">

    <section class="coloring-intro">
        <h2><?= htmlspecialchars($activityTitle, ENT_QUOTES, 'UTF-8') ?></h2>
        <p>Pick a crayon color and click inside each shape to paint. Keep going page by page and finish your colorful masterpiece.</p>
    </section>

    <!-- color palette -->
    <div class="coloring-palette-wrap" id="coloringPaletteWrap">
        <div class="coloring-palette-heading">Choose a crayon color</div>
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
            <button id="resetBtn"  class="coloring-action-btn coloring-action-btn-secondary" type="button">&#x21BA; Reset Page</button>
            <button id="nextBtn"   class="coloring-action-btn coloring-action-btn-primary" type="button">Next &#x2192;</button>
        </div>
    </div>

    <!-- completed screen -->
    <div class="coloring-completed" id="coloringCompleted">
        <div class="coloring-completed-emoji">&#x1F31F;</div>
        <h2 class="coloring-completed-title">Completed!</h2>
        <p class="coloring-completed-sub">Amazing job! You colored all the pages.</p>
        <div class="coloring-completed-actions">
            <button type="button" class="coloring-action-btn coloring-action-btn-secondary" id="restartBtn">Start Again</button>
            <?php if ($nextUrl !== ''): ?>
                <a href="<?= htmlspecialchars($nextUrl, ENT_QUOTES, 'UTF-8') ?>" class="coloring-action-btn coloring-action-btn-primary">Next activity &#x2192;</a>
            <?php endif; ?>
        </div>
    </div>

</div><!-- /.coloring-app -->

<script>
(function () {
    'use strict';

    /* ── data from PHP ───────────────────────────────── */
    var uploadedImages = <?= json_encode($imageUrls, JSON_UNESCAPED_UNICODE) ?>;
    var nextActivityUrl = <?= json_encode($nextUrl, JSON_UNESCAPED_UNICODE) ?>;

    /* ── palette colors ──────────────────────────────── */
    var colors = [
        '#ef4444', '#f97316', '#cc7722', '#f5e6c8', '#facc15', '#22c55e', '#14b8a6', '#3b82f6',
        '#8b5cf6', '#c4b5fd', '#ec4899', '#8b5a2b', '#84cc16', '#9ca3af', '#111827', '#ffffff'
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
        if (currentIndex < uploadedImages.length - 1) {
            nextBtn.textContent = 'Next \u2192';
        } else {
            nextBtn.textContent = 'Finish \u2713';
        }
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
            /* Keep only strong/dark strokes as black so enclosed regions stay fillable. */
            var val  = gray > 115 ? 255 : 0;
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
            var maxWidth  = Math.min(800, window.innerWidth - 60);
            var maxHeight = Math.max(300, window.innerHeight - 360);
            var scaleW    = img.width  > maxWidth  ? maxWidth  / img.width  : 1;
            var scaleH    = img.height > maxHeight ? maxHeight / img.height : 1;
            var scale     = Math.min(scaleW, scaleH);
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
        return data[idx] < 30 && data[idx + 1] < 30 && data[idx + 2] < 30;
    }
    function colorsMatch(data, idx, tgt, tol) {
        return Math.abs(data[idx]     - tgt.r) <= tol
            && Math.abs(data[idx + 1] - tgt.g) <= tol
            && Math.abs(data[idx + 2] - tgt.b) <= tol
            && Math.abs(data[idx + 3] - tgt.a) <= tol;
    }
    function findNearestFillablePoint(data, w, h, x, y, fill) {
        var maxRadius = 12;
        var fallback = null;
        for (var r = 0; r <= maxRadius; r++) {
            for (var dy = -r; dy <= r; dy++) {
                for (var dx = -r; dx <= r; dx++) {
                    if (r !== 0 && Math.abs(dx) !== r && Math.abs(dy) !== r) continue;
                    var nx = x + dx;
                    var ny = y + dy;
                    if (nx < 0 || ny < 0 || nx >= w || ny >= h) continue;
                    var idx = (ny * w + nx) * 4;
                    if (!isBlackPixel(data, idx)) {
                        var alreadyFill = data[idx] === fill.r && data[idx + 1] === fill.g && data[idx + 2] === fill.b && data[idx + 3] === fill.a;
                        if (!alreadyFill) {
                            return { x: nx, y: ny };
                        }
                        if (!fallback) {
                            fallback = { x: nx, y: ny };
                        }
                    }
                }
            }
        }
        return fallback;
    }
    function floodFill(startX, startY, fill) {
        var imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
        var data = imageData.data, w = canvas.width, h = canvas.height;
        if (startX < 0 || startY < 0 || startX >= w || startY >= h) return;
        var fillStart = findNearestFillablePoint(data, w, h, startX, startY, fill);
        if (!fillStart) return;
        var stack   = [[fillStart.x, fillStart.y]];
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
            if (!(data[i] === fill.r && data[i + 1] === fill.g && data[i + 2] === fill.b && data[i + 3] === fill.a)) {
                data[i] = fill.r;
                data[i + 1] = fill.g;
                data[i + 2] = fill.b;
                data[i + 3] = fill.a;
            }
            stack.push([x+1,y],[x-1,y],[x,y+1],[x,y-1]);
        }
        ctx.putImageData(imageData, 0, 0);
    }

    /* ── canvas pointer ──────────────────────────────── */
    function getCanvasPoint(event) {
        var pointerX = null;
        var pointerY = null;

        if (typeof event.offsetX === 'number' && typeof event.offsetY === 'number' && event.target === canvas) {
            pointerX = event.offsetX;
            pointerY = event.offsetY;
        }

        if (pointerX === null || pointerY === null) {
            var rect    = canvas.getBoundingClientRect();
            var clientX = event.clientX !== undefined ? event.clientX
                        : (event.touches && event.touches[0] ? event.touches[0].clientX : 0);
            var clientY = event.clientY !== undefined ? event.clientY
                        : (event.touches && event.touches[0] ? event.touches[0].clientY : 0);
            pointerX = clientX - rect.left;
            pointerY = clientY - rect.top;
        }

        var scaleX = canvas.clientWidth > 0 ? (canvas.width / canvas.clientWidth) : 1;
        var scaleY = canvas.clientHeight > 0 ? (canvas.height / canvas.clientHeight) : 1;
        var x = Math.round(pointerX * scaleX);
        var y = Math.round(pointerY * scaleY);

        return {
            x: Math.max(0, Math.min(canvas.width - 1, x)),
            y: Math.max(0, Math.min(canvas.height - 1, y))
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
        if (currentIndex >= uploadedImages.length) {
            showCompleted();
            return;
        }
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