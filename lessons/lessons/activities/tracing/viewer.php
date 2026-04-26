<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/tracing_functions.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

// Sanitize inputs
$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit       = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
$returnTo   = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';

// Validate required params
if ($activityId === '' && $unit === '') {
    die('Activity not specified');
}

// Load activity
$activity = load_tracing_activity($pdo, $unit, $activityId);

// Safe defaults
$images = (!empty($activity['images']) && is_array($activity['images']))
    ? array_values($activity['images'])
    : [];

$viewerTitle = !empty($activity['title'])
    ? (string) $activity['title']
    : default_tracing_title();

// Ensure activity ID fallback
if ($activityId === '' && !empty($activity['id'])) {
    $activityId = (string) $activity['id'];
}

// Validate images
if (empty($images)) {
    die('No tracing images found for this activity');
}

ob_start();
?>

<style>
/* (CSS unchanged for brevity — keep your original styles) */
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
                <?php
                $colors = ['#2563eb','#ef4444','#f59e0b','#22c55e','#a855f7','#ec4899'];
                foreach ($colors as $i => $color):
                ?>
                    <button
                        type="button"
                        class="tracing-color-swatch <?= $i === 0 ? 'active' : '' ?>"
                        data-color="<?= htmlspecialchars($color) ?>"
                        style="background:<?= htmlspecialchars($color) ?>;">
                    </button>
                <?php endforeach; ?>
            </div>

            <span class="tracing-toolbar-label">Pencil Size</span>

            <div class="tracing-size-group">
                <?php
                $sizes = [4, 8, 14];
                foreach ($sizes as $size):
                ?>
                    <button
                        type="button"
                        class="tracing-size-btn <?= $size === 8 ? 'active' : '' ?>"
                        data-size="<?= $size ?>">
                        <span class="dot" style="width:<?= $size ?>px;height:<?= $size ?>px;"></span>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="tracing-actions" id="tracingActions">
            <button type="button" class="tracing-btn tracing-btn-next" id="nextBtn">Next</button>
        </div>

        <div class="tracing-completed" id="tracingCompleted">
            <div style="font-size:86px;">✍️</div>
            <h2 id="tracingCompletedTitle"></h2>
            <p id="tracingCompletedText"></p>
            <p id="tracingScoreText"></p>

            <button type="button" class="tracing-btn tracing-btn-next" id="restartBtn">Restart</button>
            <a href="#" id="tracingReturnBtn" style="display:none;" class="tracing-btn tracing-btn-next">Return</a>
        </div>

    </div>
</div>

<script>
(function () {
    'use strict';

    const images = <?= json_encode($images, JSON_UNESCAPED_UNICODE); ?>;
    let currentIdx = 0;
    let penSize = 8;
    let penColor = '#2563eb';
    let drawing = false;

    const canvas = document.getElementById('traceCanvas');
    const ctx = canvas.getContext('2d');

    const counterText = document.getElementById('counterText');
    const nextBtn = document.getElementById('nextBtn');
    const restartBtn = document.getElementById('restartBtn');

    const counterEl = document.getElementById('tracingCounter');
    const wrapEl = document.getElementById('tracingCanvasWrap');
    const toolbarEl = document.getElementById('tracingToolbar');
    const actionsEl = document.getElementById('tracingActions');
    const completedEl = document.getElementById('tracingCompleted');

    const completedTitleEl = document.getElementById('tracingCompletedTitle');
    const completedTextEl = document.getElementById('tracingCompletedText');
    const scoreTextEl = document.getElementById('tracingScoreText');
    const returnBtn = document.getElementById('tracingReturnBtn');

    const activityTitle = <?= json_encode($viewerTitle); ?>;
    const ACTIVITY_ID = <?= json_encode($activityId); ?>;
    const RETURN_TO = <?= json_encode($returnTo); ?>;

    function getScaledPos(e, isTouch) {
        const rect = canvas.getBoundingClientRect();
        const clientX = isTouch ? e.touches[0].clientX : e.clientX;
        const clientY = isTouch ? e.touches[0].clientY : e.clientY;

        return {
            x: (clientX - rect.left) * (canvas.width / rect.width),
            y: (clientY - rect.top) * (canvas.height / rect.height)
        };
    }

    function drawGuide(url) {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        if (!url) return;

        const img = new Image();
        img.crossOrigin = 'anonymous';

        img.onload = () => {
            const scale = Math.min(canvas.width / img.width, canvas.height / img.height);
            const x = (canvas.width - img.width * scale) / 2;
            const y = (canvas.height - img.height * scale) / 2;
            ctx.drawImage(img, x, y, img.width * scale, img.height * scale);
        };

        img.src = url;
    }

    function renderPage() {
        if (!images.length) return;

        drawGuide(images[currentIdx].image);
        counterText.textContent = `${currentIdx + 1} / ${images.length}`;
        nextBtn.textContent = currentIdx < images.length - 1 ? 'Next' : 'Finish';
    }

    function showCompleted() {
        counterEl.style.display = 'none';
        wrapEl.style.display = 'none';
        toolbarEl.style.display = 'none';
        actionsEl.style.display = 'none';

        completedEl.classList.add('active');

        completedTitleEl.textContent = activityTitle || 'Tracing Practice';
        completedTextEl.textContent = `You've completed ${activityTitle || 'this activity'}.`;

        if (RETURN_TO && ACTIVITY_ID) {
            returnBtn.style.display = '';
            const joiner = RETURN_TO.includes('?') ? '&' : '?';

            returnBtn.href = `${RETURN_TO}${joiner}activity_percent=100&activity_total=${images.length}&activity_id=${encodeURIComponent(ACTIVITY_ID)}&activity_type=tracing`;
        }
    }

    function stroke(x, y) {
        ctx.lineWidth = penSize;
        ctx.strokeStyle = penColor;
        ctx.lineCap = 'round';

        ctx.lineTo(x, y);
        ctx.stroke();

        ctx.beginPath();
        ctx.moveTo(x, y);
    }

    canvas.addEventListener('mousedown', e => {
        drawing = true;
        const pos = getScaledPos(e, false);
        ctx.beginPath();
        ctx.moveTo(pos.x, pos.y);
    });

    canvas.addEventListener('mouseup', () => drawing = false);

    canvas.addEventListener('mousemove', e => {
        if (!drawing) return;
        const pos = getScaledPos(e, false);
        stroke(pos.x, pos.y);
    });

    document.querySelectorAll('.tracing-size-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.tracing-size-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            penSize = parseInt(btn.dataset.size) || 8;
        });
    });

    document.querySelectorAll('.tracing-color-swatch').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.tracing-color-swatch').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            penColor = btn.dataset.color || '#2563eb';
        });
    });

    nextBtn.addEventListener('click', () => {
        if (currentIdx < images.length - 1) {
            currentIdx++;
            renderPage();
        } else {
            showCompleted();
        }
    });

    restartBtn.addEventListener('click', () => {
        currentIdx = 0;
        completedEl.classList.remove('active');
        renderPage();
    });

    renderPage();
})();
</script>

<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '✏️', $content);
