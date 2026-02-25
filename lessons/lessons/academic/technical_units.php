<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
  header("Location: ../admin/login.php");
  exit;
}

require __DIR__ . "/../config/db.php";

/* ===============================
   VALIDAR SEMESTRE (COURSE)
=============================== */
$courseId = $_GET["course"] ?? null;

if (!$courseId) {
  die("Semestre no especificado");
}

/* ===============================
   OBTENER CURSO (SEMESTRE)
=============================== */
$stmtCourse = $pdo->prepare("
  SELECT * FROM courses
  WHERE id = :id
");
$stmtCourse->execute(["id" => $courseId]);
$course = $stmtCourse->fetch(PDO::FETCH_ASSOC);

if (!$course) {
  die("Semestre no válido");
}

/* ===============================
   CREAR UNIT
=============================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && !empty($_POST["unit_name"])) {

  $unitId = uniqid("unit_");

  $stmtInsert = $pdo->prepare("
    INSERT INTO units (id, name, course_id)
    VALUES (:id, :name, :course_id)
  ");

  $stmtInsert->execute([
    "id" => $unitId,
    "name" => trim($_POST["unit_name"]),
    "course_id" => $courseId
  ]);

  // 🔥 IMPORTANTE:
  // Después de crear vuelve al mismo semestre
  header("Location: technical_units.php?course=" . urlencode($courseId));
  exit;
}

/* ===============================
   LISTAR UNITS
=============================== */
$stmtUnits = $pdo->prepare("
  SELECT * FROM units
  WHERE course_id = :course
  ORDER BY position ASC, created_at ASC
");

$stmtUnits->execute([
  "course" => $courseId
]);

$units = $stmtUnits->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Units — <?= htmlspecialchars($course["name"]) ?></title>
<style>
body{font-family:Arial;background:#f4f8ff;padding:40px}
h1{color:#2563eb;margin-bottom:25px}
.card{background:#fff;padding:25px;border-radius:12px;margin-bottom:25px;max-width:800px}
.item{background:#fff;padding:15px;border-radius:10px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;box-shadow:0 4px 8px rgba(0,0,0,.08)}
a{text-decoration:none;color:#2563eb;font-weight:bold}
input{width:100%;padding:12px;margin-top:10px}
button{margin-top:15px;padding:12px 18px;background:#2563eb;color:#fff;border:none;border-radius:8px;font-weight:700}
.back{display:inline-block;margin-bottom:20px;background:#6b7280;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none}
</style>
</head>
<body>

<a class="back" href="courses_manager.php?program=prog_technical">
  ← Volver a semestres
</a>

<h1>📘 <?= htmlspecialchars($course["name"]) ?> — Units</h1>

<div class="card">
  <h2>➕ Crear Unit</h2>
  <form method="post">
    <input type="text" name="unit_name" required placeholder="Ej: Unit 1">
    <button>Crear Unit</button>
  </form>
</div>

<div class="card">
  <h2>📋 Units creadas</h2>

  <?php if (empty($units)): ?>
    <p>No hay units creadas.</p>
  <?php else: ?>
    <?php foreach ($units as $u): ?>
      <div class="item">
        <strong><?= htmlspecialchars($u["name"]) ?></strong>
        <a href="../activities/unit_view.php?unit=<?= urlencode($u['id']) ?>">
          Administrar →
        </a>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

</div>

</body>
</html>
