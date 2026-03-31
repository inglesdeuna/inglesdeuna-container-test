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
.tracing-viewer-shell { max-width: 520px; margin: 0 auto; }
.tracing-viewer-title { font-size: 1.3rem; font-weight: 700; margin-bottom: 18px; text-align: center; }
.tracing-viewer-canvas-wrap { display: flex; justify-content: center; margin-bottom: 16px; }
.tracing-viewer-canvas { border: 2px solid #2563eb; border-radius: 10px; background: #fff; }
.tracing-viewer-toolbar { display: flex; justify-content: center; gap: 12px; margin-bottom: 18px; }
.tracing-viewer-btn { background: #2563eb; color: #fff; border: none; padding: 8px 16px; border-radius: 8px; cursor: pointer; font-weight: 700; font-size: 15px; }
.tracing-viewer-btn:hover { background: #1d4ed8; }
.tracing-viewer-btn-clear { background: #ef4444; }
.tracing-viewer-btn-clear:hover { background: #b91c1c; }
</style>
<div class="tracing-viewer-shell">
    <div class="tracing-viewer-title"><?= htmlspecialchars($activityTitle, ENT_QUOTES, 'UTF-8') ?></div>
    <div class="tracing-viewer-toolbar">
        <button class="tracing-viewer-btn" id="prevBtn">⟨ Prev</button>
        <button class="tracing-viewer-btn" id="nextBtn">Next ⟩</button>
        <button class="tracing-viewer-btn tracing-viewer-btn-clear" id="clearBtn">Clear</button>
    </div>
    <div class="tracing-viewer-canvas-wrap">
        <canvas id="traceCanvas" class="tracing-viewer-canvas" width="400" height="400"></canvas>
    </div>
    <div style="text-align:center;font-size:14px;color:#64748b;">
        <span id="imageCounter"></span>
    </div>
</div>
<script>
const images = <?= json_encode($images, JSON_UNESCAPED_UNICODE) ?>;
let currentIdx = 0;
const canvas = document.getElementById('traceCanvas');
const ctx = canvas.getContext('2d');
let drawing = false;
let img = new window.Image();

function drawImageToCanvas(imageUrl) {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    if (!imageUrl) return;
    img = new window.Image();
    img.onload = function() {
        let scale = Math.min(canvas.width / img.width, canvas.height / img.height);
        let x = (canvas.width - img.width * scale) / 2;
        let y = (canvas.height - img.height * scale) / 2;
        ctx.drawImage(img, x, y, img.width * scale, img.height * scale);
    };
    img.src = imageUrl;
}

function updateCanvas() {
    if (images.length === 0) {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        document.getElementById('imageCounter').textContent = 'No images';
        return;
    }
    drawImageToCanvas(images[currentIdx].image);
    document.getElementById('imageCounter').textContent = (currentIdx + 1) + ' / ' + images.length;
}

document.getElementById('prevBtn').onclick = function() {
    if (images.length === 0) return;
    currentIdx = (currentIdx - 1 + images.length) % images.length;
    updateCanvas();
};
document.getElementById('nextBtn').onclick = function() {
    if (images.length === 0) return;
    currentIdx = (currentIdx + 1) % images.length;
    updateCanvas();
};

document.getElementById('clearBtn').onclick = function() {
    updateCanvas();
};

canvas.addEventListener('mousedown', e => { drawing = true; ctx.beginPath(); });
canvas.addEventListener('mouseup', e => { drawing = false; });
canvas.addEventListener('mouseout', e => { drawing = false; });
canvas.addEventListener('mousemove', draw);

canvas.addEventListener('touchstart', e => { drawing = true; ctx.beginPath(); });
canvas.addEventListener('touchend', e => { drawing = false; });
canvas.addEventListener('touchcancel', e => { drawing = false; });
canvas.addEventListener('touchmove', function(e) { draw(e, true); });

function getPos(e, isTouch) {
    let rect = canvas.getBoundingClientRect();
    if (isTouch && e.touches[0]) {
        return {
            x: e.touches[0].clientX - rect.left,
            y: e.touches[0].clientY - rect.top
        };
    } else {
        return {
            x: e.clientX - rect.left,
            y: e.clientY - rect.top
        };
    }
}

function draw(e, isTouch) {
    if (!drawing) return;
    e.preventDefault();
    let pos = getPos(e, isTouch);
    ctx.lineWidth = 6;
    ctx.lineCap = 'round';
    ctx.strokeStyle = '#16a34a';
    ctx.lineTo(pos.x, pos.y);
    ctx.stroke();
    ctx.beginPath();
    ctx.moveTo(pos.x, pos.y);
}

updateCanvas();
</script>
<?php
$content = ob_get_clean();
render_activity_viewer('✏️ Tracing', '✏️', $content);
