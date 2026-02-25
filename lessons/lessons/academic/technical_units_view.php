<?php
session_start();
require_once "../config/db.php";

$unit_id = $_GET['unit'] ?? null;

if (!$unit_id) {
    die("Unidad no especificada.");
}

/* ==========================
   OBTENER UNIT
========================== */
$stmt = $pdo->prepare("SELECT * FROM units WHERE id = :id");
$stmt->execute(['id' => $unit_id]);
$unit = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$unit) {
    die("Unidad no encontrada.");
}

/* ==========================
   OBTENER CURSO
========================== */
$stmtCourse = $pdo->prepare("SELECT * FROM courses WHERE id = :id");
$stmtCourse->execute(['id' => $unit['course_id']]);
$course = $stmtCourse->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    die("Curso no encontrado.");
}

/* ==========================
   ELIMINAR ACTIVIDAD
========================== */
if (isset($_GET['delete'])) {

    $delete_id = $_GET['delete'];

    $stmtDelete = $pdo->prepare("DELETE FROM activities WHERE id = :id");
    $stmtDelete->execute(['id' => $delete_id]);

    header("Location: unit_view.php?unit=" . urlencode($unit_id));
    exit;
}

/* ==========================
   OBTENER ACTIVIDADES
========================== */
$stmtActivities = $pdo->prepare("
    SELECT * FROM activities
    WHERE unit_id = :unit_id
    ORDER BY position ASC
");
$stmtActivities->execute(['unit_id' => $unit_id]);
$activities = $stmtActivities->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($unit['name']); ?></title>

<style>
body{
    font-family: Arial, sans-serif;
    background:#f4f8ff;
    padding:40px;
}

.card{
    background:#ffffff;
    padding:25px;
    border-radius:16px;
    box-shadow:0 10px 25px rgba(0,0,0,.08);
    margin-bottom:20px;
}

.back{
    display:inline-block;
    background:#6b7280;
    margin-bottom:20px;
    padding:10px 18px;
    border-radius:8px;
    color:#ffffff;
    text-decoration:none;
    font-weight:600;
}

.activity-box{
    background:#16a34a;
    border-radius:12px;
    padding:18px;
    margin-bottom:14px;
    color:#ffffff;
    display:flex;
    justify-content:space-between;
    align-items:center;
}

.btn{
    padding:8px 14px;
    border-radius:8px;
    text-decoration:none;
    font-size:14px;
    font-weight:600;
    color:#ffffff;
    margin-left:8px;
}

.btn-open{ background:#14532d; }
.btn-edit{ background:#1d4ed8; }
.btn-delete{ background:#dc2626; }
</style>
</head>

<body>

<a class="back" href="technical_units_view.php?course=<?= urlencode($course['id']); ?>">
← Volver a Semestres
</a>

<div class="card">
    <h2><?= htmlspecialchars($unit['name']); ?></h2>
    <p><strong>Semestre:</strong> <?= htmlspecialchars($course['name']); ?></p>
</div>

<div class="card">

<?php if (empty($activities)): ?>
    <p>No hay actividades creadas.</p>
<?php else: ?>
    <?php foreach ($activities as $activity): ?>

        <div class="activity-box">

            <div>
                <strong><?= strtoupper(str_replace('_',' ', $activity['type'])); ?></strong><br>
                <?= htmlspecialchars($activity['created_at']); ?>
            </div>

            <div>
                <a class="btn btn-open"
                   href="../activities/<?= htmlspecialchars($activity['type']); ?>/viewer.php?id=<?= htmlspecialchars($activity['id']); ?>&unit=<?= urlencode($unit_id); ?>">
                   Abrir
                </a>

                <a class="btn btn-edit"
                   href="../activities/<?= htmlspecialchars($activity['type']); ?>/editor.php?id=<?= htmlspecialchars($activity['id']); ?>&unit=<?= urlencode($unit_id); ?>">
                   Editar
                </a>

                <a class="btn btn-delete"
                   href="unit_view.php?unit=<?= urlencode($unit_id); ?>&delete=<?= htmlspecialchars($activity['id']); ?>"
                   onclick="return confirm('¿Eliminar esta actividad?');">
                   Eliminar
                </a>
            </div>

        </div>

    <?php endforeach; ?>
<?php endif; ?>

</div>

</body>
</html>
