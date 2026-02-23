<?php
require_once __DIR__ . "/../../config/db.php";
require_once __DIR__ . "/../../core/_activity_viewer_template.php";

$activity_id = $_GET['id'] ?? null;
if (!$activity_id) die("Actividad no especificada");

$unit = $_GET['unit'] ?? null;
if (!$unit) die("Unidad no especificada");

/* ==========================
   OBTENER ACTIVIDAD MATCH
========================== */
$stmt = $pdo->prepare("
    SELECT data 
    FROM activities
    WHERE id = :id
    AND type = 'match'
");
$stmt->execute([
    "id" => $activity_id
]);

$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    die("Actividad no encontrada");
}

$data = json_decode($row["data"] ?? "[]", true);

/* ==========================
   RENDER
========================== */
ob_start();
?>

<div class="container">
    <div class="images" id="match-images"></div>
    <div class="words" id="match-words"></div>
</div>

<link rel="stylesheet" href="match.css">

<script>
const MATCH_DATA = <?= json_encode($data ?? []) ?>;
</script>

<script src="match.js"></script>

<?php
$content = ob_get_clean();
render_activity_viewer("🧩 Match", "🧩", $content);
