<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
  header("Location: ../admin/login.php");
  exit;
}

require __DIR__ . "/../config/db.php";

/* ===============================
   VALIDAR PROGRAMA
=============================== */
$programId = $_GET["program"] ?? null;

if (!$programId) {
  die("Programa no especificado");
}

/* ===============================
   CREAR SEMESTRE (SOLO 1-4)
=============================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && !empty($_POST["course_name"])) {

  $allowedSemesters = [
    "SEMESTRE 1",
    "SEMESTRE 2",
    "SEMESTRE 3",
    "SEMESTRE 4"
  ];

  $name = strtoupper(trim($_POST["course_name"]));

  /* Validar que sea uno permitido */
  if (!in_array($name, $allowedSemesters)) {
    die("Solo se permiten: SEMESTRE 1, 2, 3 o 4.");
  }

  /* Validar que no exista */
  $check = $pdo->prepare("
      SELECT COUNT(*) FROM courses
      WHERE program_id = :program_id
      AND name = :name
  ");

  $check->execute([
      "program_id" => $programId,
      "name" => $name
  ]);

  if ($check->fetchColumn() > 0) {
      die("Ese semestre ya existe.");
  }

  $courseId = uniqid("course_");

  $stmt = $pdo->prepare("
      INSERT INTO courses (id, program_id, name)
      VALUES (:id, :program_id, :name)
  ");

  $stmt->execute([
      "id" => $courseId,
      "program_id" => $programId,
      "name" => $name
  ]);

  header("Location: courses_manager.php?program=" . urlencode($programId));
  exit;
}

/* ===============================
   TÍTULO
=============================== */
$title = $programId === "prog_technical"
  ? "Programa Técnico"
  : "Cursos de Inglés";
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($title) ?></title>

<style>
body{
  font-family:Arial;
  background:#f4f8ff;
  padding:40px
}
h1{
  color:#2563eb;
  margin-bottom:25px
}
.card{
  background:#fff;
  padding:25px;
  border-radius:12px;
  margin-bottom:25px;
  max-width:800px
}
select{
  width:100%;
  padding:12px;
  margin-top:10px
}
button{
  margin-top:15px;
  padding:12px 18px;
  background:#2563eb;
  color:#fff;
  border:none;
  border-radius:8px;
  font-weight:700;
  cursor:pointer
}
button:hover{
  opacity:.9
}
.back{
  display:inline-block;
  margin-bottom:20px;
  background:#6b7280;
  color:#fff;
  padding:10px 18px;
  border-radius:8px;
  text-decoration:none
}
</style>
</head>

<body>

<a class="back" href="../admin/dashboard.php">
  ← Volver al Dashboard
</a>

<h1>📘 <?= htmlspecialchars($title) ?></h1>

<div class="card">
  <h2>➕ Crear Semestre</h2>

  <form method="post">
    <select name="course_name" required>
      <option value="">Seleccionar semestre</option>
      <option>SEMESTRE 1</option>
      <option>SEMESTRE 2</option>
      <option>SEMESTRE 3</option>
      <option>SEMESTRE 4</option>
    </select>

    <button>Crear</button>
  </form>
</div>

</body>
</html>
