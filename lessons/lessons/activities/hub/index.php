<?php
session_start();
require_once "../../config/db.php";

$unitId = $_GET["unit"] ?? null;
if (!$unitId) die("Unidad no especificada.");

$activityTypes = [
    "drag_drop" => "Drag & Drop",
    "flashcards" => "Flashcards",
    "match" => "Match",
    "multiple_choice" => "Multiple Choice",
    "hangman" => "Hangman",
    "listen_order" => "Listen Order",
    "pronunciation" => "Pronunciation",
    "external" => "External",
    "flipbooks" => "Flipbooks"
];

// Actividades ya creadas
$stmt = $pdo->prepare("SELECT type FROM activities WHERE unit_id = :unit");
$stmt->execute(["unit" => $unitId]);
$created = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Obtener curso de la unidad
$stmtUnit = $pdo->prepare("SELECT * FROM units WHERE id = :id");
$stmtUnit->execute(["id" => $unitId]);
$unit = $stmtUnit->fetch(PDO::FETCH_ASSOC);

if (!$unit) die("Unidad no encontrada.");

$stmtCourse = $pdo->prepare("SELECT * FROM courses WHERE id = :id");
$stmtCourse->execute(["id" => $unit['course_id']]);
$course = $stmtCourse->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Escoger Actividades</title>
<style>
body {
    font-family: Arial;
    background: #f4f8ff;
    padding: 40px;
}
.card {
    background: #fff;
    padding: 25px;
    border-radius: 14px;
    max-width: 700px;
    box-shadow: 0 10px 25px rgba(0,0,0,.08);
    margin: 0 auto;
}
.item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid #eee;
}
.btn-volver {
    display:inline-block;
    margin-bottom:20px;
    background:#6b7280;
    color:#fff;
    padding:10px 18px;
    border-radius:8px;
    text-decoration:none;
    font-weight:bold;
}
button {
    margin-top: 20px;
    width: 100%;
    padding: 12px;
    background: #2563eb;
    color: #fff;
    border: none;
    border-radius: 8px;
    font-weight: bold;
    cursor: pointer;
}
.status {
    color: #16a34a;
    font-weight: bold;
}
h2 {
    text-align: center;
    margin-bottom: 20px;
}
</style>
</head>
<body>

<a class="btn-volver" href="../../academic/technical_units_view.php?course=<?= urlencode($course['id']); ?>">
Volver
</a>

<div class="card">
    <h2>Escoger Actividades</h2>

    <form method="post" action="../create_activity.php">
        <input type="hidden" name="unit" value="<?= htmlspecialchars($unitId) ?>">

        <?php foreach ($activityTypes as $key => $label): ?>
        <div class="item">
            <label>
                <input type="checkbox" name="types[]" value="<?= $key ?>"
                    <?= in_array($key, $created) ? "checked disabled" : "" ?>>
                <?= $label ?>
            </label>

            <?php if (in_array($key, $created)): ?>
                <span class="status">✔ Creada</span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <button type="submit">CREAR ACTIVIDADES →</button>
    </form>
</div>

</body>
</html>
