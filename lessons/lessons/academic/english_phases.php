<?php
session_start();
require __DIR__ . "/../config/db.php";

if (!isset($_SESSION["admin_logged"])) {
    header("Location: ../admin/login.php");
    exit;
}

$programId = $_GET["program"] ?? null;

if ($programId !== "prog_english_courses") {
    die("Programa inválido");
}

/* ==========================
   OBTENER PHASES (CURSOS)
========================== */

$stmt = $pdo->prepare("
    SELECT id, name
    FROM courses
    WHERE program_id = 'prog_english_courses'
    ORDER BY name ASC
");

$stmt->execute();
$phases = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Fases — Cursos de Inglés</title>
<style>
body{font-family:Arial;background:#f4f8ff;padding:40px}
h1{color:#2563eb}
.card{background:#fff;padding:25px;border-radius:12px;max-width:600px}
.phase{
    background:#fff;
    padding:15px;
    border-radius:10px;
    margin-bottom:10px;
    display:flex;
    justify-content:space-between;
    box-shadow:0 4px 8px rgba(0,0,0,.08)
}
a{text-decoration:none;color:#2563eb;font-weight:bold}
</style>
</head>
<body>

<h1>📘 Cursos de Inglés — Fases</h1>

<div class="card">

<?php if (empty($phases)): ?>
    <p>No hay fases creadas.</p>
<?php else: ?>
    <?php foreach ($phases as $phase): ?>
        <div class="phase">
            <strong><?= htmlspecialchars($phase["name"]) ?></strong>
            <a href="english_levels.php?phase=<?= urlencode($phase["id"]) ?>">
                Entrar →
            </a>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

</div>

</body>
</html>
