<?php
session_start();

/* =====================
   REQUIERE LOGIN
   ===================== */
if (!isset($_SESSION["teacher_id"])) {
  header("Location: login.php");
  exit;
}

$teacherId = $_SESSION["teacher_id"];

/* =====================
   ARCHIVO DOCENTES
   ===================== */
$dataDir = __DIR__ . '/data';
$teachersFile = $dataDir . '/teachers.json';
$legacyTeachersFile = __DIR__ . '/teachers.json';

if (!is_dir($dataDir)) {
  mkdir($dataDir, 0777, true);
}

if (!file_exists($teachersFile) && file_exists($legacyTeachersFile)) {
  copy($legacyTeachersFile, $teachersFile);
}

if (!file_exists($teachersFile)) {
  file_put_contents($teachersFile, '[]');
}

$teachers = json_decode((string) file_get_contents($teachersFile), true);
if (!is_array($teachers)) {
  $teachers = [];
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
body{
  font-family: Arial, Helvetica, sans-serif;
  background:#f4f8ff;
  padding:40px;
}
.section{
  background:#fff;
  padding:25px;
  border-radius:14px;
  max-width:600px;
  margin:auto;
}
ul{margin-top:15px}
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

  <p style="margin-top:25px">
    <a href="courses.php">📚 Volver a Mis cursos</a> |
    <a href="logout.php">🚪 Cerrar sesión</a>
  </p>
</div>

</body>
</html>
