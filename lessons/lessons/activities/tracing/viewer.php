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

.tracing-viewer-shell{
    max-width:980px;
    margin:0 auto;
    text-align:center;
    font-family:'Nunito', 'Segoe UI', sans-serif;
}

.viewer-header{ display:none !important; }

.tracing-intro{
    margin-bottom:14px;
    padding:16px 18px;
    border-radius:20px;
    border:1px solid #d9f99d;
    background:linear-gradient(135deg, #ecfccb 0%, #dcfce7 48%, #f0fdf4 100%);
    box-shadow:0 12px 28px rgba(15, 23, 42, .08);
}

.tracing-intro h2{
    margin:0 0 8px;
    font-size:clamp(26px, 2.2vw, 30px);
    line-height:1.1;
    font-family:'Fredoka', 'Trebuchet MS', sans-serif;
    font-weight:700;
    color:#14532d;
}

.tracing-intro p{
    margin:0;
    color:#365314;
    font-weight:700;
}

.tracing-stage{
    background:rgba(255, 255, 255, .76);
    border:1px solid #e2e8f0;
    border-radius:22px;
    box-shadow:0 14px 32px rgba(15, 23, 42, .1);
    padding:14px;
}

.tracing-viewer-toolbar{
    display:flex;
    justify-content:center;
    gap:10px;
    margin-bottom:14px;
    flex-wrap:wrap;
}

.tracing-viewer-btn{
    border:none;
    padding:11px 18px;
    min-width:142px;
    border-radius:999px;
    cursor:pointer;
    font-weight:800;
    font-size:14px;
    color:#fff;
    transition:transform .15s ease, filter .15s ease;
}

.tracing-viewer-btn:hover{
    filter:brightness(1.05);
    transform:translateY(-1px);
}

.tracing-viewer-btn-prev{
    background:linear-gradient(180deg, #38bdf8 0%, #0284c7 100%);
}

.tracing-viewer-btn-next{
    background:linear-gradient(180deg, #2dd4bf 0%, #0f766e 100%);
}

.tracing-viewer-btn-clear{
    background:linear-gradient(180deg, #fb7185 0%, #e11d48 100%);
}

.tracing-viewer-btn:focus-visible{
    outline:none;
    box-shadow:0 0 0 3px rgba(14, 165, 233, .22);
}

.tracing-viewer-canvas-wrap{
    display:flex;
    justify-content:center;
    margin-bottom:10px;
}

.tracing-canvas-shell{
    width:min(100%, 660px);
    padding:10px;
    background:#f8fafc;
    border:1px solid #cbd5e1;
    border-radius:18px;
}

.tracing-viewer-canvas{
    display:block;
    width:100%;
    height:auto;
    max-width:640px;
    border:2px solid #14b8a6;
    border-radius:12px;
    background:#fff;
    cursor:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='80' height='80' viewBox='0 0 80 80'%3E%3Cg transform='rotate(45 40 40)'%3E%3Crect x='35' y='5' width='10' height='12' rx='3' fill='%23fca5a5'/%3E%3Crect x='35' y='17' width='10' height='5' fill='%239ca3af'/%3E%3Crect x='35' y='22' width='10' height='30' fill='%23fde68a'/%3E%3Cpolygon points='35,52 45,52 40,65' fill='%23fed7aa'/%3E%3Cpolygon points='38.5,61 41.5,61 40,67' fill='%231f2937'/%3E%3C/g%3E%3C/svg%3E") 21 59, crosshair;
}

.tracing-counter{
    text-align:center;
    font-size:14px;
    color:#475569;
    font-weight:800;
}

@media (max-width: 768px){
    .tracing-viewer-btn{
        width:100%;
        max-width:300px;
        min-width:0;
    }

    .tracing-intro h2{
        font-size:24px;
    }
}
</style>
<div class="tracing-viewer-shell">
    <div class="tracing-stage">
        <div class="tracing-viewer-toolbar">
            <button class="tracing-viewer-btn tracing-viewer-btn-prev" id="prevBtn">Previous</button>
            <button class="tracing-viewer-btn tracing-viewer-btn-next" id="nextBtn">Next</button>
            <button class="tracing-viewer-btn tracing-viewer-btn-clear" id="clearBtn">Clear</button>
        </div>

        <div class="tracing-viewer-canvas-wrap">
            <div class="tracing-canvas-shell">
                <canvas id="traceCanvas" class="tracing-viewer-canvas" width="640" height="460"></canvas>
            </div>
        </div>

        <div class="tracing-counter">
            <span id="imageCounter"></span>
        </div>
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
        document.getElementById('imageCounter').textContent = 'No images available';
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
render_activity_viewer($activityTitle, '✏️', $content);
