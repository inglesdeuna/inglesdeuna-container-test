<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/coloring_functions.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';

$activity = load_coloring_activity($pdo, $unit, $activityId);
$images = isset($activity['images']) && is_array($activity['images']) ? $activity['images'] : array();
$activityTitle = isset($activity['title']) ? (string) $activity['title'] : default_coloring_title();

ob_start();
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800&display=swap');
body { overflow: hidden !important; }
.coloring-viewer-shell { max-width:980px; margin:0 auto; text-align:center; font-family:'Nunito','Segoe UI',sans-serif; height:calc(100vh - 90px); display:flex; flex-direction:column; gap:6px; overflow:hidden; }
.viewer-header { display:none !important; }
.coloring-stage { background:#fff; border:2px solid #fde68a; border-radius:24px; box-shadow:0 12px 36px rgba(120,53,15,.10); padding:10px 12px; flex:1; min-height:0; display:flex; flex-direction:column; gap:6px; overflow:hidden; }
.coloring-counter { display:inline-flex; align-items:center; gap:6px; background:linear-gradient(135deg,#fef3c7,#fde68a); border:1.5px solid #f59e0b; border-radius:999px; padding:5px 16px; font-size:15px; font-weight:800; color:#92400e; flex-shrink:0; letter-spacing:.02em; }
.coloring-counter-dot { width:8px; height:8px; border-radius:50%; background:#f59e0b; display:inline-block; }
.coloring-canvas-wrap { flex:1; min-height:0; display:flex; justify-content:center; align-items:center; }
.coloring-canvas-shell { width:min(100%,760px); height:100%; padding:10px; background:linear-gradient(180deg,#fff7ed 0%,#fffbeb 100%); border:2px solid #fdba74; border-radius:18px; box-shadow:inset 0 2px 8px rgba(120,53,15,.08); display:flex; align-items:center; justify-content:center; box-sizing:border-box; }
.worksheet-frame { position:relative; width:min(100%,520px); aspect-ratio:3 / 4; background:#fff; border:2px dashed #fdba74; border-radius:10px; padding:8px; display:flex; align-items:center; justify-content:center; }
.worksheet-title { position:absolute; top:6px; left:8px; right:8px; text-align:center; font-size:13px; font-weight:800; color:#7c2d12; text-transform:uppercase; letter-spacing:.04em; pointer-events:none; }
.coloring-canvas { display:block; max-width:min(100%,520px); max-height:100%; width:auto; height:auto; border:4px solid #f59e0b; border-radius:8px; background:#fff; touch-action:none; cursor:none; box-shadow:0 8px 24px rgba(120,53,15,.14); }
#coloringCursor { position:fixed; pointer-events:none; border-radius:50%; border:2px solid rgba(255,255,255,0.75); box-shadow:0 0 0 1.5px rgba(0,0,0,0.30); transform:translate(-50%,-50%); z-index:9999; display:none; will-change:transform,left,top; }
.coloring-toolbar { display:flex; flex-wrap:wrap; align-items:center; justify-content:center; gap:8px 16px; flex-shrink:0; background:linear-gradient(135deg,#fef2f2,#fff7ed); border:1.5px solid #fecaca; border-radius:18px; padding:8px 14px; }
.coloring-toolbar-label { font-size:12px; font-weight:800; color:#9a3412; text-transform:uppercase; letter-spacing:.05em; margin-bottom:6px; }
.coloring-color-group,.coloring-size-group { display:flex; flex-direction:column; align-items:center; gap:6px; }
.coloring-colors,.coloring-sizes { display:flex; gap:8px; flex-wrap:wrap; justify-content:center; align-items:center; }
.coloring-color-swatch { width:36px; height:36px; border-radius:50%; border:3px solid transparent; cursor:pointer; transition:transform .15s, box-shadow .15s; box-shadow:0 2px 6px rgba(0,0,0,.18); -webkit-tap-highlight-color:transparent; }
.coloring-color-swatch:hover,.coloring-color-swatch.active { transform:scale(1.22); border-color:#fff; box-shadow:0 0 0 3px #64748b, 0 4px 10px rgba(0,0,0,.2); }
.coloring-color-swatch.active { box-shadow:0 0 0 3px #ea580c, 0 4px 10px rgba(0,0,0,.2); }
.coloring-size-btn { background:#fff; border:2.5px solid #d1d5db; border-radius:50%; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:transform .15s, border-color .15s, box-shadow .15s; -webkit-tap-highlight-color:transparent; box-shadow:0 2px 6px rgba(0,0,0,.10); }
.coloring-size-btn:hover,.coloring-size-btn.active { transform:scale(1.18); border-color:#f97316; box-shadow:0 0 0 3px #fed7aa; }
.coloring-size-btn.active { border-color:#c2410c; box-shadow:0 0 0 3px #fdba74; }
.coloring-size-btn .dot { border-radius:50%; background:#1e293b; pointer-events:none; }
.coloring-nav-row { display:flex; justify-content:center; flex-shrink:0; }
.coloring-btn-next { padding:14px 42px; font-size:18px; font-weight:800; font-family:'Fredoka','Nunito',sans-serif; border:none; border-radius:999px; cursor:pointer; color:#fff; background:linear-gradient(135deg,#f97316 0%,#ea580c 100%); box-shadow:0 8px 20px rgba(234,88,12,.30); letter-spacing:.03em; min-width:160px; }
.coloring-completed { display:none; flex-direction:column; align-items:center; padding:40px 20px 30px; }
.coloring-completed.active { display:flex; }
.coloring-completed-title { font-family:'Fredoka','Trebuchet MS',sans-serif; font-size:clamp(32px,4vw,48px); font-weight:700; color:#ea580c; margin:0 0 10px; }
.coloring-completed-sub { font-size:17px; font-weight:700; color:#374151; margin:0 0 28px; max-width:360px; }
.coloring-btn-restart { padding:13px 36px; font-size:17px; font-weight:800; font-family:'Fredoka','Nunito',sans-serif; border:none; border-radius:999px; cursor:pointer; color:#fff; background:linear-gradient(135deg,#3b82f6 0%,#2563eb 100%); }
@media (max-width:600px){ .coloring-viewer-shell{height:calc(100vh - 78px);} .worksheet-frame{width:min(100%,420px);} .coloring-color-swatch{width:30px;height:30px;} }
</style>
<div class="coloring-viewer-shell">
    <div class="coloring-stage">
        <div class="coloring-counter" id="coloringCounter"><span class="coloring-counter-dot"></span><span id="counterText">— / —</span></div>
        <div class="coloring-canvas-wrap" id="coloringCanvasArea"><div class="coloring-canvas-shell"><div class="worksheet-frame"><div class="worksheet-title" id="worksheetTitle">Color this worksheet</div><canvas id="coloringCanvas" class="coloring-canvas" width="540" height="720"></canvas></div></div></div>
        <div class="coloring-toolbar" id="coloringToolbar">
            <div class="coloring-color-group"><div class="coloring-toolbar-label">Color</div><div class="coloring-colors">
                <button type="button" class="coloring-color-swatch active" data-color="#ef4444" style="background:#ef4444;"></button>
                <button type="button" class="coloring-color-swatch" data-color="#f97316" style="background:#f97316;"></button>
                <button type="button" class="coloring-color-swatch" data-color="#eab308" style="background:#eab308;"></button>
                <button type="button" class="coloring-color-swatch" data-color="#22c55e" style="background:#22c55e;"></button>
                <button type="button" class="coloring-color-swatch" data-color="#3b82f6" style="background:#3b82f6;"></button>
                <button type="button" class="coloring-color-swatch" data-color="#8b5cf6" style="background:#8b5cf6;"></button>
                <button type="button" class="coloring-color-swatch" data-color="#ec4899" style="background:#ec4899;"></button>
                <button type="button" class="coloring-color-swatch" data-color="#92400e" style="background:#92400e;"></button>
                <button type="button" class="coloring-color-swatch" data-color="#111827" style="background:#111827;"></button>
            </div></div>
            <div class="coloring-size-group"><div class="coloring-toolbar-label">Size</div><div class="coloring-sizes">
                <button type="button" class="coloring-size-btn" data-size="4" style="width:40px;height:40px;"><span class="dot" style="width:4px;height:4px;"></span></button>
                <button type="button" class="coloring-size-btn" data-size="8" style="width:44px;height:44px;"><span class="dot" style="width:8px;height:8px;"></span></button>
                <button type="button" class="coloring-size-btn active" data-size="14" style="width:50px;height:50px;"><span class="dot" style="width:14px;height:14px;"></span></button>
                <button type="button" class="coloring-size-btn" data-size="22" style="width:58px;height:58px;"><span class="dot" style="width:20px;height:20px;"></span></button>
            </div></div>
        </div>
        <div class="coloring-nav-row" id="coloringNavRow"><button type="button" class="coloring-btn-next" id="nextBtn">Next →</button></div>
        <div class="coloring-completed" id="coloringCompleted"><div style="font-size:88px;line-height:1;">🌟</div><h2 class="coloring-completed-title">Completed!</h2><p class="coloring-completed-sub">Amazing job! You colored all the pages.</p><button type="button" class="coloring-btn-restart" id="restartBtn">Start Again</button></div>
    </div>
</div>
<script>
(function () {
    var images = <?= json_encode(array_values($images), JSON_UNESCAPED_UNICODE) ?>;
    var currentIdx = 0;
    var penColor = '#ef4444';
    var penSize = 14;
    var drawing = false;
    var canvas = document.getElementById('coloringCanvas');
    var ctx = canvas.getContext('2d');
    var counterText = document.getElementById('counterText');
    var canvasArea = document.getElementById('coloringCanvasArea');
    var toolbar = document.getElementById('coloringToolbar');
    var navRow = document.getElementById('coloringNavRow');
    var completedEl = document.getElementById('coloringCompleted');
    var nextBtn = document.getElementById('nextBtn');
    var restartBtn = document.getElementById('restartBtn');
    var worksheetTitleEl = document.getElementById('worksheetTitle');
    var cursorEl = document.createElement('div');
    cursorEl.id = 'coloringCursor';
    document.body.appendChild(cursorEl);

    function getScaledPos(e, isTouch) {
        var rect = canvas.getBoundingClientRect();
        var scaleX = canvas.width / rect.width;
        var scaleY = canvas.height / rect.height;
        var clientX = isTouch ? e.touches[0].clientX : e.clientX;
        var clientY = isTouch ? e.touches[0].clientY : e.clientY;
        return { x: (clientX - rect.left) * scaleX, y: (clientY - rect.top) * scaleY };
    }
    function drawImageToCanvas(url) {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        if (!url) return;
        var im = new window.Image();
        im.crossOrigin = 'anonymous';
        im.onload = function () {
            var scale = Math.min(canvas.width / im.width, canvas.height / im.height);
            var x = (canvas.width - im.width * scale) / 2;
            var y = (canvas.height - im.height * scale) / 2;
            ctx.filter = 'grayscale(1) contrast(1.28) brightness(1.06)';
            ctx.drawImage(im, x, y, im.width * scale, im.height * scale);
            ctx.filter = 'none';
        };
        im.src = url;
    }
    function updateCursorStyle() {
        var rect = canvas.getBoundingClientRect();
        var scale = rect.width / canvas.width;
        var d = Math.max(penSize * scale, 4);
        cursorEl.style.width = d + 'px';
        cursorEl.style.height = d + 'px';
        cursorEl.style.background = penColor;
        cursorEl.style.opacity = '0.7';
    }
    function updateCanvas() {
        if (images.length === 0) {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            counterText.textContent = 'No images';
            if (worksheetTitleEl) worksheetTitleEl.textContent = 'No worksheet uploaded';
            return;
        }
        drawImageToCanvas(images[currentIdx].image);
        counterText.textContent = (currentIdx + 1) + ' / ' + images.length;
        nextBtn.textContent = (currentIdx < images.length - 1) ? 'Next →' : 'Finish ✓';
        if (worksheetTitleEl) worksheetTitleEl.textContent = 'Coloring page ' + (currentIdx + 1);
    }
    function showCompleted() { canvasArea.style.display='none'; toolbar.style.display='none'; navRow.style.display='none'; document.getElementById('coloringCounter').style.display='none'; completedEl.classList.add('active'); }
    function showCanvas() { canvasArea.style.display=''; toolbar.style.display=''; navRow.style.display=''; document.getElementById('coloringCounter').style.display=''; completedEl.classList.remove('active'); }
    nextBtn.addEventListener('click', function () { if (!images.length) return; if (currentIdx < images.length - 1) { currentIdx++; updateCanvas(); } else { showCompleted(); } });
    restartBtn.addEventListener('click', function () { currentIdx = 0; showCanvas(); updateCanvas(); });
    canvas.addEventListener('mouseenter', function () { updateCursorStyle(); cursorEl.style.display = 'block'; });
    canvas.addEventListener('mouseleave', function () { cursorEl.style.display = 'none'; drawing = false; });
    document.querySelectorAll('.coloring-color-swatch').forEach(function (btn) { btn.addEventListener('click', function () { document.querySelectorAll('.coloring-color-swatch').forEach(function (b) { b.classList.remove('active'); }); btn.classList.add('active'); penColor = btn.dataset.color; updateCursorStyle(); }); });
    document.querySelectorAll('.coloring-size-btn').forEach(function (btn) { btn.addEventListener('click', function () { document.querySelectorAll('.coloring-size-btn').forEach(function (b) { b.classList.remove('active'); }); btn.classList.add('active'); penSize = parseInt(btn.dataset.size, 10); updateCursorStyle(); }); });
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
    canvas.addEventListener('mousedown', function (e) { drawing = true; var pos = getScaledPos(e, false); ctx.beginPath(); ctx.moveTo(pos.x, pos.y); });
    canvas.addEventListener('mouseup', function () { drawing = false; });
    canvas.addEventListener('mousemove', function (e) { cursorEl.style.left = e.clientX + 'px'; cursorEl.style.top = e.clientY + 'px'; if (!drawing) return; var pos = getScaledPos(e, false); stroke(pos.x, pos.y); });
    canvas.addEventListener('touchstart', function (e) { e.preventDefault(); drawing = true; var pos = getScaledPos(e, true); ctx.beginPath(); ctx.moveTo(pos.x, pos.y); }, { passive:false });
    canvas.addEventListener('touchend', function (e) { e.preventDefault(); drawing = false; }, { passive:false });
    canvas.addEventListener('touchcancel', function (e) { e.preventDefault(); drawing = false; }, { passive:false });
    canvas.addEventListener('touchmove', function (e) { e.preventDefault(); if (!drawing) return; var pos = getScaledPos(e, true); stroke(pos.x, pos.y); }, { passive:false });
    window.addEventListener('resize', function () { if (cursorEl.style.display !== 'none') updateCursorStyle(); });
    updateCanvas();
}());
</script>
<?php
$content = ob_get_clean();
render_activity_viewer($activityTitle, '🎨', $content);