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
/* Match Drag & Drop Kids header sizing */
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

/* Dot to Dot viewer shell */
.d2dv-wrap {
    width: 100%;
}

.d2dv-stage-card {
    max-width: 900px !important;
    margin: 0 auto !important;
    display: flex !important;
    flex-direction: column !important;
    align-items: center !important;
    height: auto !important;
    min-height: 0 !important;
}

/* Keep progress compact, like Drag & Drop Kids word bank area */
.d2dv-progress-row {
    display: flex !important;
    flex-wrap: wrap !important;
    justify-content: center !important;
    gap: 8px !important;
    margin: 8px 0 !important;
    min-height: 40px !important;
}

.d2dv-chip {
    padding: 8px 16px !important;
    border-radius: 999px !important;
    font-weight: 800 !important;
    font-family: 'Fredoka', 'Trebuchet MS', sans-serif !important;
    font-size: clamp(16px, 1.8vw, 19px) !important;
    line-height: 1 !important;
}

/* The critical part: stage sizes from the image, not from a forced tall box */
.d2dv-stage {
    position: relative !important;
    display: inline-block !important;
    width: auto !important;
    height: auto !important;
    min-height: 0 !important;
    max-width: 100% !important;
    line-height: 0 !important;
}

/* Image determines the real activity size */
.d2dv-final-image {
    display: block !important;
    width: auto !important;
    height: auto !important;
    max-width: 100% !important;
    max-height: calc(100vh - 230px) !important;
    border-radius: 16px !important;
    user-select: none !important;
    pointer-events: none !important;
    object-fit: contain !important;
    box-shadow: 0 10px 28px rgba(15,23,42,.13) !important;
}

/* Canvas overlays the image exactly */
#d2dvCanvas {
    position: absolute !important;
    inset: 0 !important;
    width: 100% !important;
    height: 100% !important;
    border-radius: 16px !important;
    touch-action: none !important;
}

/* Status and buttons */
.d2dv-status {
    text-align: center !important;
    font-size: 16px !important;
    font-weight: 800 !important;
    min-height: 24px !important;
    margin: 4px 0 !important;
    line-height: 1.3 !important;
}

.d2dv-toolbar {
    display: flex !important;
    flex-wrap: wrap !important;
    justify-content: center !important;
    gap: 8px !important;
    margin: 6px 0 4px !important;
}

.d2dv-btn {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 9px 18px !important;
    border: none !important;
    border-radius: 999px !important;
    cursor: pointer !important;
    min-width: 130px !important;
    font-weight: 800 !important;
    font-family: 'Nunito', 'Segoe UI', sans-serif !important;
    font-size: 13px !important;
    line-height: 1 !important;
    box-shadow: 0 8px 18px rgba(15,23,42,.12) !important;
    transition: transform .15s, filter .15s !important;
}

.d2dv-btn:hover {
    filter: brightness(1.05);
    transform: translateY(-1px);
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

/* Mobile */
@media (max-width: 640px) {
    .d2dv-final-image {
        max-height: calc(100vh - 210px) !important;
    }

    .d2dv-chip {
        padding: 7px 12px !important;
        font-size: 15px !important;
    }

    .d2dv-progress-row,
    .d2dv-toolbar {
        gap: 6px !important;
    }

    .d2dv-toolbar {
        flex-direction: column !important;
        align-items: center !important;
    }

    .d2dv-btn {
        width: 100% !important;
        max-width: 280px !important;
    }
}

/* Fullscreen: same proportions as Drag & Drop Kids */
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

body.presentation-mode .d2dv-stage-card,
body.fullscreen-embedded .d2dv-stage-card {
    max-width: 900px !important;
    margin: 0 auto !important;
    height: auto !important;
    min-height: 0 !important;
    align-items: center !important;
}

body.presentation-mode .d2dv-stage,
body.fullscreen-embedded .d2dv-stage {
    width: auto !important;
    height: auto !important;
    min-height: 0 !important;
    max-width: 100% !important;
}

body.presentation-mode .d2dv-final-image,
body.fullscreen-embedded .d2dv-final-image {
    width: auto !important;
    height: auto !important;
    max-width: 100% !important;
    max-height: calc(100vh - 20mm - 190px) !important;
    object-fit: contain !important;
}

body.presentation-mode #d2dvCanvas,
body.fullscreen-embedded #d2dvCanvas {
    position: absolute !important;
    inset: 0 !important;
    width: 100% !important;
    height: 100% !important;
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

body.presentation-mode .d2dv-progress-row,
body.fullscreen-embedded .d2dv-progress-row {
    margin: 6px 0 !important;
    min-height: 34px !important;
}

body.presentation-mode .d2dv-toolbar,
body.fullscreen-embedded .d2dv-toolbar {
    margin: 6px 0 4px !important;
}
</style>

<div class="d2dv-wrap">
    <?= render_activity_header(
        $viewerTitle,
        $instruction !== '' ? $instruction : 'Connect the dots in order to reveal the picture.'
    ) ?>

    <?php if (!$hasActivity): ?>

        <div class="d2dv-empty">
            This activity has no image or not enough points. Minimum 3 points are required.
        </div>

    <?php else: ?>

        <div class="d2dv-stage-card">

            <div class="d2dv-progress-row">
                <span class="d2dv-chip" id="d2dvProgress">Connect 1 to 2</span>
                <span class="d2dv-chip d2dv-chip-accent" id="d2dvCounter">
                    0 / <?= count($points) - 1 ?> lines
                </span>
            </div>

            <div class="d2dv-stage" id="d2dvStage">
                <img class="d2dv-final-image"
                     id="d2dvFinalImage"
                     src="<?= htmlspecialchars($image, ENT_QUOTES, 'UTF-8') ?>"
                     alt="<?= htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8') ?>">
                <canvas id="d2dvCanvas"></canvas>
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
