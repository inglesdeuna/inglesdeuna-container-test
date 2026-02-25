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

if ($_SERVER["REQUEST_METHOD"] === "POST" && !empty($_POST["course_name"])) {

  $name = strtoupper(trim($_POST["course_name"]));

  $allowedSemesters = [
    "SEMESTRE 1",
    "SEMESTRE 2",
    "SEMESTRE 3",
    "SEMESTRE 4"
  ];

  if (!in_array($name, $allowedSemesters)) {
    die("Semestre inválido.");
  }

  /* Buscar si ya existe */
  $check = $pdo->prepare("
      SELECT id FROM courses
      WHERE program_id = :program_id
      AND name = :name
      LIMIT 1
  ");

  $check->execute([
      "program_id" => $programId,
      "name" => $name
  ]);

  $existing = $check->fetch(PDO::FETCH_ASSOC);

  if ($existing) {
      $courseId = $existing["id"];
  } else {
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
  }

  /* SIEMPRE REDIRIGE A CREAR UNITS */
  header("Location: technical_units.php?course=" . urlencode($courseId));
  exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Programa Técnico</title>
<style>
body{font-family:Arial;background:#f4f8ff;padding:40px}
h1{color:#2563eb;margin-bottom:25px}
.card{background:#fff;padding:25px;border-radius:12px;max-width:800px}
select{width:100%;padding:12px;margin-top:10px}
button{margin-top:15px;padding:12px 18px;background:#2563eb;color:#fff;border:none;border-radius:8px;font-weight:700}
.back{display:inline-block;margin-bottom:20px;background:#6b7280;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none}
</style>
</head>
<body>

<a class="back" href="../admin/dashboard.php">
  ← Volver al Dashboard
</a>

<h1>📘 Programa Técnico</h1>

<div class="card">
  <h2>➕ Crear / Acceder a Semestre</h2>
  <form method="post">
    <select name="course_name" required>
      <option value="">Seleccionar semestre</option>
      <option>SEMESTRE 1</option>
      <option>SEMESTRE 2</option>
      <option>SEMESTRE 3</option>
      <option>SEMESTRE 4</option>
    </select>
    <button>Continuar</button>
  </form>
</div>

</body>
</html>
