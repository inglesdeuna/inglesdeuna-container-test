<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/dot_to_dot_functions.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
$returnTo = isset($_GET['return_to']) ? trim((string) $_GET['return_to']) : '';

if ($unit === '' && $activityId !== '') {
    $unit = dot_to_dot_resolve_unit_from_activity($pdo, $activityId);
}

$activity = load_dot_to_dot_activity($pdo, $unit, $activityId);
$title = isset($activity['title']) ? (string) $activity['title'] : default_dot_to_dot_title();
$image = isset($activity['image']) ? (string) $activity['image'] : '';
$instruction = isset($activity['instruction'])
    ? (string) $activity['instruction']
    : 'Connect the dots in order to reveal the picture.';
$points = isset($activity['points']) && is_array($activity['points']) ? array_values($activity['points']) : array();

$cssVersion = (string) (@filemtime(__DIR__ . '/dot_to_dot.css') ?: time());
$jsVersion = (string) (@filemtime(__DIR__ . '/dot_to_dot.js') ?: time());

ob_start();
?>
<link rel="stylesheet" href="dot_to_dot.css?v=<?= htmlspecialchars($cssVersion, ENT_QUOTES, 'UTF-8') ?>">

<?php if ($image === '' || count($points) < 3) { ?>
    <div class="d2dv-empty">This activity is not ready yet. Open the editor to upload an image and add at least 3 points.</div>
<?php } else { ?>
    <section class="d2dv-wrap">
        <header class="d2dv-hero">
            <h2><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h2>
            <p><?= htmlspecialchars($instruction, ENT_QUOTES, 'UTF-8') ?></p>
        </header>

        <div class="d2dv-stage-card">
            <div class="d2dv-progress-row">
                <span class="d2dv-chip" id="d2dvProgress">Connect 1 to 2</span>
                <span class="d2dv-chip d2dv-chip-accent" id="d2dvCounter">0 / <?= max(count($points) - 1, 0) ?> lines</span>
            </div>

            <div class="d2dv-stage" id="d2dvStage">
                <img src="<?= htmlspecialchars($image, ENT_QUOTES, 'UTF-8') ?>" class="d2dv-final-image" id="d2dvFinalImage" alt="final drawing">
                <canvas id="d2dvCanvas" aria-label="Dot to dot canvas"></canvas>
            </div>

            <div class="d2dv-toolbar">
                <button type="button" class="d2dv-btn d2dv-btn-soft" id="d2dvResetBtn">Reset</button>
                <button type="button" class="d2dv-btn d2dv-btn-accent" id="d2dvHintBtn">Hint</button>
                <button type="button" class="d2dv-btn d2dv-btn-next" id="d2dvContinueBtn" style="display:none;">Continue</button>
            </div>

            <p class="d2dv-status" id="d2dvStatus">Draw from point 1 to point 2.</p>
        </div>
    </section>

    <script>
    window.DOT_TO_DOT_DATA = {
        activityId: <?= json_encode((string) ($activity['id'] ?? ''), JSON_UNESCAPED_UNICODE) ?>,
        image: <?= json_encode($image, JSON_UNESCAPED_UNICODE) ?>,
        title: <?= json_encode($title, JSON_UNESCAPED_UNICODE) ?>,
        points: <?= json_encode($points, JSON_UNESCAPED_UNICODE) ?>,
        returnTo: <?= json_encode($returnTo, JSON_UNESCAPED_UNICODE) ?>
    };
    </script>
    <script src="dot_to_dot.js?v=<?= htmlspecialchars($jsVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
<?php } ?>
<?php
$content = ob_get_clean();
render_activity_viewer($title, '🔢', $content);
