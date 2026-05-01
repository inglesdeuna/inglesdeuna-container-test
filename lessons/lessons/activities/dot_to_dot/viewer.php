<?php
require_once __DIR__ . '/dot_to_dot_functions.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$unit       = isset($_GET['unit'])      ? trim((string) $_GET['unit'])      : '';
$activityId = isset($_GET['id'])        ? trim((string) $_GET['id'])        : '';
$returnTo   = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';

if ($activityId === '' && $unit === '') {
    die('Activity not specified');
}

$activity    = load_dot_to_dot_activity($pdo, $unit, $activityId);
$points      = $activity['points'] ?? [];
$image       = $activity['image']  ?? '';
$viewerTitle = ($activity['title'] ?? '') !== ''
    ? (string) $activity['title']
    : dot_to_dot_default_title();

$instruction = trim((string)($activity['instruction'] ?? ''));

if ($activityId === '' && !empty($activity['id'])) {
    $activityId = (string) $activity['id'];
}

$hasActivity = $image !== '' && count($points) >= 3;

ob_start();
?>

<link rel="stylesheet" href="dot_to_dot.css">

<style>
/* Shared viewer sizing copied from Drag & Drop Kids */
.act-header {
    max-width: 900px !important;
    margin-left: auto !important;
    margin-right: auto !important;
    margin-bottom: 10px !important;
    padding: 12px 18px !important;
    border-radius: 16px !important;
}

.act-header h2 {
    font-size: clamp(18px, 2.6vw, 26px) !important;
    margin: 0 0 4px !important;
}

.act-header p {
    font-size: 13px !important;
}

/* Reusable activity viewer layout */
.activity-stage {
    max-width: 900px;
    margin: 0 auto;
    display: flex;
    flex-direction: column;
}

.activity-canvas-wrap {
    display: flex;
    justify-content: center;
    align-items: flex-start;
    margin-bottom: 10px;
    line-height: 0;
}

.activity-canvas {
    position: relative;
    display: inline-block;
    max-width: 100%;
}

.activity-main-image,
.activity-main-canvas {
    display: block;
    max-width: 100%;
    max-height: calc(100vh - 230px);
    width: auto;
    height: auto;
    border-radius: 16px;
    box-shadow: 0 10px 28px rgba(15,23,42,.13);
}

/* Dot to Dot layout using shared sizing */
.d2dv-wrap {
    width: 100%;
}

.d2dv-stage-card {
    max-width: 900px;
    margin: 0 auto;
}

.d2dv-stage {
    position: relative;
    display: inline-block;
    max-width: 100%;
}

.d2dv-final-image {
    display: block;
    max-width: 100%;
    max-height: calc(100vh - 230px);
    width: auto;
    height: auto;
    border-radius: 16px;
    user-select: none;
    pointer-events: none;
    box-shadow: 0 10px 28px rgba(15,23,42,.13);
}

#d2dvCanvas {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    border-radius: 16px;
    touch-action: none;
}

.d2dv-progress-row {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 8px;
    margin: 8px 0;
    min-height: 40px;
}

.d2dv-chip {
    padding: 8px 16px;
    border-radius: 999px;
    color: #4c1d95;
    font-weight: 800;
    font-family: 'Fredoka', 'Trebuchet MS', sans-serif;
    font-size: clamp(16px, 1.8vw, 19px);
    background: #ede9fe;
    border: 2px solid #7c3aed;
    box-shadow: 0 4px 12px rgba(124,58,237,.18);
    line-height: 1;
}

.d2dv-chip-accent {
    background: #dbeafe;
    border-color: #2563eb;
    color: #1e3a8a;
}

.d2dv-toolbar {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 8px;
    margin: 8px 0 4px;
}

.d2dv-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 9px 18px;
    border: none;
    border-radius: 999px;
    color: #fff;
    cursor: pointer;
    min-width: 130px;
    font-weight: 800;
    font-family: 'Nunito', 'Segoe UI', sans-serif;
    font-size: 13px;
    box-shadow: 0 8px 18px rgba(15,23,42,.12);
    transition: transform .15s, filter .15s;
    line-height: 1;
}

.d2dv-btn:hover {
    filter: brightness(1.05);
    transform: translateY(-1px);
}

