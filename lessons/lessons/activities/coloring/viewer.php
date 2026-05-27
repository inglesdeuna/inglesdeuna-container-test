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
    height: 100%;
    overflow: hidden;
}

body {
    margin: 0 !important;
    padding: 0 !important;
    background: #fff !important;
    font-family: 'Nunito', 'Segoe UI', sans-serif !important;
}

.activity-wrapper {
    height: 100vh !important;
    max-width: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
    display: flex !important;
    flex-direction: column !important;
    background: transparent !important;
    overflow: hidden !important;
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
    overflow: hidden !important;
}

.col-page {
    width: 100%;
    flex: 1;
    min-height: 0;
    overflow: hidden;
    padding: clamp(8px, 1.2vw, 16px) clamp(10px, 1.5vw, 20px);
    display: flex;
    flex-direction: column;
    align-items: stretch;
    justify-content: flex-start;
    background: #fff;
    box-sizing: border-box;
}

.col-app {
    width: min(1120px, 100%);
    margin: 0 auto;
    flex: 1;
    min-height: 0;
    display: flex;
    flex-direction: column;
}

.col-hero {
    flex-shrink: 0;
    text-align: center;
    padding-bottom: clamp(5px, 0.8vh, 10px);
}

.col-kicker { display: none; }

.col-hero h1 {
    font-family: 'Fredoka', sans-serif;
    font-size: clamp(18px, 2.8vw, 34px);
    font-weight: 700;
    color: var(--col-orange);
    margin: 0;
    line-height: 1.1;
}

.col-hero p {
    font-size: clamp(11px, 1.1vw, 13px);
    font-weight: 700;
    color: var(--col-muted);
    margin: 3px 0 0;
}

.col-stage-shell {
    flex: 1;
    min-height: 0;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    background: #fff;
    border: 1px solid var(--col-border);
    border-radius: 20px;
    padding: clamp(10px, 1.4vw, 18px);
    box-shadow: 0 8px 40px rgba(127, 119, 221, .13);
    box-sizing: border-box;
}

.board {
    flex: 1;
    min-height: 0;
    overflow: hidden;
    width: min(100%, 980px);
    margin: 0 auto;
    border: 1px solid #EDE9FA;
    border-radius: 20px;
    background: #fff;
    box-shadow: 0 12px 36px rgba(127, 119, 221, .13);
    padding: clamp(10px, 1.4vw, 16px);
    display: flex;
    flex-direction: column;
    gap: 8px;
    box-sizing: border-box;
}

.prog-row {
    flex-shrink: 0;
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
    flex-shrink: 0;
    background: #F5F3FF;
    border: 1px solid #EDE9FA;
    border-radius: 14px;
    padding: 8px 12px;
}

.picker-label {
    font-size: 11px;
    font-weight: 900;
    font-family: 'Nunito', sans-serif;
    color: var(--col-muted);
    letter-spacing: .08em;
    text-transform: uppercase;
    text-align: center;
    margin-bottom: 8px;
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
    flex-shrink: 0;
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
    flex: 1;
    min-height: 0;
    overflow: hidden;
    border: 1px solid #EDE9FA;
    border-radius: 20px;
    background: #fff;
    display: flex;
    justify-content: center;
    align-items: center;
    touch-action: manipulation;
    padding: 8px;
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
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    padding-top: 4px;
}

.page-info {
    font-size: 13px;
    font-weight: 900;
    font-family: 'Nunito', sans-serif;
    color: var(--col-muted);
}

.btns { display: flex; gap: 10px; flex-wrap: wrap; justify-content: center; }

