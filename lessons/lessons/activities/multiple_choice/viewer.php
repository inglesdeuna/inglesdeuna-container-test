<?php
require_once __DIR__."/../../config/db.php";
require_once __DIR__."/../../core/_activity_viewer_template.php";

$unit = $_GET['unit'] ?? null;
if (!$unit) die("Unidad no especificada");

$stmt = $pdo->prepare("
    SELECT data FROM activities
    WHERE unit_id = :unit
    AND type = 'multiple_choice'
");
$stmt->execute(["unit"=>$unit]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$data = json_decode($row["data"] ?? "[]", true);

ob_start();
?>

<div class="mc-wrapper">
    <div id="mc-container"></div>
</div>

<link rel="stylesheet" href="multiple_choice.css">

<script>
const MC_DATA = <?= json_encode($data ?? []) ?>;
</script>

<script src="multiple_choice.js"></script>

<?php
$content = ob_get_clean();
render_activity_viewer("📝 Multiple Choice", "📝", $content);