.d2dv-btn-soft {
    background: linear-gradient(180deg, #94a3b8 0%, #64748b 100%);
}

.d2dv-btn-accent {
    background: linear-gradient(180deg, #d8b4fe 0%, #a855f7 100%);
}

.d2dv-btn-next {
    background: linear-gradient(180deg, #60a5fa 0%, #2563eb 100%);
}

.d2dv-status {
    text-align: center;
    font-size: 16px;
    font-weight: 800;
    min-height: 24px;
    margin: 4px 0;
}

.d2dv-empty {
    max-width: 900px;
    margin: 0 auto;
    padding: 24px;
    text-align: center;
    border-radius: 16px;
    background: #fff7ed;
    color: #9a3412;
    font-weight: 800;
}

.d2dv-completion-panel {
    text-align: center;
    padding: 20px;
}

.d2dv-completion-icon {
    font-size: 48px;
    margin-bottom: 8px;
}

.d2dv-completion-title {
    font-family: 'Fredoka', 'Trebuchet MS', sans-serif;
    font-size: 30px;
    font-weight: 700;
    color: #9a3412;
    margin: 0 0 8px;
}

.d2dv-completion-score {
    font-size: 16px;
    font-weight: 800;
    color: #9a3412;
    margin: 0;
}

@media (max-width: 640px) {
    .d2dv-final-image {
        max-height: calc(100vh - 210px);
    }

    .d2dv-chip {
        padding: 7px 12px;
        font-size: 15px;
    }

    .d2dv-progress-row,
    .d2dv-toolbar {
        gap: 6px;
    }

    .d2dv-toolbar {
        flex-direction: column;
        align-items: center;
    }

    .d2dv-btn {
        width: 100%;
        max-width: 280px;
    }
}

/* Fullscreen / presentation mode */
body.presentation-mode .activity-wrapper,
body.fullscreen-embedded .activity-wrapper {
    padding: 10mm !important;
    box-sizing: border-box !important;
}

body.presentation-mode .viewer-content,
body.fullscreen-embedded .viewer-content {
    border-radius: 14px !important;
    overflow: hidden !important;
}

body.presentation-mode .d2dv-final-image,
body.fullscreen-embedded .d2dv-final-image {
    max-height: calc(100vh - 20mm - 190px);
}

body.presentation-mode .act-header,
body.fullscreen-embedded .act-header {
    padding: 8px 14px !important;
    margin-bottom: 6px !important;
}

body.presentation-mode .act-header h2,
body.fullscreen-embedded .act-header h2 {
    font-size: clamp(16px, 2vw, 22px) !important;
}
</style>

<div class="d2dv-wrap">
    <?= render_activity_header(
        $viewerTitle,
        $instruction !== '' ? $instruction : 'Connect the dots in order.'
    ) ?>

    <?php if (!$hasActivity): ?>

        <div class="d2dv-empty">
            This activity has no image or not enough points. Minimum 3 points are required.
        </div>

    <?php else: ?>

        <div class="activity-stage d2dv-stage-card">

            <div class="d2dv-progress-row">
                <span class="d2dv-chip" id="d2dvProgress">Connect 1 to 2</span>
                <span class="d2dv-chip d2dv-chip-accent" id="d2dvCounter">
                    0 / <?= count($points) - 1 ?> lines
                </span>
            </div>

            <div class="activity-canvas-wrap">
                <div class="activity-canvas d2dv-stage" id="d2dvStage">
                    <img class="activity-main-image d2dv-final-image"
                         id="d2dvFinalImage"
                         src="<?= htmlspecialchars($image, ENT_QUOTES, 'UTF-8') ?>"
                         alt="<?= htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8') ?>">
                    <canvas class="activity-main-canvas" id="d2dvCanvas"></canvas>
                </div>
            </div>

            <p class="d2dv-status" id="d2dvStatus"></p>

            <div class="d2dv-toolbar">
                <button class="d2dv-btn d2dv-btn-soft" id="d2dvResetBtn" type="button">Reset</button>
                <button class="d2dv-btn d2dv-btn-accent" id="d2dvHintBtn" type="button">Hint</button>
                <button class="d2dv-btn d2dv-btn-accent" id="d2dvRevealBtn" type="button" style="display:none">Reveal Image</button>
                <button class="d2dv-btn d2dv-btn-next" id="d2dvContinueBtn" type="button" style="display:none">Next</button>
            </div>

            <div id="d2dvCompletionPanel" class="d2dv-completion-panel" style="display:none">
                <div class="d2dv-completion-icon">✅</div>
                <p class="d2dv-completion-title">Completed!</p>
                <p class="d2dv-completion-score" id="d2dvCompletionScore"></p>
            </div>

        </div>

    <?php endif; ?>
</div>

<?php if ($hasActivity): ?>
<script>
window.DOT_TO_DOT_DATA = {
    points:        <?= json_encode(array_values($points), JSON_UNESCAPED_UNICODE) ?>,
    labelSettings: <?= json_encode($activity['label_settings'] ?? [], JSON_UNESCAPED_UNICODE) ?>,
    returnTo:      <?= json_encode($returnTo, JSON_UNESCAPED_UNICODE) ?>,
    activityId:    <?= json_encode($activityId, JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script src="dot_to_dot.js"></script>
<?php endif; ?>

<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '', $content);
