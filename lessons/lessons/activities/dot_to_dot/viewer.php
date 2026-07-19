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
/* ── Unified unscored completed screen ── */
.af-unscored__card{background:#fff;border:1.5px solid #EDE9FA;border-radius:14px;padding:28px 32px;width:100%;max-width:100%;box-sizing:border-box;font-family:'Nunito','Segoe UI',sans-serif;}
.af-unscored__prog-label{font-size:11px;color:#9B8FCC;font-weight:700;letter-spacing:.06em;text-align:center;margin-bottom:6px;text-transform:uppercase;}
.af-unscored__prog-track{background:#EDE9FA;border-radius:99px;height:9px;overflow:hidden;margin-bottom:4px;}
.af-unscored__prog-fill{height:100%;border-radius:99px;background:linear-gradient(90deg,#F97316,#7F77DD);transition:width .4s ease;}
.af-unscored__prog-nums{display:flex;justify-content:space-between;font-size:11px;color:#9B8FCC;margin-bottom:16px;}
.af-unscored__prog-nums strong{color:#7F77DD;}
.af-unscored__icon{width:48px;height:48px;border-radius:50%;background:#EDE9FA;display:flex;align-items:center;justify-content:center;margin:0 auto 10px;}
.af-unscored__title{font-family:'Fredoka','Trebuchet MS',sans-serif;font-size:20px;font-weight:600;color:#7F77DD;text-align:center;margin:0 0 3px;}
.af-unscored__sub{font-size:13px;color:#9B8FCC;font-weight:600;text-align:center;margin:0 0 16px;}
.af-unscored__chips{display:grid;gap:8px;margin-bottom:16px;}
.af-unscored__chips--2{grid-template-columns:1fr 1fr;}
.af-unscored__chip{background:#F9F8FF;border:1.5px solid #EDE9FA;border-radius:12px;padding:10px 6px;text-align:center;}
.af-unscored__chip-val{font-family:'Fredoka','Trebuchet MS',sans-serif;font-size:24px;color:#7F77DD;line-height:1;}
.af-unscored__chip-lbl{font-size:10px;color:#9B8FCC;font-weight:700;letter-spacing:.05em;margin-top:2px;text-transform:uppercase;}
.af-unscored__banner{border-radius:12px;padding:9px 14px;display:flex;align-items:center;gap:10px;margin-bottom:16px;}
.af-unscored__banner--orange{background:#FFF0E6;}
.af-unscored__banner-icon{width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.af-unscored__banner-icon--orange{background:#F97316;}
.af-unscored__banner-text{font-size:12px;font-weight:600;}
.af-unscored__banner-text--orange{color:#b85a10;}
.af-unscored__banner-title{font-family:'Fredoka','Trebuchet MS',sans-serif;font-size:15px;display:block;}
.af-unscored__btns{display:flex;gap:8px;}
.af-unscored__btn-primary{flex:1;background:#F97316;color:#fff;border:none;border-radius:10px;padding:11px 0;font-family:'Nunito','Segoe UI',sans-serif;font-size:14px;font-weight:700;cursor:pointer;}
.af-unscored__btn-secondary{flex:1;background:#fff;color:#7F77DD;border:1.5px solid #EDE9FA;border-radius:10px;padding:11px 0;font-family:'Nunito','Segoe UI',sans-serif;font-size:14px;font-weight:700;cursor:pointer;}
</style>

<div class="d2dv-page" data-az-zoom>
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

        <div class="d2dv-main" id="d2dvMain">

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

        </div>

        <div id="d2dvCompletionPanel" style="display:none"></div>

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
