<?php
session_start();

/**
 * SELECCIÓN DE PROGRAMA
 * Paso 1 del flujo académico
 */

// 🔐 SOLO ADMIN
if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

/* ==========================
   DATA
   ========================== */
$baseDir = __DIR__ . "/data";
$file = $baseDir . "/programs.json";

if (!file_exists($file)) {
    file_put_contents($file, "[]");
}

$programs = json_decode(file_get_contents($file), true);
$programs = is_array($programs) ? $programs : [];

/* ==========================
   CONTINUAR
   ========================== */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $programId = $_POST["program_id"] ?? "";

    if ($programId) {
       header("Location: courses_manager.php?program=" . urlencode($programId));
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Seleccionar Programa</title>

<style>
body{
  font-family:Arial, sans-serif;
  background:#f4f8ff;
  padding:40px;
}
.card{
  background:white;
  padding:30px;
  border-radius:16px;
  max-width:520px;
  box-shadow:0 10px 25px rgba(0,0,0,.08);
}
h1{
  margin-top:0;
  color:#2563eb;
}
select{
  width:100%;
  padding:12px;
  margin-top:20px;
  font-size:16px;
}
button{
  margin-top:30px;
  width:100%;
  padding:14px;
  background:#2563eb;
  color:#fff;
  border:none;
  border-radius:10px;
  font-weight:700;
  font-size:16px;
  cursor:pointer;
}
</style>
</head>

<body>

<h1>🎓 Programas Académicos</h1>

<div class="card">
<form method="post">

  <select name="program_id" required>
    <option value="">Seleccionar programa</option>
    <?php foreach ($programs as $p): ?>
      <option value="<?= htmlspecialchars($p['id']) ?>">
        <?= htmlspecialchars($p['name']) ?>
      </option>
    <?php endforeach; ?>
  </select>

  <button>➡️ Siguiente</button>

</form>
</div>

</body>
</html>
