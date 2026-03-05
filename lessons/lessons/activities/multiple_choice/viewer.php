<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';

if ($activityId === '' && $unit === '') {
    die('Actividad no especificada');
}

$row = null;

if ($activityId !== '') {
    $stmt = $pdo->prepare(
        "SELECT data
         FROM activities
         WHERE id = :id
           AND type = 'multiple_choice'
         LIMIT 1"
    );
    $stmt->execute(array('id' => $activityId));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$row && $unit !== '') {
    $stmt = $pdo->prepare(
        "SELECT data
         FROM activities
         WHERE unit_id = :unit
           AND type = 'multiple_choice'
         LIMIT 1"
    );
    $stmt->execute(array('unit' => $unit));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
}

$raw = isset($row['data']) ? $row['data'] : '[]';
$decoded = json_decode($raw, true);

$questions = array();
if (is_array($decoded)) {
    $isList = array_keys($decoded) === range(0, count($decoded) - 1);

    if ($isList) {
        $questions = $decoded;
    } elseif (isset($decoded['questions']) && is_array($decoded['questions'])) {
        $questions = $decoded['questions'];
    }
}

$cssVersion = file_exists(__DIR__ . '/multiple_choice.css') ? (string) filemtime(__DIR__ . '/multiple_choice.css') : (string) time();
$jsVersion = file_exists(__DIR__ . '/multiple_choice.js') ? (string) filemtime(__DIR__ . '/multiple_choice.js') : (string) time();

ob_start();
?>

<div class="mc-viewer" id="mc-container">
    <div class="mc-status" id="mc-status"></div>

    <div class="mc-card">
        <div class="mc-question" id="mc-question"></div>
        <img id="mc-image" class="mc-image" alt="Question image">
        <div class="mc-options" id="mc-options"></div>
    </div>

    <div class="mc-controls">
        <button type="button" class="mc-btn" id="mc-check">✅ Check</button>
        <button type="button" class="mc-btn" id="mc-next">➡️ Next</button>
    </div>

    <div class="mc-feedback" id="mc-feedback"></div>
</div>

<link rel="stylesheet" href="multiple_choice.css?v=<?php echo urlencode($cssVersion); ?>">
<script>
const MULTIPLE_CHOICE_DATA = <?php echo json_encode($questions, JSON_UNESCAPED_UNICODE); ?>;
</script>
<script src="multiple_choice.js?v=<?php echo urlencode($jsVersion); ?>"></script>

<?php
$content = ob_get_clean();
render_activity_viewer('Multiple Choice', '📝', $content);
