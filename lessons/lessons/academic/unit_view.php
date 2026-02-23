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

/* ==========================
   OBTENER ACTIVIDADES
   ========================== */
$stmtActivities = $pdo->prepare("
    SELECT * FROM activities
    WHERE unit_id = :unit_id
    ORDER BY created_at ASC
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
    font-family:Arial,sans-serif;
    background:#f4f8ff;
    padding:40px;
}
.card{
    background:#fff;
    padding:25px;
    border-radius:16px;
    box-shadow:0 10px 25px rgba(0,0,0,.08);
    margin-bottom:20px;
}
a{
    display:block;
    margin-bottom:12px;
    padding:14px 18px;
    border-radius:10px;
    text-decoration:none;
    color:#fff;
}
.back{
    display:inline-block;
    background:#6b7280;
    margin-bottom:20px;
}
.activity{
    background:#16a34a;
}
.activity-title{
    font-weight:bold;
    font-size:15px;
}
.activity-type{
    font-size:12px;
    opacity:0.85;
}
small{
    display:block;
    font-size:11px;
    opacity:0.7;
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
    <p><strong>ID:</strong> <?= htmlspecialchars($unit['id']); ?></p>
    <p><strong>Posición:</strong> <?= htmlspecialchars($unit['position']); ?></p>
</div>

<div class="card">
    <h3>Actividades</h3>

    <?php if (empty($activities)): ?>
        <p>No hay actividades en esta unidad.</p>
    <?php else: ?>
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

            // Decodificar JSON
            $data = json_decode($activity['data'], true);

            $activityTitle = $data['title'] ?? strtoupper(str_replace('_',' ',$typeRaw));
            ?>

            <a class="activity"
               href="../activities/<?= htmlspecialchars($typeRaw); ?>.php?id=<?= htmlspecialchars($activity['id']); ?>">

                <div class="activity-title">
                    <?= $icon . " " . htmlspecialchars($activityTitle); ?>
                </div>

                <div class="activity-type">
                    Tipo: <?= strtoupper(str_replace('_',' ',$typeRaw)); ?>
                </div>

                <small>
                    Creado: <?= htmlspecialchars($activity['created_at']); ?>
                </small>

            </a>

        <?php endforeach; ?>
    <?php endif; ?>

</div>

</body>
</html>
