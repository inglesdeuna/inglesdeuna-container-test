<?php
session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_editor_template.php';

// Block student access to editor
if (isset($_SESSION['student_logged']) && $_SESSION['student_logged']) {
    header('Location: /lessons/lessons/academic/student_dashboard.php?error=access_denied');
    exit;
}

// Accept admin OR teacher session
$isLoggedIn = !empty($_SESSION['academic_logged']) || !empty($_SESSION['admin_logged']);
if (!$isLoggedIn) {
    header('Location: /lessons/lessons/academic/login.php');
    exit;
}

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
$source = isset($_GET['source']) ? trim((string) $_GET['source']) : '';
$assignment = isset($_GET['assignment']) ? trim((string) $_GET['assignment']) : '';

// For now, no DB save/load, just a static editor
ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Tracing Activity Editor</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f9fafb; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 30px auto; background: #fff; border-radius: 12px; box-shadow: 0 2px 12px #0001; padding: 24px; }
        h2 { text-align: center; }
        #canvasWrap { position: relative; display: flex; justify-content: center; margin-bottom: 16px; }
        canvas { border: 2px solid #2563eb; border-radius: 10px; background: #fff; }
        #upload { margin-bottom: 16px; }
        .btn { background: #2563eb; color: #fff; border: none; padding: 10px 18px; border-radius: 8px; cursor: pointer; font-weight: 700; }
        .btn:hover { background: #1d4ed8; }
    </style>
</head>
<body>
<div class="container">
    <h2>Tracing Activity Editor</h2>
    <input type="file" id="upload" accept="image/*">
    <div id="canvasWrap">
        <canvas id="traceCanvas" width="400" height="400"></canvas>
    </div>
    <button class="btn" id="clearBtn">Clear Tracing</button>
</div>
<script>
const canvas = document.getElementById('traceCanvas');
const ctx = canvas.getContext('2d');
let drawing = false;
let img = new window.Image();

function drawImageToCanvas(image) {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    let scale = Math.min(canvas.width / image.width, canvas.height / image.height);
    let x = (canvas.width - image.width * scale) / 2;
    let y = (canvas.height - image.height * scale) / 2;
    ctx.drawImage(image, x, y, image.width * scale, image.height * scale);
}

document.getElementById('upload').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function(evt) {
        img.onload = function() {
            drawImageToCanvas(img);
        };
        img.src = evt.target.result;
    };
    reader.readAsDataURL(file);
});

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

document.getElementById('clearBtn').onclick = function() {
    if (img.src) drawImageToCanvas(img);
    else ctx.clearRect(0, 0, canvas.width, canvas.height);
};
</script>
</body>
</html>
<?php
$content = ob_get_clean();
render_activity_editor('✏️ Tracing Editor', '✏️', $content);
