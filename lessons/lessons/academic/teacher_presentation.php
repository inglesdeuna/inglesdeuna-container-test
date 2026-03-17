<?php
session_start();

if (!isset($_SESSION['academic_logged'])) {
    header('Location: login.php');
    exit;
}

require_once "../config/db.php";

$unit_id = $_GET['unit'] ?? '';
$step = isset($_GET['step']) ? (int)$_GET['step'] : 0;

if (!$unit_id) {
    die("Unidad no especificada");
}

/* ===============================
   OBTENER ACTIVIDADES ORDENADAS
=============================== */
$stmt = $pdo->prepare("
    SELECT id, type
    FROM activities
    WHERE unit_id = :unit
    ORDER BY COALESCE(position,0) ASC, id ASC
");
$stmt->execute(['unit' => $unit_id]);

$activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total = count($activities);

if ($total === 0) {
    die("No hay actividades.");
}

/* ===============================
   SI TERMINÓ
=============================== */
if ($step >= $total) {
    ?>
    <html>
    <body style="font-family:Arial;text-align:center;padding:100px;">
        <h1>✅ Completed</h1>
        <a href="dashboard.php">Volver</a>
    </body>
    </html>
    <?php
    exit;
}

/* ===============================
   ACTIVIDAD ACTUAL
=============================== */
$current = $activities[$step];
$type = $current['type'];
$id = $current['id'];

/* ===============================
   SIGUIENTE
=============================== */
$nextStep = $step + 1;
$nextUrl = "teacher_presentation.php?unit=$unit_id&step=$nextStep";

/* ===============================
   REDIRIGIR AL VIEWER REAL
=============================== */
$viewerPath = "../activities/$type/viewer.php";

if (!file_exists(__DIR__ . "/../activities/$type/viewer.php")) {
    die("Viewer no encontrado para tipo: $type");
}

header("Location: $viewerPath?id=$id&next=$nextUrl");
exit;
