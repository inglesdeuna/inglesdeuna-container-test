<?php
session_start();

require_once __DIR__ . "/../../core/db.php";

/* ===========================
   VALIDAR UNIT
=========================== */
$unit = $_GET['unit'] ?? null;
if (!$unit) {
    die("Unit not specified");
}

/* ===========================
   OBTENER DATA DESDE DB
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
    $content = json_decode($row['data'], true);
    $pdfPath = $content['pdf'] ?? "";
}

/* ===========================
   GENERAR CONTENIDO
=========================== */
ob_start();

if ($pdfPath) {
    ?>

    <iframe
        src="/<?= htmlspecialchars($pdfPath) ?>"
        style="width:100%; height:700px; border:none; border-radius:12px;">
    </iframe>

    <?php
} else {
    ?>

    <div style="color:#ef4444; font-weight:bold; text-align:center;">
        No PDF uploaded for this unit.
    </div>

    <?php
}

$activityContent = ob_get_clean();

/* ===========================
   VARIABLES PARA TEMPLATE
=========================== */
$activityTitle = "📖 Flipbooks";
$activitySubtitle = "Let's read together and explore a new story.";

/* ===========================
   CARGAR TEMPLATE
=========================== */
require_once __DIR__ . "/../../core/_activity_viewer_template.php";

