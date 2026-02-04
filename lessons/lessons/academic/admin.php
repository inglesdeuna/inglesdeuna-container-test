<?php
session_start();

/* REQUIERE LOGIN */
if (!isset($_SESSION["teacher_id"])) {
  header("Location: login.php");
  exit;
}

$teacherId = $_SESSION["teacher_id"];

/* ARCHIVO DOCENTES */
$teachersFile = __DIR__ . "/teachers.json";
$teachers = file_exists($teachersFile)
  ? json_decode(file_get_contents($teachersFile), true)
  : [];

/* VALIDAR ADMIN */
$isAdmin = false;
$adminName = "";

foreach ($teachers as $t) {
  if (($t["id"] ?? null) === $teacherId && ($t["role"] ?? "") === "admin") {
    $isAdmin = true;
    $adminName = $t["name"];
    break;
  }
}

if (!$isAdmin) {
  die("Acceso restringido");
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Panel Administrador</title>
<style>
body{font-family:Arial;background:#f4f8ff;padding:40px}
.section{background:#fff;padding:25px;border-radius:14px;max-width:600px;margin:auto}
</style>
</head>
<body>

<div class="section">
  <h1>👑 Panel Administrador</h1>
  <p>Administrador: <strong><?= htmlspecialchars($adminName) ?></strong></p>

  <ul>
    <li>✔ Acceso administrador validado</li>
    <li>✔ Login protegido</li>
    <li>✔ Rol admin activo</li>
  </ul>

  <p style="margin-top:20px">
    <a href="courses.php">📚 Volver a Mis cursos</a> |
    <a href="logout.php">🚪 Cerrar sesión</a>
  </p>
</div>

</body>
</html>
