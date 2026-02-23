<?php
session_start();

require_once "../config/db.php";

$course_id = $_GET['course'] ?? null;

if (!$course_id) {
    die("Curso no especificado.");
}

/* ==========================
   OBTENER CURSO
   ========================== */
$stmt = $pdo->prepare("SELECT * FROM courses WHERE id = :id");
$stmt->execute(['id' => $course_id]);
$course = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    die("Curso no encontrado.");
}

/* ==========================
   CREAR UNIT
   ========================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unit_name'])) {

    $unit_id = uniqid('unit_');
    $name = trim($_POST['unit_name']);

    if ($name !== '') {

        $stmtInsert = $pdo->prepare("
            INSERT INTO units (id, course_id, name, position)
            VALUES (:id, :course_id, :name,
                COALESCE(
                    (SELECT MAX(position) + 1 FROM units WHERE course_id = :course_id2),
                    1
                )
            )
        ");

        $stmtInsert->execute([
            'id' => $unit_id,
            'course_id' => $course_id,
            'course_id2' => $course_id,
            'name' => $name
        ]);
    }

    header("Location: ../admin/dashboard.php");
    exit;
}

/* ==========================
   OBTENER UNITS
   ========================== */
$stmtUnits = $pdo->prepare("
    SELECT * FROM units 
    WHERE course_id = :course_id 
    ORDER BY position ASC
");
$stmtUnits->execute(['course_id' => $course_id]);
$units = $stmtUnits->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($course['name']); ?></title>
<style>
body{font-family:Arial,sans-serif;background:#f4f8ff;padding:40px;}
.card{background:#fff;padding:25px;border-radius:16px;box-shadow:0 10px 25px rgba(0,0,0,.08);margin-bottom:20px;}
a{display:block;margin-bottom:10px;padding:10px 15px;background:#2563eb;color:#fff;text-decoration:none;border-radius:8px;}
form input{padding:8px;width:70%;}
form button{padding:8px 15px;background:#16a34a;color:#fff;border:none;border-radius:6px;cursor:pointer;}
.back{margin-bottom:20px;display:inline-block;background:#6b7280;}
</style>
</head>
<body>

<a class="back" href="../admin/dashboard.php">← Volver al Dashboard</a>

<div class="card">
    <h2><?= htmlspecialchars($course['name']); ?></h2>

    <form method="POST">
        <input type="text" name="unit_name" placeholder="Nombre del módulo" required>
        <button type="submit">Crear Unit</button>
    </form>
</div>

<div class="card">
    <h3>Módulos</h3>

    <?php if (empty($units)): ?>
        <p>No hay módulos creados.</p>
    <?php else: ?>
        <?php foreach ($units as $unit): ?>
            <a href="unit_view.php?unit=<?= htmlspecialchars($unit['id']); ?>">
                <?= htmlspecialchars($unit['name'] ?? 'Sin nombre'); ?>
            </a>
        <?php endforeach; ?>
    <?php endif; ?>

</div>

</body>
</html>
