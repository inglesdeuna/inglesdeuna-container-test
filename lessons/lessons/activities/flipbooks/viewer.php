<?php
session_start();

require_once __DIR__ . "/../../core/db.php";
require_once __DIR__ . "/../../core/_activity_viewer_template.php";

/* ===========================
   VALIDAR UNIT
=========================== */
$unit = $_GET['unit'] ?? null;
if (!$unit) {
    die("Unit not specified");
}

/* ===========================
   OBTENER DESDE DB
=========================== */
$stmt = $pdo->prepare("
    SELECT data
    FROM activities
    WHERE unit_id = :unit
    AND type = 'flipbooks'
    LIMIT 1
");

$stmt->execute([':unit' => $unit]);
$row = $stmt->fetchColumn();

$pdfPath = "";

if ($row) {
    $decoded = json_decode($row, true);
    $pdfPath = $decoded['pdf'] ?? "";
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
    <p style="color:#ef4444; font-weight:bold;">
        No PDF uploaded for this unit.
    </p>
    <?php
}

$content = ob_get_clean();

/* ===========================
   RENDER TEMPLATE
=========================== */
render_activity_viewer(
    "📖 Flipbooks",
    "📖",
    "Let's read together and explore a new story.",
    $content,
    $unit
);
