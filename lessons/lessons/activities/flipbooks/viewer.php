<?php
session_start();

require_once __DIR__ . "/../../core/db.php";

/* 1️⃣ DEFINIR UNIT */
$unit = $_GET['unit'] ?? null;
if (!$unit) die("Unidad no especificada");

/* 2️⃣ CONSULTA DB */
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

/* 3️⃣ GENERAR CONTENIDO */
ob_start();
?>

<?php if ($pdfPath): ?>

    <iframe 
        src="/lessons/lessons/<?= htmlspecialchars($pdfPath) ?>" 
        style="width:100%; height:700px; border:none; border-radius:12px;">
    </iframe>

<?php else: ?>

    <p style="color:#dc2626; font-weight:600;">
        No PDF uploaded for this unit.
    </p>

<?php endif; ?>

<?php
$activityContent = ob_get_clean();

/* 4️⃣ VARIABLES PARA TEMPLATE */
$activityTitle = "📖 Flipbooks";
$activitySubtitle = "Let's read together and explore a new story.";

/* 5️⃣ REQUIERE TEMPLATE AL FINAL */
require_once __DIR__ . "/../../core/_activity_viewer_template.php";