.btn-purple,
.btn-orange {
    border: none;
    border-radius: 10px;
    padding: 11px clamp(20px, 3vw, 32px);
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
    flex: 1;
    min-height: 0;
    align-items: center;
    justify-content: center;
    padding: 16px;
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

/* ── Unified unscored completed screen ── */
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

@media (max-width: 900px) {
    .colors-grid { grid-template-columns: repeat(4, minmax(32px, 1fr)); }
    .swatch { width: 34px; height: 34px; }
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
                    <div class="btns">
                        <button class="btn-orange" id="btn-reset" type="button">Clear</button>
                        <button class="btn-purple" id="btn-finish" type="button">Next</button>
                    </div>
                    <span class="page-info" id="progressText">Page 1 of 1</span>
                </div>

                <div class="coloring-completed" id="coloringCompleted"></div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    var uploadedImages       = <?= json_encode($imageUrls, JSON_UNESCAPED_UNICODE) ?>;
    var nextActivityUrl      = <?= json_encode($nextUrl, JSON_UNESCAPED_UNICODE) ?>;
    var COLORING_RETURN_TO   = <?= json_encode($returnTo, JSON_UNESCAPED_UNICODE) ?>;
    var COLORING_ACTIVITY_ID = <?= json_encode($activityId, JSON_UNESCAPED_UNICODE) ?>;
    var coloringRounds       = 0;

    var colors = [
        '#ef4444','#f97316','#cc7722','#f5e6c8','#facc15','#22c55e','#14b8a6','#3b82f6',
        '#8b5cf6','#c4b5fd','#ec4899','#8b5a2b','#84cc16','#9ca3af','#111827','#ffffff'
    ];
    var selectedColor = colors[0];
    var colorNames = {
        '#ef4444':'Red','#f97316':'Orange','#cc7722':'Brown','#f5e6c8':'Cream',
        '#facc15':'Yellow','#22c55e':'Green','#14b8a6':'Teal','#3b82f6':'Blue',
        '#8b5cf6':'Violet','#c4b5fd':'Lavender','#ec4899':'Pink','#8b5a2b':'Dark Brown',
        '#84cc16':'Lime','#9ca3af':'Gray','#111827':'Black','#ffffff':'White'
    };

    var currentIndex     = 0;
    var paintedSnapshots = [];
    var origData         = null; // pixel data of original image — used only for boundary detection
    var origImg          = null; // Image element kept alive for re-compositing

    var canvas      = document.getElementById('coloringCanvas');
    var ctx         = canvas.getContext('2d');
    var progressText = document.getElementById('progressText');
    var progFill    = document.getElementById('progFill');
    var progBadge   = document.getElementById('progBadge');
    var selDot      = document.getElementById('sel-dot');
    var selLabel    = document.getElementById('sel-label');
    var stage       = document.getElementById('coloringStage');
    var completedEl = document.getElementById('coloringCompleted');
    var finishBtn   = document.getElementById('btn-finish');
    var resetBtn    = document.getElementById('btn-reset');
    var paletteEl   = document.getElementById('coloringPalette');

    /*
     * Two-layer compositing approach:
     *   colorCanvas  — off-screen canvas holding only flat fill colors (starts white)
     *   visible canvas = colorCanvas drawn first, then original outline image on top
     *                    with 'multiply' blend mode.
     *
     * Why multiply works for coloring books:
     *   white(255) * color = color  →  white areas show the fill color through
     *   black(0)   * anything = 0   →  black outlines always stay pure black
     *   Anti-aliased gray pixels multiply cleanly, preserving smooth edges.
     *
     * Flood-fill never touches the visible canvas directly; it only modifies
     * colorCanvas, then render() recomposites both layers.
     */
    var colorCanvas = document.createElement('canvas');
    var colorCtx    = colorCanvas.getContext('2d', { willReadFrequently: true });

    var clickAudioCtx = null;

    function playClickSound() {
        try {
            var AC = window.AudioContext || window.webkitAudioContext;
            if (!AC) return;
            if (!clickAudioCtx) clickAudioCtx = new AC();
            if (clickAudioCtx.state === 'suspended') clickAudioCtx.resume();
            var now = clickAudioCtx.currentTime;
            var osc  = clickAudioCtx.createOscillator();
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
        } catch (e) {}
    }

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

    function updateProgress() {
        if (!uploadedImages.length) {
            progressText.textContent = 'No images';
            progBadge.textContent = '—';
            progFill.style.width = '0%';
            return;
        }
        progressText.textContent = 'Page ' + (currentIndex + 1) + ' of ' + uploadedImages.length;
        progBadge.textContent = (currentIndex + 1) + ' / ' + uploadedImages.length;
        progFill.style.width = (((currentIndex + 1) / uploadedImages.length) * 100) + '%';
        finishBtn.textContent = currentIndex < uploadedImages.length - 1 ? 'Next →' : 'Finish ✓';
    }

    function render() {
        if (!origImg) return;
        ctx.globalCompositeOperation = 'source-over';
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(colorCanvas, 0, 0);                          // 1. flat color fills
        ctx.globalCompositeOperation = 'multiply';
        ctx.drawImage(origImg, 0, 0, canvas.width, canvas.height); // 2. sharp outlines on top
        ctx.globalCompositeOperation = 'source-over';
    }

    function saveCurrentSnapshot() {
        if (!uploadedImages.length || currentIndex >= uploadedImages.length) return;
        try { paintedSnapshots[currentIndex] = colorCanvas.toDataURL('image/png'); } catch (e) {}
    }

    function hex2rgb(hex) {
        var m = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
        return m ? [parseInt(m[1],16), parseInt(m[2],16), parseInt(m[3],16)] : [0,0,0];
    }

    function floodFill(x, y, newColor) {
        x = Math.round(x);
        y = Math.round(y);
        var w = canvas.width, h = canvas.height;
        if (x < 0 || y < 0 || x >= w || y >= h || !origData) return;

        var nr = hex2rgb(newColor);
        var nR = nr[0], nG = nr[1], nB = nr[2];

        // Don't start a fill on a dark outline pixel.
        // If the exact click lands on an outline, search a small radius for the
        // nearest light pixel — handles clicks that fall on the edge of tiny regions.
        var oi  = (y * w + x) * 4;
        var lum = 0.299 * origData[oi] + 0.587 * origData[oi+1] + 0.114 * origData[oi+2];
        if (lum < 100) {
            var found = false;
            outer: for (var r = 1; r <= 4; r++) {
                for (var dy = -r; dy <= r; dy++) {
                    for (var dx = -r; dx <= r; dx++) {
                        if (Math.abs(dx) !== r && Math.abs(dy) !== r) continue; // perimeter only
                        var nx = x + dx, ny = y + dy;
                        if (nx < 0 || ny < 0 || nx >= w || ny >= h) continue;
                        var ni = (ny * w + nx) * 4;
                        var nl = 0.299 * origData[ni] + 0.587 * origData[ni+1] + 0.114 * origData[ni+2];
                        if (nl >= 100) { x = nx; y = ny; found = true; break outer; }
                    }
                }
            }
            if (!found) return;
        }

        // Get current color-layer state
        var imgd = colorCtx.getImageData(0, 0, w, h);
        var d    = imgd.data;
        var ci   = (y * w + x) * 4;
        var tR = d[ci], tG = d[ci+1], tB = d[ci+2];

        // Nothing to do if already this color
        if (Math.abs(tR-nR) < 6 && Math.abs(tG-nG) < 6 && Math.abs(tB-nB) < 6) return;

        var TOL     = 50;
        var visited = new Uint8Array(w * h);
        var stack   = [y * w + x];

        while (stack.length) {
            var pos = stack.pop();
            if (visited[pos]) continue;
            visited[pos] = 1;

            var px = pos % w;
            var py = (pos - px) / w;

            // Outline boundary: skip dark pixels in original image
            var oa  = pos * 4;
            var ol  = 0.299 * origData[oa] + 0.587 * origData[oa+1] + 0.114 * origData[oa+2];
            if (ol < 100) continue;

            // Color boundary: skip pixels too different from seed color in fill layer
            var ca = pos * 4;
            if (Math.abs(d[ca]-tR) > TOL || Math.abs(d[ca+1]-tG) > TOL || Math.abs(d[ca+2]-tB) > TOL) continue;

            d[ca]   = nR;
            d[ca+1] = nG;
            d[ca+2] = nB;

            if (px > 0)     stack.push(pos - 1);
            if (px < w - 1) stack.push(pos + 1);
            if (py > 0)     stack.push(pos - w);
            if (py < h - 1) stack.push(pos + w);
        }

        colorCtx.putImageData(imgd, 0, 0);
        render();
    }

    function handleFill(e) {
        if (!origData) return;
        var rect  = canvas.getBoundingClientRect();
        var point = e.touches ? e.touches[0] : e;
        var x = (point.clientX - rect.left) * canvas.width  / rect.width;
        var y = (point.clientY - rect.top)  * canvas.height / rect.height;
        floodFill(x, y, selectedColor);
        saveCurrentSnapshot();
        if (e.preventDefault) e.preventDefault();
    }

    function loadImageAt(idx) {
        if (!uploadedImages.length || idx >= uploadedImages.length) return;
        var img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = function () {
            var ratio = img.width / img.height;
            var maxW  = canvas.parentElement.clientWidth  - 16;
            var maxH  = canvas.parentElement.clientHeight - 16;
            var cw = maxW, ch = maxW / ratio;
            if (ch > maxH) { ch = maxH; cw = ch * ratio; }
            cw = Math.round(cw); ch = Math.round(ch);

            canvas.width      = cw; canvas.height      = ch;
            colorCanvas.width = cw; colorCanvas.height = ch;
            origImg = img;

            // Draw original once to capture pixel data for boundary detection
            ctx.globalCompositeOperation = 'source-over';
            ctx.drawImage(img, 0, 0, cw, ch);
            try { origData = ctx.getImageData(0, 0, cw, ch).data; } catch(e) { origData = null; }

            // Initialize fill layer as solid white
            colorCtx.fillStyle = '#ffffff';
            colorCtx.fillRect(0, 0, cw, ch);

            var snap = paintedSnapshots[idx];
            if (snap) {
                var si = new Image();
                si.onload = function () {
                    colorCtx.clearRect(0, 0, cw, ch);
                    colorCtx.drawImage(si, 0, 0, cw, ch);
                    render();
                };
                si.src = snap;
            } else {
                render();
            }
        };
        img.src = uploadedImages[idx];
    }

    canvas.addEventListener('click',      handleFill);
    canvas.addEventListener('touchstart', handleFill, { passive: false });

    finishBtn.addEventListener('click', function () {
        if (currentIndex < uploadedImages.length - 1) {
            saveCurrentSnapshot();
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
            paintedSnapshots[currentIndex] = null;
            colorCtx.fillStyle = '#ffffff';
            colorCtx.fillRect(0, 0, colorCanvas.width, colorCanvas.height);
            render();
        }
    });

    // Redraw at new container size reusing the already-loaded origImg (no re-fetch).
    var resizeTimer = null;
    function redrawAtCurrentSize() {
        if (!origImg || !uploadedImages.length || currentIndex >= uploadedImages.length) return;
        var ratio = origImg.width / origImg.height;
        var maxW  = canvas.parentElement.clientWidth  - 16;
        var maxH  = canvas.parentElement.clientHeight - 16;
        if (maxW < 100 || maxH < 100) return;
        var cw = maxW, ch = maxW / ratio;
        if (ch > maxH) { ch = maxH; cw = ch * ratio; }
        cw = Math.round(cw); ch = Math.round(ch);
        if (cw === canvas.width && ch === canvas.height) return; // nothing changed

        saveCurrentSnapshot();
        canvas.width      = cw; canvas.height      = ch;
        colorCanvas.width = cw; colorCanvas.height = ch;

        ctx.globalCompositeOperation = 'source-over';
        ctx.drawImage(origImg, 0, 0, cw, ch);
        try { origData = ctx.getImageData(0, 0, cw, ch).data; } catch(e) { origData = null; }

        var snap = paintedSnapshots[currentIndex];
        if (snap) {
            var si = new Image();
            si.onload = function () {
                colorCtx.clearRect(0, 0, cw, ch);
                colorCtx.drawImage(si, 0, 0, cw, ch);
                render();
            };
            si.src = snap;
        } else {
            colorCtx.fillStyle = '#ffffff';
            colorCtx.fillRect(0, 0, cw, ch);
            render();
        }
    }

    function scheduleResize() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(redrawAtCurrentSize, 150);
    }

    window.addEventListener('resize', scheduleResize);
    document.addEventListener('fullscreenchange', scheduleResize);
    document.addEventListener('webkitfullscreenchange', scheduleResize);

    function showCompleted() {
        stage.classList.add('is-completed');
        completedEl.classList.add('active');
        showPassiveDone(completedEl, {
            text: 'You finished coloring! Great job!',
            restartLabel: 'Play Again',
            onRestart: function () {
                stage.classList.remove('is-completed');
                completedEl.classList.remove('active');
                completedEl.innerHTML = '';
                currentIndex = 0;
                paintedSnapshots = [];
                loadImageAt(0);
                updateProgress();
            },
            returnTo: COLORING_RETURN_TO,
            activityId: COLORING_ACTIVITY_ID,
            activityType: 'coloring',
            total: uploadedImages.length
        });
    }

    function showPassiveDone(containerEl, opts) {
        containerEl.innerHTML =
            '<div class="passive-done" id="passive-done-card">' +
            '<div class="passive-done-icon">🎉</div>' +
            '<h2 class="passive-done-title">All Done!</h2>' +
            '<p class="passive-done-text">' + (opts.text || 'Great work!') + '</p>' +
            '<div class="passive-done-track"><div class="passive-done-fill" id="passive-fill"></div></div>' +
            '<div><button class="passive-done-btn" id="passive-restart-btn">&#8635; ' + (opts.restartLabel || 'Play Again') + '</button></div>' +
            '</div>';
        var card = document.getElementById('passive-done-card');
        var fill = document.getElementById('passive-fill');
        var btn  = document.getElementById('passive-restart-btn');
        requestAnimationFrame(function () {
            card.classList.add('active');
            setTimeout(function () { if (fill) fill.style.width = '100%'; }, 80);
        });
        if (btn && opts.onRestart) btn.addEventListener('click', opts.onRestart);
        if (opts.returnTo && opts.activityId) {
            var sep = opts.returnTo.indexOf('?') !== -1 ? '&' : '?';
            fetch(opts.returnTo + sep + 'activity_percent=100&activity_errors=0&activity_total=' + (opts.total||1) +
                '&activity_id=' + encodeURIComponent(opts.activityId) +
                '&activity_type=' + encodeURIComponent(opts.activityType || 'activity'),
                { method: 'GET', credentials: 'same-origin', cache: 'no-store' }).catch(function(){});
        }
    }

    buildPalette();
    if (uploadedImages.length > 0) {
        loadImageAt(0);
        updateProgress();
    } else {
        progressText.textContent = 'No images uploaded for this activity.';
    }
}());
</script>
<?php
$content = ob_get_clean();
render_activity_viewer($activityTitle, '&#x1F3A8;', $content);
?>
