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
* { box-sizing: border-box; }
.viewer-header { display: none !important; }

.shell {
    width: 100%;
    min-height: calc(100vh - 90px);
    padding: clamp(14px, 2.5vw, 34px);
    display: flex;
    flex-direction: column;
    align-items: center;
    background: #ffffff;
}

body, html {
    margin: 0 !important;
    padding: 0 !important;
    width: 100%;
    height: 100%;
}

.activity-wrapper {
    margin: 0 !important;
    padding: 0 !important;
    width: 100%;
    min-height: 100vh;
}

.top-row, .activity-header, .activity-title, .activity-subtitle {
    display: none !important;
}

.viewer-content {
    margin: 0 !important;
    padding: 0 !important;
    width: 100%;
    height: auto;
}

.shell {
    width: 100%;
    height: 100vh;
    padding: 0 !important;
    display: flex;
    flex-direction: column;
    background: #ffffff;
    overflow: hidden;
}

.hero {
    padding: clamp(12px, 1.5vw, 18px) clamp(12px, 2vw, 24px);
    width: 100%;
    flex-shrink: 0;
    background: #ffffff;
    border-bottom: 1px solid #F0EEF8;
    text-align: center;
}

.kicker {
    display: inline-block;
    background: #FFF0E6;
    border: 1px solid #FCDDBF;
    color: #C2580A;
    border-radius: 999px;
    padding: 4px 12px;
    font-size: 10px;
    font-family: 'Nunito', sans-serif;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
    margin-bottom: 4px;
}

.act-title {
    font-family: 'Fredoka', sans-serif;
    font-weight: 700;
    font-size: clamp(20px, 3vw, 28px);
    color: #F97316;
    margin: 0;
    line-height: 1.1;
}

.act-sub {
    font-family: 'Nunito', sans-serif;
    font-weight: 700;
    font-size: clamp(11px, 1.4vw, 13px);
    color: #9B94BE;
    margin: 3px 0 0;
    line-height: 1.2;
}

.board {
    background: #ffffff;
    flex: 1;
    body, html {
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
        stage.style.display = 'none';
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
                    } catch (e) {}
                    window.location.href = saveUrl;
                });
        }
    }
    function showStage() {
        stage.style.display = '';
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
        playClickSound();
        var pt = getCanvasPoint(event);
        floodFill(pt.x, pt.y, hexToRgba(selectedColor));
    }

    /* ── button events ───────────────────────────────── */
    finishBtn.addEventListener('click', function () {
        if (!uploadedImages.length) return;
        playClickSound();
        if (currentIndex >= uploadedImages.length - 1) {
            showCompleted();
        } else {
            currentIndex++;
            loadImageAt(currentIndex);
        }
    });
    resetBtn.addEventListener('click', function () {
        playClickSound();
        resetCurrentPage();
    });
    restartBtn.addEventListener('click', function () {
        playClickSound();
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