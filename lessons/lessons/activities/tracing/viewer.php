<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';

function default_tracing_title(): string { return 'Tracing'; }
function normalize_tracing_title(string $title): string { $title = trim($title); return $title !== '' ? $title : default_tracing_title(); }
function normalize_tracing_payload($rawData): array {
    $default = array('title' => default_tracing_title(), 'images' => array());
    if ($rawData === null || $rawData === '') return $default;
    $decoded = is_string($rawData) ? json_decode($rawData, true) : $rawData;
    if (!is_array($decoded)) return $default;
    $title = '';
    $imagesSource = $decoded;
    if (isset($decoded['title'])) $title = trim((string) $decoded['title']);
    if (isset($decoded['images']) && is_array($decoded['images'])) $imagesSource = $decoded['images'];
    $images = array();
    if (is_array($imagesSource)) {
        foreach ($imagesSource as $item) {
            if (!is_array($item)) continue;
            $images[] = array(
                'id' => isset($item['id']) ? trim((string) $item['id']) : uniqid('tracing_'),
                'image' => isset($item['image']) ? trim((string) $item['image']) : '',
            );
        }
    }
    return array('title' => normalize_tracing_title($title), 'images' => $images);
}
function activities_columns(PDO $pdo): array {
    static $cache = null;
    if (is_array($cache)) return $cache;
    $cache = array();
    $stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'activities'");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($row['column_name'])) $cache[] = (string) $row['column_name'];
    }
    return $cache;
}
function load_tracing_activity(PDO $pdo, string $unit, string $activityId): array {
    $columns = activities_columns($pdo);
    $selectFields = array('id');
    if (in_array('data', $columns, true)) $selectFields[] = 'data';
    if (in_array('content_json', $columns, true)) $selectFields[] = 'content_json';
    if (in_array('title', $columns, true)) $selectFields[] = 'title';
    if (in_array('name', $columns, true)) $selectFields[] = 'name';
    $fallback = array('id' => '', 'title' => default_tracing_title(), 'images' => array());
    $row = null;
    if ($activityId !== '') {
        $stmt = $pdo->prepare("SELECT " . implode(', ', $selectFields) . " FROM activities WHERE id = :id AND type = 'tracing' LIMIT 1");
        $stmt->execute(array('id' => $activityId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row && in_array('unit_id', $columns, true)) {
        $stmt = $pdo->prepare("SELECT " . implode(', ', $selectFields) . " FROM activities WHERE unit_id = :unit AND type = 'tracing' ORDER BY id ASC LIMIT 1");
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row && in_array('unit', $columns, true)) {
        $stmt = $pdo->prepare("SELECT " . implode(', ', $selectFields) . " FROM activities WHERE unit = :unit AND type = 'tracing' ORDER BY id ASC LIMIT 1");
        $stmt->execute(array('unit' => $unit));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$row) return $fallback;
    $rawData = null;
    if (isset($row['data'])) $rawData = $row['data'];
    elseif (isset($row['content_json'])) $rawData = $row['content_json'];
    $payload = normalize_tracing_payload($rawData);
    $columnTitle = '';
    if (isset($row['title']) && trim((string) $row['title']) !== '') $columnTitle = trim((string) $row['title']);
    elseif (isset($row['name']) && trim((string) $row['name']) !== '') $columnTitle = trim((string) $row['name']);
    if ($columnTitle !== '') $payload['title'] = $columnTitle;
    return array('id' => isset($row['id']) ? (string) $row['id'] : '', 'title' => normalize_tracing_title((string) $payload['title']), 'images' => isset($payload['images']) && is_array($payload['images']) ? $payload['images'] : array());
}

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
