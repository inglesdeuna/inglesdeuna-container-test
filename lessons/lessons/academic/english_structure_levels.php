<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

require __DIR__ . "/../config/db.php";

/* ===============================
   OBTENER PROGRAMA ENGLISH
=============================== */
$stmtProgram = $pdo->prepare("
    SELECT * FROM programs
    WHERE slug = 'prog_english_courses'
    LIMIT 1
");
$stmtProgram->execute();
$program = $stmtProgram->fetch(PDO::FETCH_ASSOC);

if (!$program) {
    die("Programa de inglés no encontrado.");
}

/* ===============================
   CREAR LEVEL
=============================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && !empty($_POST["level_name"])) {

    $levelName = strtoupper(trim($_POST["level_name"]));

    $stmtInsert = $pdo->prepare("
        INSERT INTO english_levels (program_id, name, created_at)
        VALUES (:program_id, :name, NOW())
    ");

    $stmtInsert->execute([
        "program_id" => $program["id"],
        "name" => $levelName
    ]);

    header("Location: english_structure_levels.php");
    exit;
}

/* ===============================
   LISTAR LEVELS
=============================== */
$stmtLevels = $pdo->prepare("
    SELECT *
    FROM english_levels
    WHERE program_id = :program_id
    ORDER BY created_at ASC
");
$stmtLevels->execute(["program_id" => $program["id"]]);
$levels = $stmtLevels->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>English - Gestionar Levels</title>

<style>
:root{
    --bg:#eef7f0;
    --card:#ffffff;
    --line:#d8e8dc;
    --text:#1f3b28;
    --green:#2f9e44;
    --green-dark:#237a35;
    --gray:#6f7e73;
    --shadow:0 10px 24px rgba(0,0,0,.08);
}
body{
    font-family: Arial, sans-serif;
    background:var(--bg);
    padding:40px;
    color:var(--text);
}

.back{
    display:inline-block;
    margin-bottom:25px;
    background:linear-gradient(180deg,#7b8b7f,#66756a);
    color:#ffffff;
    padding:10px 18px;
    border-radius:8px;
    text-decoration:none;
    font-weight:600;
}

.card{
    background:var(--card);
    padding:25px;
    border-radius:16px;
    box-shadow:var(--shadow);
    border:1px solid var(--line);
    margin-bottom:25px;
    max-width:900px;
}

input{
    width:100%;
    padding:12px;
    margin-top:10px;
    border-radius:8px;
    border:1px solid var(--line);
}

button{
    margin-top:15px;
    padding:10px 18px;
    background:linear-gradient(180deg,var(--green),var(--green-dark));
    color:#ffffff;
    border:none;
    border-radius:8px;
    font-weight:600;
    cursor:pointer;
}

.item{
    background:#f7fcf8;
    border:1px solid var(--line);
    padding:15px 18px;
    border-radius:12px;
    margin-bottom:12px;
    display:flex;
    justify-content:space-between;
    align-items:center;
}

.btn-blue{
    background:linear-gradient(180deg,var(--green),var(--green-dark));
    color:#ffffff;
    padding:8px 16px;
    border-radius:8px;
    text-decoration:none;
    font-weight:600;
}
</style>
</head>

<body>

<a class="back" href="../admin/dashboard.php">
← Volver
</a>

<div class="card">
    <h2>➕ Crear Level</h2>

    <form method="POST">
        <input type="text" name="level_name" required placeholder="Ej: A1">
        <button type="submit">Crear</button>
    </form>
</div>

<div class="card">
    <h2>📋 Levels creados</h2>

    <?php if (empty($levels)): ?>
        <p>No hay levels creados.</p>
    <?php else: ?>
        <?php foreach ($levels as $level): ?>
            <div class="item">
                <strong><?= htmlspecialchars($level["name"]) ?></strong>

                <a class="btn-blue"
                   href="english_structure_phases.php?level=<?= urlencode($level["id"]) ?>">
                    Administrar →
                </a>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

</body>
</html>
