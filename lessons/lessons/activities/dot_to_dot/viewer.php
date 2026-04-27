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
$viewerTitle = ($activity['title'] ?? '') !== '' ? (string) $activity['title'] : dot_to_dot_default_title();

if ($activityId === '' && !empty($activity['id'])) {
    $activityId = (string) $activity['id'];
}

$hasActivity = $image !== '' && count($points) >= 3;

ob_start();
?>
<link rel="stylesheet" href="dot_to_dot.css">

<div class="d2dv-wrap">
    <div class="d2dv-hero">
        <h2><?= htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8') ?></h2>
        <?php if (!empty($activity['instruction'])): ?>
            <p><?= htmlspecialchars($activity['instruction'], ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
    </div>

    <?php if (!$hasActivity): ?>
        <div class="d2dv-empty">
            This activity has no image or not enough points (minimum 3 required).
        </div>
    <?php else: ?>
        <div class="d2dv-stage-card">
            <div class="d2dv-progress-row">
                <span class="d2dv-chip"        id="d2dvProgress">Connect 1 to 2</span>
                <span class="d2dv-chip d2dv-chip-accent" id="d2dvCounter">0 / <?= count($points) - 1 ?> lines</span>
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
                <button class="d2dv-btn d2dv-btn-soft"   id="d2dvResetBtn">Reset</button>
                <button class="d2dv-btn d2dv-btn-accent"  id="d2dvHintBtn">Hint</button>
                <button class="d2dv-btn d2dv-btn-accent"  id="d2dvRevealBtn" style="display:none">Reveal Image</button>
                <button class="d2dv-btn d2dv-btn-next"    id="d2dvContinueBtn" style="display:none">Continue</button>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php if ($hasActivity): ?>
<script>
window.DOT_TO_DOT_DATA = {
    points:        <?= json_encode(array_values($points), JSON_UNESCAPED_UNICODE) ?>,
    labelSettings: <?= json_encode($activity['label_settings'] ?? [], JSON_UNESCAPED_UNICODE) ?>,
    returnTo:      <?= json_encode($returnTo, ENT_QUOTES) ?>,
    activityId:    <?= json_encode($activityId, ENT_QUOTES) ?>
};
</script>
<script src="dot_to_dot.js"></script>
<?php endif; ?>

<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '', $content);
