<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

require __DIR__ . "/../config/db.php";

$programId = $_GET["program"] ?? null;

if (!$programId) {
    die("Programa no especificado");
}

/* ===============================
   LISTAR CURSOS DEL PROGRAMA
=============================== */
$stmt = $pdo->prepare("
    SELECT id, name
    FROM courses
    WHERE program_id = :program
    ORDER BY name ASC
");

$stmt->execute([
    "program" => $programId
]);

$courses = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Semestres creados</title>
<style>
body{font-family:Arial;background:#f4f8ff;padding:40px}
.card{background:#fff;padding:25px;border-radius:16px;max-width:900px;box-shadow:0 10px 25px rgba(0,0,0,.08)}
.item{background:#f1f5f9;padding:18px;border-radius:12px;margin-bottom:14px;display:flex;justify-content:space-between;align-items:center}
.btn{background:#2563eb;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:600}
.back{display:inline-block;margin-bottom:20px;background:#6b7280;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none}
</style>
</head>
<body>

<a class="back" href="../admin/dashboard.php">
← Volver al Dashboard
</a>

<div class="card">
<h2>Programa Técnico</h2>

<?php if (empty($courses)): ?>
    <p>No hay semestres creados.</p>
<?php else: ?>
    <?php foreach ($courses as $c): ?>
        <div class="item">
            <strong><?= htmlspecialchars($c["name"]) ?></strong>
            <a class="btn"
               href="technical_units.php?course=<?= urlencode($c["id"]) ?>">
               Administrar →
            </a>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

</div>

</body>
</html>
