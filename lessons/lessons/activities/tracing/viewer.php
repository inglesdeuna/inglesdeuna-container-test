<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/tracing_functions.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';

$activity = load_tracing_activity($pdo, $unit, $activityId);
$images = isset($activity['images']) && is_array($activity['images']) ? $activity['images'] : array();
$activityTitle = isset($activity['title']) ? (string) $activity['title'] : default_tracing_title();

ob_start();
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800&display=swap');

/* ── No-scroll override ── */
body { overflow: hidden !important; }

/* ── Shell ── */
.tracing-viewer-shell {
    max-width: 860px;
    margin: 0 auto;
    text-align: center;
    font-family: 'Nunito', 'Segoe UI', sans-serif;
    /* fill viewport minus body's top padding (~18px) + top-row (~52px) + body bottom padding (~24px) */
    height: calc(100vh - 95px);
    display: flex;
    flex-direction: column;
    gap: 6px;
    overflow: hidden;
}

.viewer-header { display: none !important; }

/* ── Intro banner ── */
.tracing-intro {
    margin-bottom: 18px;
    padding: 18px 22px;
    border-radius: 24px;
    border: 2px solid #fde68a;
    background: linear-gradient(135deg, #fffbeb 0%, #fef9c3 50%, #fefce8 100%);
    box-shadow: 0 8px 24px rgba(251,191,36,.18);
}
.tracing-intro h2 {
    margin: 0 0 6px;
    font-size: clamp(22px, 2.6vw, 30px);
    font-family: 'Fredoka', 'Trebuchet MS', sans-serif;
    font-weight: 700;
    color: #92400e;
}
.tracing-intro p {
    margin: 0;
    color: #78350f;
    font-weight: 700;
    font-size: 14px;
}

/* ── Main stage ── */
.tracing-stage {
    background: #fff;
    border: 2px solid #e0f2fe;
    border-radius: 28px;
    box-shadow: 0 12px 36px rgba(14,165,233,.10);
    padding: 10px 14px 12px;
    flex: 1;
    min-height: 0;
    display: flex;
    flex-direction: column;
    gap: 6px;
    overflow: hidden;
}

/* ── Counter badge ── */
.tracing-counter {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: linear-gradient(135deg, #e0f2fe, #bfdbfe);
    border: 1.5px solid #93c5fd;
    border-radius: 999px;
    padding: 5px 16px;
    font-size: 15px;
    font-weight: 800;
    color: #1e40af;
    flex-shrink: 0;
    letter-spacing: .02em;
}
.tracing-counter-dot {
    width: 8px; height: 8px;
    border-radius: 50%;
    background: #3b82f6;
    display: inline-block;
}

/* ── Canvas wrapper ── */
.tracing-viewer-canvas-wrap {
    flex: 1;
    min-height: 0;
    display: flex;
    justify-content: center;
    align-items: center;
}
.tracing-canvas-shell {
    width: min(100%, 700px);
    height: 100%;
    padding: 8px;
    background: linear-gradient(135deg, #f0f9ff, #f8fafc);
    border: 2px solid #bae6fd;
    border-radius: 22px;
    box-shadow: inset 0 2px 8px rgba(14,165,233,.08);
    display: flex;
    align-items: center;
    justify-content: center;
    box-sizing: border-box;
}
.tracing-viewer-canvas {
    display: block;
    max-width: 100%;
    max-height: 100%;
    width: auto;
    height: auto;
    border: 3px solid #7dd3fc;
    border-radius: 16px;
    background: #fff;
    touch-action: none;
    cursor: none;
    box-shadow: 0 4px 18px rgba(14,165,233,.10);
}

/* ── Floating circle cursor ── */
#tracingCursor {
    position: fixed;
    pointer-events: none;
    border-radius: 50%;
    border: 2px solid rgba(255,255,255,0.75);
    box-shadow: 0 0 0 1.5px rgba(0,0,0,0.30);
    transform: translate(-50%, -50%);
    z-index: 9999;
    display: none;
    will-change: transform, left, top;
}

/* ── Toolbar (color + thickness) ── */
.tracing-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: center;
    gap: 8px 16px;
    flex-shrink: 0;
    background: linear-gradient(135deg, #f0fdf4, #f0f9ff);
    border: 1.5px solid #bbf7d0;
    border-radius: 18px;
    padding: 8px 14px;
}
.tracing-toolbar-label {
    font-size: 12px;
    font-weight: 800;
    color: #374151;
    text-transform: uppercase;
    letter-spacing: .05em;
    margin-bottom: 6px;
}
.tracing-color-group,
.tracing-size-group {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
}
/* Color swatches */
.tracing-colors {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    justify-content: center;
}
.tracing-color-swatch {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    border: 3px solid transparent;
    cursor: pointer;
    transition: transform .15s, box-shadow .15s;
    box-shadow: 0 2px 6px rgba(0,0,0,.18);
    -webkit-tap-highlight-color: transparent;
}
.tracing-color-swatch:hover,
.tracing-color-swatch.active {
    transform: scale(1.22);
    border-color: #fff;
    box-shadow: 0 0 0 3px #64748b, 0 4px 10px rgba(0,0,0,.2);
}
.tracing-color-swatch.active {
    box-shadow: 0 0 0 3px #1d4ed8, 0 4px 10px rgba(0,0,0,.2);
}
/* Size buttons */
.tracing-sizes {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
    justify-content: center;
}
.tracing-size-btn {
    background: #fff;
    border: 2.5px solid #d1d5db;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: transform .15s, border-color .15s, box-shadow .15s;
    -webkit-tap-highlight-color: transparent;
    box-shadow: 0 2px 6px rgba(0,0,0,.10);
}
.tracing-size-btn:hover,
.tracing-size-btn.active {
    transform: scale(1.18);
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px #bfdbfe;
}
.tracing-size-btn.active {
    border-color: #1d4ed8;
    box-shadow: 0 0 0 3px #93c5fd;
}
.tracing-size-btn .dot {
    border-radius: 50%;
    background: #1e293b;
    pointer-events: none;
}

/* ── Next button ── */
.tracing-nav-row {
    display: flex;
    justify-content: center;
    flex-shrink: 0;
}
.tracing-btn-next {
    padding: 14px 42px;
    font-size: 18px;
    font-weight: 800;
    font-family: 'Fredoka', 'Nunito', sans-serif;
    border: none;
    border-radius: 999px;
    cursor: pointer;
    color: #fff;
    background: linear-gradient(135deg, #34d399 0%, #059669 100%);
    box-shadow: 0 8px 20px rgba(5,150,105,.28);
    transition: transform .15s, filter .15s, box-shadow .15s;
    letter-spacing: .03em;
    -webkit-tap-highlight-color: transparent;
    min-width: 160px;
}
.tracing-btn-next:hover {
    filter: brightness(1.07);
    transform: translateY(-2px);
    box-shadow: 0 12px 26px rgba(5,150,105,.34);
}
.tracing-btn-next:active {
    transform: translateY(0);
    filter: brightness(.95);
}

/* ── Completed screen ── */
.tracing-completed {
    display: none;
    flex-direction: column;
    align-items: center;
    padding: 40px 20px 30px;
    animation: tracingFadeIn .5s ease;
}
.tracing-completed.active {
    display: flex;
}
@keyframes tracingFadeIn {
    from { opacity: 0; transform: translateY(20px); }
    to   { opacity: 1; transform: translateY(0); }
}
.tracing-completed-icon {
    font-size: 88px;
    margin-bottom: 12px;
    line-height: 1;
}
.tracing-completed-title {
    font-family: 'Fredoka', 'Trebuchet MS', sans-serif;
    font-size: clamp(32px, 4vw, 48px);
    font-weight: 700;
    color: #d97706;
    margin: 0 0 10px;
    line-height: 1.1;
}
.tracing-completed-sub {
    font-size: 17px;
    font-weight: 700;
    color: #374151;
    margin: 0 0 28px;
    max-width: 360px;
}
.tracing-btn-restart {
    padding: 13px 36px;
    font-size: 17px;
    font-weight: 800;
    font-family: 'Fredoka', 'Nunito', sans-serif;
    border: none;
    border-radius: 999px;
    cursor: pointer;
    color: #fff;
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    box-shadow: 0 8px 20px rgba(217,119,6,.28);
    transition: transform .15s, filter .15s;
    -webkit-tap-highlight-color: transparent;
}
.tracing-btn-restart:hover {
    filter: brightness(1.08);
    transform: translateY(-2px);
}

/* ── Responsive ── */
@media (max-width: 600px) {
    .tracing-viewer-shell { height: calc(100vh - 80px); }
    .tracing-btn-next { font-size: 16px; padding: 11px 28px; min-width: 130px; }
    .tracing-color-swatch { width: 30px; height: 30px; }
    .tracing-toolbar { padding: 6px 10px; gap: 6px 10px; }
    .tracing-toolbar-label { font-size: 11px; }
    .tracing-size-btn .dot { transform: scale(0.85); }
}
</style>

<div class="tracing-viewer-shell">
    <div class="tracing-stage">

        <!-- counter -->
        <div class="tracing-counter" id="tracingCounter">
            <span class="tracing-counter-dot"></span>
            <span id="counterText">— / —</span>
        </div>

        <!-- floating cursor -->
        <!-- canvas -->
        <div class="tracing-viewer-canvas-wrap" id="tracingCanvasArea">
            <div class="tracing-canvas-shell">
                <canvas id="traceCanvas" class="tracing-viewer-canvas" width="680" height="500"></canvas>
            </div>
        </div>

        <!-- toolbar: colors + sizes -->
        <div class="tracing-toolbar" id="tracingToolbar">
            <div class="tracing-color-group">
                <div class="tracing-toolbar-label">🎨 Color</div>
                <div class="tracing-colors" id="colorSwatches">
                    <button type="button" class="tracing-color-swatch active" data-color="#ef4444" style="background:#ef4444;" title="Red" aria-label="Red pencil"></button>
                    <button type="button" class="tracing-color-swatch" data-color="#f97316" style="background:#f97316;" title="Orange" aria-label="Orange pencil"></button>
                    <button type="button" class="tracing-color-swatch" data-color="#eab308" style="background:#eab308;" title="Yellow" aria-label="Yellow pencil"></button>
                    <button type="button" class="tracing-color-swatch" data-color="#22c55e" style="background:#22c55e;" title="Green" aria-label="Green pencil"></button>
                    <button type="button" class="tracing-color-swatch" data-color="#3b82f6" style="background:#3b82f6;" title="Blue" aria-label="Blue pencil"></button>
                    <button type="button" class="tracing-color-swatch" data-color="#a855f7" style="background:#a855f7;" title="Purple" aria-label="Purple pencil"></button>
                    <button type="button" class="tracing-color-swatch" data-color="#1e293b" style="background:#1e293b;" title="Black" aria-label="Black pencil"></button>
                </div>
            </div>
            <div class="tracing-size-group">
                <div class="tracing-toolbar-label">✏️ Size</div>
                <div class="tracing-sizes" id="sizeButtons">
                    <button type="button" class="tracing-size-btn" data-size="3" style="width:40px;height:40px;" title="Thin" aria-label="Thin stroke">
                        <span class="dot" style="width:3px;height:3px;"></span>
                    </button>
                    <button type="button" class="tracing-size-btn active" data-size="6" style="width:44px;height:44px;" title="Medium" aria-label="Medium stroke">
                        <span class="dot" style="width:6px;height:6px;"></span>
                    </button>
                    <button type="button" class="tracing-size-btn" data-size="12" style="width:50px;height:50px;" title="Thick" aria-label="Thick stroke">
                        <span class="dot" style="width:12px;height:12px;"></span>
                    </button>
                    <button type="button" class="tracing-size-btn" data-size="22" style="width:58px;height:58px;" title="Extra thick" aria-label="Extra thick stroke">
                        <span class="dot" style="width:22px;height:22px;"></span>
                    </button>
                </div>
            </div>
        </div>

        <!-- next button -->
        <div class="tracing-nav-row" id="tracingNavRow">
            <button type="button" class="tracing-btn-next" id="nextBtn">Next →</button>
        </div>

        <!-- completed screen -->
        <div class="tracing-completed" id="tracingCompleted">
            <div class="tracing-completed-icon">🌟</div>
            <h2 class="tracing-completed-title">Completed!</h2>
            <p class="tracing-completed-sub">Amazing job! You traced all the images. Keep up the great work! 🎉</p>
            <button type="button" class="tracing-btn-restart" id="restartBtn">↩ Start Again</button>
        </div>

    </div>
</div>

<script>
(function () {
    var images = <?= json_encode(array_values($images), JSON_UNESCAPED_UNICODE) ?>;
    var currentIdx = 0;
    var penColor = '#ef4444';
    var penSize  = 6;
    var drawing  = false;
    var lastX = 0, lastY = 0;

    var canvas      = document.getElementById('traceCanvas');
    var ctx         = canvas.getContext('2d');
    var counterText = document.getElementById('counterText');
    var canvasArea  = document.getElementById('tracingCanvasArea');
    var toolbar     = document.getElementById('tracingToolbar');
    var navRow      = document.getElementById('tracingNavRow');
    var completedEl = document.getElementById('tracingCompleted');
    var nextBtn     = document.getElementById('nextBtn');
    var restartBtn  = document.getElementById('restartBtn');
    /* create cursor at body level to avoid any stacking-context trap */
    var cursorEl = document.createElement('div');
    cursorEl.id = 'tracingCursor';
    document.body.appendChild(cursorEl);

    /* ── helpers ── */
    function getScaledPos(e, isTouch) {
        var rect = canvas.getBoundingClientRect();
        var scaleX = canvas.width  / rect.width;
        var scaleY = canvas.height / rect.height;
        var clientX = isTouch ? e.touches[0].clientX : e.clientX;
        var clientY = isTouch ? e.touches[0].clientY : e.clientY;
        return {
            x: (clientX - rect.left) * scaleX,
            y: (clientY - rect.top)  * scaleY
        };
    }

    function drawImageToCanvas(url) {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        if (!url) return;
        var im = new window.Image();
        im.crossOrigin = 'anonymous';
        im.onload = function () {
            var scale = Math.min(canvas.width / im.width, canvas.height / im.height);
            var x = (canvas.width  - im.width  * scale) / 2;
            var y = (canvas.height - im.height * scale) / 2;
            ctx.drawImage(im, x, y, im.width * scale, im.height * scale);
        };
        im.src = url;
    }

    function showCompleted() {
        canvasArea.style.display  = 'none';
        toolbar.style.display     = 'none';
        navRow.style.display      = 'none';
        document.getElementById('tracingCounter').style.display = 'none';
        completedEl.classList.add('active');
    }

    function showCanvas() {
        canvasArea.style.display  = '';
        toolbar.style.display     = '';
        navRow.style.display      = '';
        document.getElementById('tracingCounter').style.display = '';
        completedEl.classList.remove('active');
    }

    function updateCanvas() {
        if (images.length === 0) {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            counterText.textContent = 'No images';
            return;
        }
        drawImageToCanvas(images[currentIdx].image);
        counterText.textContent = (currentIdx + 1) + ' / ' + images.length;
        nextBtn.textContent = (currentIdx < images.length - 1) ? 'Next →' : 'Finish ✓';
    }

    /* ── navigation ── */
    nextBtn.addEventListener('click', function () {
        if (images.length === 0) return;
        if (currentIdx < images.length - 1) {
            currentIdx++;
            updateCanvas();
        } else {
            showCompleted();
        }
    });

    restartBtn.addEventListener('click', function () {
        currentIdx = 0;
        showCanvas();
        updateCanvas();
    });

    /* ── circle cursor helpers ── */
    function updateCursorStyle() {
        var rect = canvas.getBoundingClientRect();
        var scale = rect.width / canvas.width;
        var d = Math.max(penSize * scale, 4);
        cursorEl.style.width    = d + 'px';
        cursorEl.style.height   = d + 'px';
        cursorEl.style.background = penColor;
        cursorEl.style.opacity  = '0.7';
    }

    canvas.addEventListener('mouseenter', function () {
        updateCursorStyle();
        cursorEl.style.display = 'block';
    });
    canvas.addEventListener('mouseleave', function () {
        cursorEl.style.display = 'none';
        drawing = false;
    });

    /* ── color swatches ── */
    document.querySelectorAll('.tracing-color-swatch').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.tracing-color-swatch').forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            penColor = btn.dataset.color;
            updateCursorStyle();
        });
    });

    /* ── size buttons ── */
    document.querySelectorAll('.tracing-size-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.tracing-size-btn').forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            penSize = parseInt(btn.dataset.size, 10);
            updateCursorStyle();
        });
    });

    window.addEventListener('resize', function () {
        if (cursorEl.style.display !== 'none') updateCursorStyle();
    });

    /* ── drawing: mouse ── */
    canvas.addEventListener('mousedown', function (e) {
        drawing = true;
        var pos = getScaledPos(e, false);
        lastX = pos.x; lastY = pos.y;
        ctx.beginPath();
        ctx.moveTo(lastX, lastY);
    });
    canvas.addEventListener('mouseup',  function () { drawing = false; });
    canvas.addEventListener('mousemove', function (e) {
        /* move floating cursor */
        cursorEl.style.left = e.clientX + 'px';
        cursorEl.style.top  = e.clientY + 'px';
        if (!drawing) return;
        var pos = getScaledPos(e, false);
        stroke(pos.x, pos.y);
    });

    /* ── drawing: touch ── */
    canvas.addEventListener('touchstart', function (e) {
        e.preventDefault();
        drawing = true;
        var pos = getScaledPos(e, true);
        lastX = pos.x; lastY = pos.y;
        ctx.beginPath();
        ctx.moveTo(lastX, lastY);
    }, { passive: false });
    canvas.addEventListener('touchend',    function (e) { e.preventDefault(); drawing = false; }, { passive: false });
    canvas.addEventListener('touchcancel', function (e) { e.preventDefault(); drawing = false; }, { passive: false });
    canvas.addEventListener('touchmove', function (e) {
        e.preventDefault();
        if (!drawing) return;
        var pos = getScaledPos(e, true);
        stroke(pos.x, pos.y);
    }, { passive: false });

    function stroke(x, y) {
        ctx.lineWidth   = penSize;
        ctx.lineCap     = 'round';
        ctx.lineJoin    = 'round';
        ctx.strokeStyle = penColor;
        ctx.lineTo(x, y);
        ctx.stroke();
        ctx.beginPath();
        ctx.moveTo(x, y);
        lastX = x; lastY = y;
    }

    /* ── init ── */
    updateCanvas();
}());
</script>
<?php
$content = ob_get_clean();
render_activity_viewer($activityTitle, '✏️', $content);
