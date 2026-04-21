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
$labelSettings = isset($activity['label_settings']) && is_array($activity['label_settings'])
    ? normalize_dot_to_dot_label_settings($activity['label_settings'], count($points))
    : default_dot_to_dot_label_settings();

$cssVersion = (string) (@filemtime(__DIR__ . '/dot_to_dot.css') ?: time());
$jsVersion = (string) (@filemtime(__DIR__ . '/dot_to_dot.js') ?: time());

ob_start();
?>
<link rel="stylesheet" href="dot_to_dot.css?v=<?= htmlspecialchars($cssVersion, ENT_QUOTES, 'UTF-8') ?>">
<style>
.dot2dot-app {
    max-width: 980px;
    margin: 0 auto;
    padding: clamp(8px, 1.4vw, 16px);
    font-family: 'Nunito','Segoe UI',sans-serif;
    color: #334155;
}
.dot2dot-intro {
    background: linear-gradient(135deg, #fff8df 0%, #eef8ff 52%, #f8fbff 100%);
    border: 1px solid #dbe7f5;
    border-radius: 26px;
    padding: 24px 26px;
    box-shadow: 0 16px 34px rgba(15, 23, 42, .09);
    margin-bottom: 14px;
}
.dot2dot-intro h2 {
    margin: 0 0 8px;
    font-family: 'Fredoka', 'Trebuchet MS', sans-serif;
    font-size: clamp(24px, 3.2vw, 32px);
    font-weight: 700;
    color: #0f172a;
    letter-spacing: .3px;
}
.dot2dot-intro p {
    margin: 0;
    font-size: 16px;
    color: #334155;
    line-height: 1.6;
}
.dot2dot-stage {
    background: linear-gradient(180deg, #fffdf7 0%, #ffffff 100%);
    border: 1px solid #dbe7f5;
    border-radius: 24px;
    box-shadow: 0 14px 28px rgba(15, 23, 42, .07);
    padding: 16px;
    margin-bottom: 18px;
}
.dot2dot-canvas-wrap { display: flex; justify-content: center; align-items: center; overflow: visible; border-radius: 16px; background: #fff; touch-action: manipulation; }
#dot2dotCanvas {
    max-width: 100%;
    max-height: calc(100vh - 360px);
    width: auto;
    height: auto;
    display: block;
    touch-action: manipulation;
    border-radius: 14px;
}
.dot2dot-controls {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    justify-content: center;
    align-items: center;
    padding: 14px 0 4px;
    margin-top: 12px;
}
.dot2dot-action-btn {
    display: inline-flex;
    justify-content: center;
    align-items: center;
    min-width: 146px;
    border: none;
    border-radius: 999px;
    padding: 12px 24px;
    font-size: 15px;
    font-weight: 800;
    font-family: inherit;
    cursor: pointer;
    background: linear-gradient(180deg, #60a5fa 0%, #2563eb 100%);
    color: #fff;
    box-shadow: 0 10px 24px rgba(0,0,0,.14);
    transition: transform .18s ease, filter .18s ease;
}
.dot2dot-action-btn:active { filter: brightness(0.95); }
.dot2dot-action-btn-primary { background: linear-gradient(180deg, #22c55e 0%, #15803d 100%); }
.dot2dot-progress { font-size: 15px; font-weight: 800; color: #0f172a; }
.dot2dot-completed { display: none; text-align: center; padding: 50px 20px 30px; flex-direction: column; align-items: center; }
.dot2dot-completed.active { display: flex; }
.dot2dot-completed-emoji { font-size: 88px; line-height: 1; margin-bottom: 14px; }
.dot2dot-completed-title { font-family: 'Fredoka','Trebuchet MS',sans-serif; font-size: clamp(32px,4vw,48px); font-weight: 700; color: #22c55e; margin: 0 0 8px; }
.dot2dot-completed-sub { font-size: 17px; font-weight: 700; color: #374151; margin: 0 0 26px; }
.dot2dot-completed-actions { display: flex; flex-wrap: wrap; justify-content: center; gap: 12px; }
.dot2dot-no-image { text-align: center; padding: 40px 20px; font-size: 16px; font-weight: 700; color: #0f172a; }
@media (max-width: 640px) {
    .dot2dot-action-btn { width: 100%; }
}
</style>

<div class="dot2dot-app">
    <section class="dot2dot-intro">
        <h2><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h2>
        <p><?= htmlspecialchars($instruction, ENT_QUOTES, 'UTF-8') ?></p>
    </section>

    <div class="dot2dot-stage" id="dot2dotStage">
        <div class="dot2dot-canvas-wrap">
            <canvas id="dot2dotCanvas"></canvas>
        </div>
        <div class="dot2dot-controls" id="dot2dotControls">
            <span class="dot2dot-progress" id="dot2dotProgress">0 / <?= max(count($points) - 1, 0) ?> lines</span>
            <button id="dot2dotPrevBtn" class="dot2dot-action-btn" type="button">&#x2190; Prev</button>
            <button id="dot2dotNextBtn" class="dot2dot-action-btn dot2dot-action-btn-primary" type="button">Next &#x2192;</button>
        </div>
    </div>

    <div class="dot2dot-completed" id="dot2dotCompleted">
        <div class="dot2dot-completed-emoji">&#x1F389;</div>
        <h2 class="dot2dot-completed-title">Completed!</h2>
        <p class="dot2dot-completed-sub">You finished the dot-to-dot activity.</p>
        <div class="dot2dot-completed-actions">
            <button type="button" class="dot2dot-action-btn" id="dot2dotRestartBtn">Start Again</button>
            <?php if ($returnTo !== ''): ?>
                <a href="<?= htmlspecialchars($returnTo, ENT_QUOTES, 'UTF-8') ?>" class="dot2dot-action-btn dot2dot-action-btn-primary">Next activity &#x2192;</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
window.DOT_TO_DOT_DATA = {
    activityId: <?= json_encode((string) ($activity['id'] ?? ''), JSON_UNESCAPED_UNICODE) ?>,
    image: <?= json_encode($image, JSON_UNESCAPED_UNICODE) ?>,
    title: <?= json_encode($title, JSON_UNESCAPED_UNICODE) ?>,
    points: <?= json_encode($points, JSON_UNESCAPED_UNICODE) ?>,
    labelSettings: <?= json_encode($labelSettings, JSON_UNESCAPED_UNICODE) ?>,
    returnTo: <?= json_encode($returnTo, JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script src="dot_to_dot_viewer.min.js?v=<?= htmlspecialchars($jsVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
<?php
$content = ob_get_clean();
render_activity_viewer($title, '🔢', $content);
