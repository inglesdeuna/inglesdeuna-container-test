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
   CREAR CURSO / SEMESTRE
=============================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && !empty($_POST["course_name"])) {

  $courseId = uniqid("course_");

  $stmt = $pdo->prepare("
      INSERT INTO courses (id, program_id, name)
      VALUES (:id, :program_id, :name)
  ");

  $stmt->execute([
      "id" => $courseId,
      "program_id" => $programId,
      "name" => strtoupper(trim($_POST["course_name"]))
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

<a class="back" href="../admin/dashboard.php">
  ← Volver al Dashboard
</a>

<h1>📘 <?= htmlspecialchars($title) ?></h1>

</body>
</html>
