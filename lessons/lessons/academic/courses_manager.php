<?php
session_start();

/**
 * COURSES MANAGER (POSTGRES VERSION)
 * Creación y gestión de cursos (SOLO ADMIN)
 */

// 🔐 SOLO ADMIN
if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
  header("Location: ../admin/login.php");
  exit;
}

/* ===============================
   DB CONNECTION
=============================== */
require __DIR__ . "/../config/db.php";

/* ===============================
   VALIDAR PROGRAMA
=============================== */
$programId = $_GET["program"] ?? null;
if (!$programId) {
  die("Programa no especificado");
}

/* ===============================
   OBTENER PROGRAMA DESDE JSON
=============================== */
$programsFile = __DIR__ . "/data/programs.json";

$programs = file_exists($programsFile)
  ? json_decode(file_get_contents($programsFile), true)
  : [];

$programName = null;
foreach ($programs as $p) {
  if ($p["id"] === $programId) {
    $programName = $p["name"];
    break;
  }
}

if (!$programName) {
  die("Programa inválido");
}

/* ===============================
   CREAR CURSO → POSTGRES
=============================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && !empty($_POST["course_name"])) {

  $courseId = uniqid("course_");

  $stmtInsert = $pdo->prepare("
      INSERT INTO courses (id, program_id, name)
      VALUES (:id, :program_id, :name)
  ");

  $stmtInsert->execute([
      "id" => $courseId,
      "program_id" => $programId,
      "name" => trim($_POST["course_name"])
  ]);

  header("Location: courses_manager.php?program=" . urlencode($programId));
  exit;
}

/* ===============================
   LISTAR CURSOS → POSTGRES
=============================== */
$stmt = $pdo->prepare("
  SELECT * FROM courses
  WHERE program_id = :program
  ORDER BY name ASC
");

$stmt->execute([
  "program" => $programId
]);

$programCourses = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Cursos</title>
<style>
body{font-family:Arial;background:#f4f8ff;padding:40px}
h1{color:#2563eb}
.card{background:#fff;padding:25px;border-radius:12px;margin-bottom:25px;max-width:600px}
.course{
  background:#fff;
  padding:15px;
  border-radius:10px;
  margin-bottom:10px;
  display:flex;
  justify-content:space-between;
  align-items:center;
  box-shadow:0 4px 8px rgba(0,0,0,.08)
}
a{text-decoration:none;color:#2563eb;font-weight:bold}
input{width:100%;padding:12px;margin-top:10px}
button{
  margin-top:15px;
  padding:12px 18px;
  background:#2563eb;
  color:#fff;
  border:none;
  border-radius:8px;
  font-weight:700;
  cursor:pointer;
}
button:hover{background:#1d4ed8}
</style>
</head>
<body>

<h1>📘 Cursos — <?= htmlspecialchars($programName) ?></h1>

<div class="card">
  <h2>➕ Crear curso</h2>
  <form method="post">
    <input type="text" name="course_name" required placeholder="Ej: Phase 1">
    <button>Crear curso</button>
  </form>
</div>

<div class="card">
  <h2>📋 Cursos creados</h2>

  <?php if (empty($programCourses)): ?>
    <p>No hay cursos creados.</p>
  <?php else: ?>
    <?php foreach ($programCourses as $c): ?>
      <div class="course">
        <strong><?= htmlspecialchars($c["name"]) ?></strong>
        <a href="course_view.php?course=<?= urlencode($c["id"]) ?>">
          Abrir →
        </a>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

</div>

</body>
</html>
