<?php
session_start();
require_once "../config/db.php";

$unit_id = $_GET['unit'] ?? null;
if (!$unit_id) die("Unidad no especificada.");

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
   OBTENER UNIT
   ========================== */
$stmt = $pdo->prepare("SELECT * FROM units WHERE id = :id");
$stmt->execute(['id' => $unit_id]);
$unit = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$unit) die("Unidad no encontrada.");

/* ==========================
   OBTENER CURSO
   ========================== */
$stmtCourse = $pdo->prepare("SELECT * FROM courses WHERE id = :id");
$stmtCourse->execute(['id' => $unit['course_id']]);
$course = $stmtCourse->fetch(PDO::FETCH_ASSOC);

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

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>

<style>
body{font-family:Arial,sans-serif;background:#f4f8ff;padding:40px;}
.card{background:#fff;padding:25px;border-radius:16px;box-shadow:0 10px 25px rgba(0,0,0,.08);margin-bottom:20px;}
.back{display:inline-block;background:#6b7280;margin-bottom:20px;padding:8px 14px;border-radius:8px;color:#fff;text-decoration:none;}
.activity-box{background:#16a34a;border-radius:12px;padding:16px;margin-bottom:12px;color:#fff;cursor:grab;}
.activity-title{font-weight:bold;font-size:15px;}
.activity-type{font-size:12px;opacity:0.9;}
small{display:block;font-size:11px;opacity:0.8;}
   .draggable {
    cursor: grab;
}
.draggable:active {
    cursor: grabbing;
}
</style>
</head>
<body>

<a class="back" href="course_view.php?course=<?= htmlspecialchars($course['id']); ?>">
← Volver al Curso
</a>

<div class="card">
    <h2><?= htmlspecialchars($unit['name']); ?></h2>
    <p><strong>Curso:</strong> <?= htmlspecialchars($course['name']); ?></p>
</div>

<div class="card">
    <h3>Arrastra para ordenar</h3>

    <div id="activityList">

   <?php foreach ($activities as $activity): ?>

<?php
$typeRaw = $activity['type'];

$icons = [
    'hangman' => '🎯',
    'drag_drop' => '🧩',
    'flashcards' => '🃏',
    'match' => '🔗',
    'multiple_choice' => '✅',
    'listen_order' => '🎧',
    'pronunciation' => '🎤',
    'external' => '🌐',
    'flipbooks' => '📖'
];

$icon = $icons[$typeRaw] ?? '📘';

$data = json_decode($activity['data'], true);
$activityTitle = $data['title'] ?? strtoupper(str_replace('_',' ',$typeRaw));
?>

<div class="activity-box draggable" draggable="true" data-id="<?= $activity['id']; ?>">

    <div class="activity-title">
        <?= $icon . " " . htmlspecialchars($activityTitle); ?>
    </div>

    <small>
        Tipo: <?= strtoupper(str_replace('_',' ',$typeRaw)); ?><br>
        Creado: <?= htmlspecialchars($activity['created_at']); ?>
    </small>

    <div style="margin-top:10px;">

        <a class="btn btn-open"
           href="../activities/<?= htmlspecialchars($typeRaw); ?>.php?id=<?= htmlspecialchars($activity['id']); ?>">
           Abrir
        </a>

        <a class="btn btn-edit"
           href="../activities/<?= htmlspecialchars($typeRaw); ?>_editor.php?id=<?= htmlspecialchars($activity['id']); ?>">
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
    </div>

</div>

<script>
var sortable = new Sortable(document.getElementById('activityList'), {
    animation: 150,
    onEnd: function () {

        let order = [];
        document.querySelectorAll('#activityList .activity-box').forEach(el => {
            order.push(el.dataset.id);
        });

        fetch('update_activity_order.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'order[]=' + order.join('&order[]=')
        });
    }
});
</script>

</body>
</html>
