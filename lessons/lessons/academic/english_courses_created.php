<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

require __DIR__ . "/../config/db.php";

$programSlug = "prog_english_courses";

/* ===============================
   OBTENER PROGRAMA
=============================== */
$stmtProgram = $pdo->prepare("
    SELECT id, name 
    FROM programs 
    WHERE slug = :slug 
    LIMIT 1
");

$stmtProgram->execute(["slug" => $programSlug]);
$program = $stmtProgram->fetch(PDO::FETCH_ASSOC);

if (!$program) {
    die("Programa inglés no encontrado.");
}

$programId = $program["id"];

/* ===============================
   LISTAR CURSOS CREADOS
=============================== */
$stmt = $pdo->prepare("
    SELECT id, name
    FROM courses
    WHERE program_id = :program_id
    ORDER BY id ASC
");

$stmt->execute(["program_id" => $programId]);
$courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Cursos creados</title>
</head>
<body>

<h2>Cursos creados</h2>

<?php if (empty($courses)): ?>
    <p>No hay registros creados.</p>
<?php else: ?>
    <?php foreach ($courses as $course): ?>
        <div>
            <strong><?= htmlspecialchars($course["name"]) ?></strong>
            <a href="english_units.php?course=<?= $course["id"] ?>">
                Ver →
            </a>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

</body>
</html>
