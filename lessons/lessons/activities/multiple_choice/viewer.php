<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$unit = isset($_GET['unit']) ? $_GET['unit'] : null;
if (!$unit) {
    die('Unidad no especificada');
}

$stmt = $pdo->prepare(
    "SELECT data
     FROM activities
     WHERE unit_id = :unit
       AND type = 'multiple_choice'
     LIMIT 1"
);
$stmt->execute(array('unit' => $unit));

$row = $stmt->fetch(PDO::FETCH_ASSOC);
$raw = isset($row['data']) ? $row['data'] : '[]';
$decoded = json_decode($raw, true);
$questions = is_array($decoded) ? $decoded : array();

if (count($questions) === 0) {
    die('No hay preguntas para esta unidad');
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
