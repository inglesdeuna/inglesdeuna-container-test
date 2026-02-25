<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
    header("Location: ../../admin/login.php");
    exit;
}

require __DIR__ . "/../../config/db.php";

$unitId = $_GET["unit"] ?? null;

if (!$unitId) {
    die("Unidad no especificada.");
}

$stmt = $pdo->prepare("
    SELECT u.*, c.name AS course_name, c.program_id
    FROM units u
    JOIN courses c ON u.course_id = c.id
    WHERE u.id = :unit
    LIMIT 1
");

$stmt->execute(["unit" => $unitId]);
$unit = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$unit) {
    die("Unidad no válida.");
}

$programLabel = $unit["program_id"] === "prog_technical"
    ? "Programa Técnico"
    : "Programa Inglés";

/* TIPOS */
$activityTypes = [
    "drag_drop" => "Drag & Drop",
    "external" => "External",
    "flashcards" => "Flashcards",
    "flipbooks" => "Flipbooks",
    "hangman" => "Hangman",
    "listen_order" => "Listen Order",
    "match" => "Match",
    "multiple_choice" => "Multiple Choice",
    "pronunciation" => "Pronunciation"
];

/* YA CREADAS */
$stmt = $pdo->prepare("
    SELECT type FROM activities
    WHERE unit_id = :unit
");
$stmt->execute(["unit" => $unitId]);
$created = $stmt->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Activities Hub</title>

<style>
body{font-family:Arial;background:#f4f8ff;padding:40px}

.back{
    display:inline-block;
    margin-bottom:20px;
    background:#6b7280;
    color:#fff;
    padding:8px 14px;
    border-radius:8px;
    text-decoration:none;
}

.header-box{
    background:#fff;
    padding:20px 25px;
    border-radius:12px;
    margin-bottom:30px;
    box-shadow:0 6px 14px rgba(0,0,0,.08);
}

.meta{font-size:14px;color:#6b7280}

.hub-card{
    background:#fff;
    padding:25px;
    border-radius:12px;
    max-width:700px;
    box-shadow:0 6px 14px rgba(0,0,0,.08);
}

.hub-item{
    display:flex;
    justify-content:space-between;
    align-items:center;
    padding:12px 0;
    border-bottom:1px solid #eee;
}

.status{
    color:#16a34a;
    font-weight:bold;
    font-size:14px;
}

.create-btn{
    margin-top:25px;
    width:100%;
    padding:12px;
    background:#2563eb;
    color:#fff;
    border:none;
    border-radius:8px;
    font-weight:bold;
    cursor:pointer;
}
</style>
</head>

<body>

<a class="back" href="../../academic/technical_created.php">
← Volver a Cursos
</a>

<div class="header-box">
<h2><?= htmlspecialchars($unit["name"]) ?></h2>
<div class="meta">
<?= htmlspecialchars($programLabel) ?> |
<?= htmlspecialchars($unit["course_name"]) ?> |
ID Unidad: <?= htmlspecialchars($unitId) ?>
</div>
</div>

<div class="hub-card">

<form method="post" action="../create_activity.php">

<input type="hidden" name="unit" value="<?= htmlspecialchars($unitId) ?>">

<?php foreach ($activityTypes as $key => $label): 
    $isCreated = in_array($key, $created);
?>

<div class="hub-item">
<label>
<input type="checkbox" name="types[]" value="<?= $key ?>">
<?= $label ?>
</label>

<?php if ($isCreated): ?>
<span class="status">✔ Creada</span>
<?php endif; ?>

</div>

<?php endforeach; ?>

<button class="create-btn">CREAR ACTIVIDADES</button>

</form>

</div>

</body>
</html>
