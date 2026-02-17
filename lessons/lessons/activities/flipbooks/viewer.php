<?php
session_start();

/* ===========================
   VALIDAR UNIT
=========================== */
$unit = $_GET['unit'] ?? null;
if (!$unit) {
    die("Unit not specified");
}

/* ===========================
   DB
=========================== */
require_once __DIR__ . "/../../core/db.php";

/* ===========================
   OBTENER PDF DESDE DB
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
   VERIFICAR ARCHIVO
=========================== */
$absolutePath = __DIR__ . "/../../" . $pdfPath;
$fileExists = $pdfPath && file_exists($absolutePath);

/* ===========================
   CONTENIDO PARA TEMPLATE
=========================== */
$activityTitle = "📖 Flipbooks";
$activitySubtitle = "Let's read together and explore a new story.";

ob_start();
?>

<?php if ($fileExists): ?>

    <iframe 
        src="/lessons/lessons/<?= htmlspecialchars($pdfPath) ?>" 
        style="width:100%; height:650px; border:none; border-radius:12px;">
    </iframe>

<?php else: ?>

    <p style="color:#dc2626; font-weight:bold;">
        No PDF uploaded for this unit.
    </p>

<?php endif; ?>

<?php
$activityContent = ob_get_clean();

/* ===========================
   CARGAR TEMPLATE (AL FINAL)
=========================== */
require_once __DIR__ . "/../../core/_activity_viewer_template.php";
