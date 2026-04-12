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

body {
    overflow: hidden !important;
}

.tracing-viewer-shell {
    max-width: 920px;
    margin: 0 auto;
    text-align: center;
    font-family: 'Nunito', 'Segoe UI', sans-serif;
    height: calc(100vh - 90px);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.viewer-header {
    display: none !important;
}

.tracing-stage {
    flex: 1;
    min-height: 0;
    display: flex;
    flex-direction: column;
    gap: 10px;
    background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
    border: 2px solid #bfdbfe;
    border-radius: 24px;
    box-shadow: 0 12px 36px rgba(59, 130, 246, .10);
    padding: 12px;
    overflow: hidden;
}

.tracing-intro {
    flex-shrink: 0;
    background: linear-gradient(135deg, #fef3c7 0%, #dbeafe 55%, #dcfce7 100%);
    border: 2px solid #93c5fd;
    border-radius: 20px;
    padding: 10px 14px;
}

.tracing-intro h2 {
    margin: 0 0 4px;
    font-family: 'Fredoka', 'Trebuchet MS', sans-serif;
    font-size: clamp(22px, 2.7vw, 30px);
    color: #1d4ed8;
}

.tracing-intro p {
    margin: 0;
    color: #475569;
    font-size: 14px;
    font-weight: 700;
}

.tracing-counter {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    align-self: center;
    background: linear-gradient(135deg, #e0f2fe, #bfdbfe);
    border: 1.5px solid #7dd3fc;
    border-radius: 999px;
    padding: 6px 16px;
    font-size: 15px;
    font-weight: 800;
    color: #1d4ed8;
    flex-shrink: 0;
}

.tracing-counter-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #0ea5e9;
    display: inline-block;
}

.tracing-canvas-wrap {
    flex: 1;
    min-height: 0;
    display: flex;
    justify-content: center;
    align-items: center;
}

.tracing-canvas-shell {
    width: min(100%, 760px);
    height: 100%;
    padding: 10px;
    background: linear-gradient(180deg, #f0f9ff 0%, #ffffff 100%);
    border: 2px solid #bae6fd;
    border-radius: 20px;
    box-shadow: inset 0 2px 8px rgba(14, 165, 233, .08);
    display: flex;
    justify-content: center;
    align-items: center;
    overflow: hidden;
}

.tracing-canvas {
    display: block;
    max-width: 100%;
    max-height: 100%;
    width: auto;
    height: auto;
    background: #fff;
    border: 3px solid #38bdf8;
    border-radius: 16px;
    box-shadow: 0 4px 18px rgba(14, 165, 233, .10);
    touch-action: none;
    cursor: none;
}

#tracingCursor {
    position: fixed;
    pointer-events: none;
    border-radius: 50%;
    transform: translate(-50%, -50%);
    border: 2px solid rgba(255,255,255,.8);
    box-shadow: 0 0 0 1.5px rgba(0,0,0,.24);
    background: rgba(37, 99, 235, .75);
    z-index: 9999;
    display: none;
}

.tracing-toolbar {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 18px;
    flex-wrap: wrap;
    flex-shrink: 0;
    padding: 12px 14px;
    border: 1px solid #dbeafe;
    border-radius: 18px;
    background: linear-gradient(135deg, #eff6ff, #f8fafc);
}

.tracing-toolbar-label {
    font-size: 12px;
    font-weight: 800;
    color: #1e40af;
    text-transform: uppercase;
    letter-spacing: .05em;
}


.tracing-color-group,
.tracing-size-group {
    display: flex;
    align-items: center;
    gap: 8px;
}

.tracing-color-swatch {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    border: 3px solid transparent;
    cursor: pointer;
    box-shadow: 0 2px 8px rgba(0,0,0,.14);
    transition: transform .15s ease, box-shadow .15s ease;
}

.tracing-color-swatch.active,
.tracing-color-swatch:hover {
    transform: scale(1.14);
    border-color: #fff;
    box-shadow: 0 0 0 3px #2563eb, 0 4px 12px rgba(0,0,0,.16);
}

.tracing-size-btn {
    background: #fff;
    border: 2px solid #cbd5e1;
    border-radius: 50%;
    width: 42px;
    height: 42px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    box-shadow: 0 2px 6px rgba(0,0,0,.10);
}

.tracing-size-btn.active {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px #bfdbfe;
}

.tracing-size-btn .dot {
    border-radius: 50%;
    background: #2563eb;
}

.tracing-actions {
    display: flex;
    justify-content: center;
    gap: 12px;
    flex-wrap: wrap;
    flex-shrink: 0;
}

.tracing-btn {
    border: none;
    border-radius: 999px;
    padding: 12px 28px;
    font-size: 16px;
    font-weight: 800;
    font-family: 'Fredoka', 'Nunito', sans-serif;
    color: #fff;
    cursor: pointer;
}

.tracing-btn-next {
    background: linear-gradient(135deg, #22c55e, #16a34a);
}

.tracing-completed {
    display: none;
    flex: 1;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    padding: 20px;
}

.tracing-completed.active {
    display: flex;
}

.tracing-completed h2 {
    margin: 0 0 10px;
    font-family: 'Fredoka', 'Trebuchet MS', sans-serif;
    font-size: clamp(32px, 4vw, 48px);
    color: #0f766e;
}

.tracing-completed p {
    margin: 0 0 22px;
    color: #475569;
    font-weight: 700;
}

@media (max-width: 600px) {
    .tracing-viewer-shell {
        height: calc(100vh - 78px);
    }

    .tracing-btn {
        min-width: 136px;
        padding: 11px 20px;
    }
}
</style>

<div class="tracing-viewer-shell">
    <div class="tracing-stage">
        <div class="tracing-intro">
            <h2>Trace and Practice</h2>
            <p>Choose a color, pick a pencil size, and trace each page in order.</p>
        </div>

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
                <button type="button" class="tracing-color-swatch active" data-color="#2563eb" style="background:#2563eb;" aria-label="Blue pencil"></button>
                <button type="button" class="tracing-color-swatch" data-color="#ef4444" style="background:#ef4444;" aria-label="Red pencil"></button>
                <button type="button" class="tracing-color-swatch" data-color="#f59e0b" style="background:#f59e0b;" aria-label="Orange pencil"></button>
                <button type="button" class="tracing-color-swatch" data-color="#22c55e" style="background:#22c55e;" aria-label="Green pencil"></button>
                <button type="button" class="tracing-color-swatch" data-color="#a855f7" style="background:#a855f7;" aria-label="Purple pencil"></button>
                <button type="button" class="tracing-color-swatch" data-color="#ec4899" style="background:#ec4899;" aria-label="Pink pencil"></button>
            </div>
            <span class="tracing-toolbar-label">Pencil Size</span>
            <div class="tracing-size-group">
                <button type="button" class="tracing-size-btn" data-size="4" aria-label="Thin pencil"><span class="dot" style="width:4px;height:4px;"></span></button>
                <button type="button" class="tracing-size-btn active" data-size="8" aria-label="Medium pencil"><span class="dot" style="width:8px;height:8px;"></span></button>
                <button type="button" class="tracing-size-btn" data-size="14" aria-label="Thick pencil"><span class="dot" style="width:14px;height:14px;"></span></button>
            </div>
        </div>

        <div class="tracing-actions" id="tracingActions">
            <button type="button" class="tracing-btn tracing-btn-next" id="nextBtn">Next</button>
        </div>

        <div class="tracing-completed" id="tracingCompleted">
            <div style="font-size:86px;line-height:1;">✍️</div>
            <h2>Completed</h2>
            <p>You finished all tracing pages.</p>
            <button type="button" class="tracing-btn tracing-btn-next" id="restartBtn">Start Again</button>
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

    function getScaledPos(e, isTouch) {
        var rect = canvas.getBoundingClientRect();
        var scaleX = canvas.width / rect.width;
        var scaleY = canvas.height / rect.height;
        var clientX = isTouch ? e.touches[0].clientX : e.clientX;
        var clientY = isTouch ? e.touches[0].clientY : e.clientY;
        return {
            x: (clientX - rect.left) * scaleX,
            y: (clientY - rect.top) * scaleY
        };
    }

    function drawGuide(url) {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        if (!url) {
            return;
        }

        var image = new window.Image();
        image.crossOrigin = 'anonymous';
        image.onload = function () {
            var scale = Math.min(canvas.width / image.width, canvas.height / image.height);
            var x = (canvas.width - image.width * scale) / 2;
            var y = (canvas.height - image.height * scale) / 2;
            ctx.drawImage(image, x, y, image.width * scale, image.height * scale);
        };
        image.src = url;
    }

    function renderCurrentPage() {
        if (!images.length) {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            counterText.textContent = 'No images';
            return;
        }

        drawGuide(images[currentIdx].image);
        counterText.textContent = (currentIdx + 1) + ' / ' + images.length;
        nextBtn.textContent = currentIdx < images.length - 1 ? 'Next' : 'Finish';
    }

    function showCompleted() {
        counterEl.style.display = 'none';
        wrapEl.style.display = 'none';
        toolbarEl.style.display = 'none';
        actionsEl.style.display = 'none';
        completedEl.classList.add('active');
    }

    function showTracing() {
        counterEl.style.display = '';
        wrapEl.style.display = '';
        toolbarEl.style.display = 'flex';
        actionsEl.style.display = 'flex';
        completedEl.classList.remove('active');
    }

    document.querySelectorAll('.tracing-size-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.tracing-size-btn').forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            penSize = parseInt(btn.dataset.size, 10) || 8;
        });
    });

    document.querySelectorAll('.tracing-color-swatch').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.tracing-color-swatch').forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            penColor = btn.dataset.color || '#2563eb';
        });
    });

    nextBtn.addEventListener('click', function () {
        if (!images.length) {
            return;
        }
        if (currentIdx < images.length - 1) {
            currentIdx += 1;
            renderCurrentPage();
        } else {
            showCompleted();
        }
    });

    restartBtn.addEventListener('click', function () {
        currentIdx = 0;
        showTracing();
        renderCurrentPage();
    });

    function stroke(x, y) {
        ctx.lineWidth = penSize;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.strokeStyle = penColor;
        ctx.lineTo(x, y);
        ctx.stroke();
        ctx.beginPath();
        ctx.moveTo(x, y);
    }

    canvas.addEventListener('mousedown', function (e) {
        drawing = true;
        var pos = getScaledPos(e, false);
        ctx.beginPath();
        ctx.moveTo(pos.x, pos.y);
    });

    canvas.addEventListener('mouseup', function () {
        drawing = false;
    });

    canvas.addEventListener('mousemove', function (e) {
        if (!drawing) {
            return;
        }
        var pos = getScaledPos(e, false);
        stroke(pos.x, pos.y);
    });

    canvas.addEventListener('touchstart', function (e) {
        e.preventDefault();
        drawing = true;
        var pos = getScaledPos(e, true);
        ctx.beginPath();
        ctx.moveTo(pos.x, pos.y);
    }, { passive: false });

    canvas.addEventListener('touchend', function (e) {
        e.preventDefault();
        drawing = false;
    }, { passive: false });

    canvas.addEventListener('touchcancel', function (e) {
        e.preventDefault();
        drawing = false;
    }, { passive: false });

    canvas.addEventListener('touchmove', function (e) {
        e.preventDefault();
        if (!drawing) {
            return;
        }
        var pos = getScaledPos(e, true);
        stroke(pos.x, pos.y);
    }, { passive: false });

    renderCurrentPage();
}());
</script>
<?php
$content = ob_get_clean();
render_activity_viewer($activityTitle, '✏️', $content);
