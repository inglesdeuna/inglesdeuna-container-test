<?php
session_start();
require_once "../../config/db.php";

$unit_id = $_GET['unit'] ?? null;

if (!$unit_id) {
    die("Unidad no especificada.");
}

/* ===============================
   OBTENER UNIT (SOLO ENGLISH)
=============================== */
$stmt = $pdo->prepare("
    SELECT id, phase_id
    FROM units
    WHERE id = :id
    LIMIT 1
");
$stmt->execute(['id' => $unit_id]);
$unit = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$unit) {
    die("Unidad no encontrada.");
}

if (empty($unit['phase_id'])) {
    die("Esta unidad no pertenece a English Structure.");
}

/* ===============================
   URL VOLVER (ENGLISH)
=============================== */
$backUrl = "../../academic/english_structure_units.php?phase=" . urlencode($unit['phase_id']);

/* ===============================
   TIPOS DE ACTIVIDADES
=============================== */
$activityTypes = [
    "drag_drop" => "Drag & Drop",
    "flashcards" => "Flashcards",
    "match" => "Match",
    "multiple_choice" => "Multiple Choice",
    "hangman" => "Hangman",
    "listen_order" => "Listen Order",
    "pronunciation" => "Pronunciation",
    "dictation" => "Dictation",
    "external" => "External",
    "flipbooks" => "Flipbooks",
    "powerpoint" => "PowerPoint"
];

/* ===============================
   ACTIVIDADES YA CREADAS
=============================== */
$stmtActivities = $pdo->prepare("
    SELECT DISTINCT type
    FROM activities
    WHERE unit_id = :unit_id
");
$stmtActivities->execute(['unit_id' => $unit_id]);
$createdTypes = $stmtActivities->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Hub de Actividades (English)</title>

<style>
body{
    font-family: Arial, sans-serif;
    background:#eef2f7;
    padding:40px;
}

.btn-volver{
    display:inline-block;
    background:#6b7280;
    color:#fff;
    padding:10px 16px;
    border-radius:8px;
    text-decoration:none;
    font-weight:600;
    margin-bottom:25px;
}

.card{
    max-width:600px;
    margin:0 auto;
    background:#ffffff;
    padding:30px;
    border-radius:18px;
    box-shadow:0 15px 35px rgba(0,0,0,.08);
}

.card h2{
    text-align:center;
    margin-bottom:25px;
}

.list{
    list-style:none;
    padding:0;
    margin:0 0 25px 0;
}

.list li{
    padding:12px 0;
    border-bottom:1px solid #eee;
    display:flex;
    justify-content:space-between;
    align-items:center;
}

.created{
    color:#16a34a;
    font-weight:bold;
}

.btn-submit{
    width:100%;
    background:#2563eb;
    color:#fff;
    padding:14px;
    border:none;
    border-radius:10px;
    font-weight:bold;
    cursor:pointer;
}
</style>
</head>

<body>

<a class="btn-volver" href="<?= $backUrl; ?>">
    ← Volver
</a>

<div class="card">

    <h2>Escoger Actividades (English)</h2>

    <form method="POST" action="../create_activity.php">
        <input type="hidden" name="unit" value="<?= htmlspecialchars($unit_id); ?>">

        <ul class="list">
            <?php foreach ($activityTypes as $type => $label): ?>
                <li>
                    <label>
                        <input type="checkbox" name="types[]" value="<?= $type; ?>"
                        <?= in_array($type, $createdTypes) ? 'checked disabled' : ''; ?>>
                        <?= htmlspecialchars($label); ?>
                    </label>

                    <?php if (in_array($type, $createdTypes)): ?>
                        <span class="created">✓ Creada</span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>

        <button type="submit" class="btn-submit">
            CREAR ACTIVIDADES →
        </button>
    </form>

</div>

</body>
</html>
