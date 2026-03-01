<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

require __DIR__ . "/../config/db.php";

/* ===============================
   OBTENER PROGRAMA TÉCNICO
=============================== */
$stmtProgram = $pdo->prepare("
    SELECT id FROM programs
    WHERE slug = 'prog_technical'
    LIMIT 1
");
$stmtProgram->execute();
$program = $stmtProgram->fetch(PDO::FETCH_ASSOC);

if (!$program) {
    die("Programa técnico no encontrado.");
}

$programId = $program["id"];

/* ===============================
   OBTENER SEMESTRES
=============================== */
$stmt = $pdo->prepare("
    SELECT id, name
    FROM courses
    WHERE program_id = :program_id
    ORDER BY id ASC
");
$stmt->execute(["program_id" => $programId]);
$semestres = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Semestres creados</title>

<style>
body {
    font-family: 'Segoe UI', sans-serif;
    background: #eef2f7;
    padding: 40px;
}

/* CONTENEDOR CENTRAL */
.container {
    max-width: 850px;
    margin: 0 auto;
}

/* BOTÓN VOLVER */
.back {
    display: inline-block;
    margin-bottom: 25px;
    background: #6b7280;
    color: white;
    padding: 10px 18px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 600;
}

/* CARD */
.card {
    background: white;
    padding: 30px;
    border-radius: 18px;
    box-shadow: 0 15px 35px rgba(0,0,0,.08);
}

/* TÍTULO */
.card h2 {
    margin-bottom: 25px;
}

/* ITEM FILA */
.row {
    background: #f4f6fb;
    padding: 15px 20px;
    border-radius: 12px;
    margin-bottom: 15px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

/* NOMBRE */
.row strong {
    font-size: 15px;
}

/* BOTONES */
.actions {
    display: flex;
    gap: 10px;
    align-items: center;
}

.btn-view {
    background: #2563eb;
    color: white;
    padding: 8px 14px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 600;
}

.inline-form {
    margin: 0;
}

.btn-delete-x {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    border: none;
    background: #dc2626;
    color: white;
    font-size: 20px;
    font-weight: 700;
    cursor: pointer;
    line-height: 1;
}
</style>
</head>

<body>

<div class="container">

<a class="back" href="../admin/dashboard.php">← Volver</a>

<div class="card">
    <h2>📘 Semestres creados</h2>

    <?php if (empty($semestres)): ?>
        <p>No hay semestres creados.</p>
    <?php else: ?>
        <?php foreach ($semestres as $sem): ?>
            <div class="row">
                <strong><?= htmlspecialchars($sem["name"]) ?></strong>

                <div class="actions">
                    <a class="btn-view"
                       href="technical_units_view.php?course=<?= urlencode($sem["id"]) ?>">
                        Ver →
                    </a>

                    <form class="inline-form" method="POST" action="delete_course.php"
                          onsubmit="return confirm('¿Eliminar semestre?');">
                        <input type="hidden" name="id" value="<?= (int) $sem["id"] ?>">
                        <input type="hidden" name="return_to" value="technical_courses_created.php">
                        <button type="submit" class="btn-delete-x" aria-label="Eliminar">×</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

</div>

</body>
</html>
