<?php
session_start();

// 🔐 SOLO ADMIN
if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

require __DIR__ . "/../config/db.php";

/* ===============================
   OBTENER COURSE (SEMESTRE)
   =============================== */
$courseId = $_GET["course"] ?? null;

if (!$courseId) {
    die("Curso no especificado.");
}

/* ===============================
   CREAR UNIT
   =============================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && !empty($_POST["unit_name"])) {

    $unitId = uniqid("unit_");

    $stmt = $pdo->prepare("
        INSERT INTO units (id, course_id, name)
        VALUES (:id, :course_id, :name)
    ");

    $stmt->execute([
        "id" => $unitId,
        "course_id" => $courseId,
        "name" => trim($_POST["unit_name"])
    ]);

    header("Location: units_manager.php?course=" . urlencode($courseId));
    exit;
}

/* ===============================
   LISTAR UNITS
   =============================== */
$stmt = $pdo->prepare("
    SELECT id, name
    FROM units
    WHERE course_id = :course
    ORDER BY name ASC
");

$stmt->execute(["course" => $courseId]);
$units = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Units</title>

<style>
body{
    font-family:Arial;
    background:#f4f8ff;
    padding:40px;
}
.card{
    background:#fff;
    padding:25px;
    border-radius:12px;
    margin-bottom:25px;
    max-width:600px;
}
.unit{
    background:#ffffff;
    padding:15px;
    border-radius:10px;
    margin-bottom:10px;
    display:flex;
    justify-content:space-between;
    box-shadow:0 4px 8px rgba(0,0,0,.08);
}
.unit a{
    text-decoration:none;
    color:#2563eb;
    font-weight:bold;
}
input{
    width:100%;
    padding:12px;
    margin-top:10px;
}
button{
    margin-top:15px;
    padding:12px 18px;
    background:#2563eb;
    color:#fff;
    border:none;
    border-radius:8px;
    font-weight:700;
}
.back{
    display:inline-block;
    margin-bottom:30px;
    text-decoration:none;
    font-weight:bold;
    color:#555;
}
</style>
</head>

<body>

<a class="back" href="courses_manager.php?program=prog_technical">
← Volver a Semestres
</a>

<div class="card">
    <h2>➕ Crear Unit</h2>
    <form method="post">
        <input type="text" name="unit_name" required placeholder="Ej: Inglés Técnico 1">
        <button>Crear Unit</button>
    </form>
</div>

<div class="card">
    <h2>📋 Units creadas</h2>

    <?php if (empty($units)): ?>
        <p>No hay units creadas.</p>
    <?php else: ?>
        <?php foreach ($units as $unit): ?>
            <div class="unit">
                <strong><?= htmlspecialchars($unit["name"]) ?></strong>
                <a href="unit_view.php?unit=<?= urlencode($unit["id"]) ?>">
                    Entrar →
                </a>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

</div>

</body>
</html>
