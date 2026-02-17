<?php
session_start();

require_once __DIR__ . "/../../core/db.php";
require_once __DIR__ . "/../../core/_activity_viewer_template.php";

/* ===========================
   VALIDAR UNIT  (ESTO YA FUNCIONABA)
=========================== */
$unit = $_GET['unit'] ?? null;
if (!$unit) die("Unidad no especificada");

/* ===========================
   OBTENER DESDE DB (ESTO YA FUNCIONABA)
=========================== */
$stmt = $pdo->prepare("
    SELECT data 
    FROM activities 
    WHERE unit_id = :unit 
    AND type = 'flipbooks'
    LIMIT 1
");
$stmt->execute(['unit' => $unit]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$pdfPath = "";

if ($row) {
    $decoded = json_decode($row['data'], true);
    $pdfPath = $decoded['pdf'] ?? "";
}

/* ===========================
   CONTENIDO DEL FLIPBOOK
   SOLO ESTA PARTE ES DIFERENTE
=========================== */

ob_start();
?>

<?php if ($pdfPath): ?>

    <iframe 
        src="/<?= htmlspecialchars($pdfPath) ?>" 
        style="width:100%; height:700px; border:none; border-radius:12px;">
    </iframe>

<?php else: ?>

    <p style="color:#dc2626; font-weight:600;">
        No PDF uploaded for this unit.
    </p>

<?php endif; ?>

<?php
$content = ob_get_clean();

/* ===========================
   VARIABLES PARA TEMPLATE
=========================== */
$activityTitle = "📖 Flipbooks";
$activitySubtitle = "Let's read together and explore a new story.";
$activityContent = $content;

/* ===========================
   RENDER TEMPLATE (ESTO YA FUNCIONABA)
=========================== */
render_activity_viewer(
    $activityTitle,
    "📖",
    $activityContent,
    $activitySubtitle
);
