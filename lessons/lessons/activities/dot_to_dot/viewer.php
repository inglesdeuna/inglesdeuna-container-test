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
$instruction = trim((string)($activity['instruction'] ?? ''));

if ($activityId === '' && !empty($activity['id'])) {
    $activityId = (string) $activity['id'];
}

$hasActivity = $image !== '' && is_array($points) && count($points) >= 3;

ob_start();
?>

<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="dot_to_dot.css">

<style>
.passive-done {
    display: none;
    width: min(680px, 100%);
    margin: 24px auto 0;
    text-align: center;
    padding: clamp(28px, 5vw, 54px);
    border-radius: 34px;
    background: #fff;
    border: 1px solid #E2F7EF;
    box-shadow: 0 8px 40px rgba(8,80,65,.12);
}
.passive-done.active { display: block; animation: passivePop .45s cubic-bezier(.2,.9,.2,1); }
@keyframes passivePop { from { opacity:0; transform:scale(.92); } to { opacity:1; transform:scale(1); } }
.passive-done-icon { font-size: clamp(66px,12vw,100px); margin-bottom: 12px; }
.passive-done-title { margin: 0 0 10px; font-family: 'Fredoka', sans-serif; font-size: clamp(34px,6vw,60px); color: #085041; line-height: 1; }
.passive-done-text { margin: 0 auto 22px; max-width: 520px; color: #7C739B; font-size: clamp(14px,2vw,17px); font-weight: 800; line-height: 1.5; }
.passive-done-track { height: 14px; max-width: 420px; margin: 0 auto 18px; border-radius: 999px; background: #E2F7EF; overflow: hidden; }
.passive-done-fill { height: 100%; width: 0%; border-radius: 999px; background: linear-gradient(90deg, #1D9E75, #7F77DD, #EC4899); transition: width .8s cubic-bezier(.2,.9,.2,1); }
.passive-done-btn { display: inline-flex; align-items: center; gap: 8px; padding: 13px 28px; border-radius: 999px; border: 0; background: #1D9E75; color: #fff; font-family: 'Nunito', sans-serif; font-size: 15px; font-weight: 900; cursor: pointer; box-shadow: 0 6px 18px rgba(29,158,117,.30); transition: .18s; }
.passive-done-btn:hover { transform: translateY(-2px); }
</style>

<div class="d2dv-page">
<div class="d2dv-app">
    <div class="d2dv-hero">
        <div class="d2dv-kicker">Activity</div>
        <h1><?= htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8') ?></h1>
        <p><?= htmlspecialchars($instruction !== '' ? $instruction : 'Connect the dots in order to reveal the picture.', ENT_QUOTES, 'UTF-8') ?></p>
    </div>

    <div class="d2dv-stage-shell">
<div class="d2dv-wrap">

    <?php if (!$hasActivity): ?>

        <div class="d2dv-empty">
            This activity has no image or not enough points. Minimum 3 points are required.
        </div>

    <?php else: ?>

        <div class="d2dv-main">

            <div class="d2dv-progress-row">
                <span class="d2dv-chip" id="d2dvProgress">
                    Connect 1 to 2
                </span>

                <span class="d2dv-chip d2dv-chip-accent" id="d2dvCounter">
                    0 / <?= max(0, count($points) - 1) ?> lines
                </span>
            </div>

            <div class="d2dv-canvas-wrap">
                <div class="d2dv-stage" id="d2dvStage">
                    <img
                        class="d2dv-img"
                        id="d2dvImg"
                        src="<?= htmlspecialchars($image, ENT_QUOTES, 'UTF-8') ?>"
                        alt="<?= htmlspecialchars($viewerTitle, ENT_QUOTES, 'UTF-8') ?>"
                    >

                    <canvas id="d2dvCanvas"></canvas>
                </div>
            </div>

            <p class="d2dv-status" id="d2dvStatus"></p>

            <div class="d2dv-toolbar">
                <button class="d2dv-btn d2dv-btn-soft" id="d2dvResetBtn" type="button">
                    Reset
                </button>

                <button class="d2dv-btn d2dv-btn-accent" id="d2dvHintBtn" type="button">
                    Hint
                </button>

                <button class="d2dv-btn d2dv-btn-accent" id="d2dvRevealBtn" type="button" style="display:none">
                    Reveal Image
                </button>

                <button class="d2dv-btn d2dv-btn-next" id="d2dvContinueBtn" type="button" style="display:none">
                    Next
                </button>
            </div>

            <div id="d2dvCompletionPanel" style="display:none"></div>

        </div>

    <?php endif; ?>
</div>
    </div>
</div>
</div>

<?php if ($hasActivity): ?>
<script>
window.DOT_TO_DOT_DATA = {
    points: <?= json_encode(array_values($points), JSON_UNESCAPED_UNICODE) ?>,
    image: <?= json_encode($image, JSON_UNESCAPED_UNICODE) ?>,
    labelSettings: <?= json_encode($activity['label_settings'] ?? [], JSON_UNESCAPED_UNICODE) ?>,
    returnTo: <?= json_encode($returnTo, JSON_UNESCAPED_UNICODE) ?>,
    activityId: <?= json_encode($activityId, JSON_UNESCAPED_UNICODE) ?>
};
</script>

<script src="dot_to_dot.js"></script>
<?php endif; ?>

<?php
$content = ob_get_clean();
render_activity_viewer($viewerTitle, '', $content);
