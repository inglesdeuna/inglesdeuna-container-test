<?php
require_once __DIR__."/../../config/db.php";
require_once __DIR__."/../../core/_activity_viewer_template.php";

$unit = $_GET['unit'] ?? null;
if (!$unit) die("Unidad no especificada");

$stmt = $pdo->prepare("
    SELECT data FROM activities
    WHERE unit_id = :unit
    AND type = 'match'
");
$stmt->execute(["unit"=>$unit]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$data = json_decode($row["data"] ?? "[]", true);

ob_start();
?>

<div class="match-container">
    <div id="match-images"></div>
    <div id="match-words"></div>
</div>

<link rel="stylesheet" href="match.css">

<script>
const MATCH_DATA = <?= json_encode($data ?? []) ?>;
</script>

<script src="match.js"></script>

<?php
$content = ob_get_clean();
render_activity_viewer("🧩 Match", "🧩", $content);
