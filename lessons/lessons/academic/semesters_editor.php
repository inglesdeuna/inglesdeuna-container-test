<?php
/* ===== RUTAS SEGURAS ===== */
$baseDir = "/var/www/html/lessons/data";
$programsFile  = $baseDir . "/programs.json";
$semestersFile = $baseDir . "/semesters.json";

/* ===== ASEGURAR ARCHIVOS ===== */
if (!is_dir($baseDir)) {
  mkdir($baseDir, 0777, true);
}
if (!file_exists($programsFile)) {
  file_put_contents($programsFile, "[]");
}
if (!file_exists($semestersFile)) {
  file_put_contents($semestersFile, "[]");
}

/* ===== CARGAR DATOS ===== */
$programs  = json_decode(file_get_contents($programsFile), true) ?? [];
$semesters = json_decode(file_get_contents($semestersFile), true) ?? [];

/* ===== GUARDAR ===== */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $program_id = $_POST["program_id"] ?? "";
  $name       = trim($_POST["name"] ?? "");

  if ($program_id && $name !== "") {
    $semesters[] = [
      "id"         => uniqid("sem_"),
      "program_id"=> $program_id,
      "name"       => $name
    ];

    file_put_contents(
      $semestersFile,
      json_encode($semesters, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
  }

  header("Location: semesters_editor.php");
  exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Semestres</title>

<style>
body{
  font-family: Arial, Helvetica, sans-serif;
  background:#f4f8ff;
  padding:40px;
}

h1{ color:#2563eb; }

.card{
  background:white;
  padding:20px;
  border-radius:14px;
  max-width:600px;
  box-shadow:0 10px 25px rgba(0,0,0,.08);
}

input, select{
  width:100%;
  padding:10px;
  margin-top:10px;
}

button{
  margin-top:15px;
  padding:12px 18px;
  border:none;
  border-radius:10px;
  background:#2563eb;
  color:white;
  font-weight:bold;
  cursor:pointer;
}

.list{
  margin-top:30px;
  max-width:600px;
}

.item{
  background:#fff;
  padding:12px;
  border-radius:10px;
  margin-bottom:10px;
  box-shadow:0 4px 8px rgba(0,0,0,.08);
}
</style>
</head>

<body>

<h1>📂 Semestres</h1>

<div class="card">
  <form method="post">

    <select name="program_id" required>
      <option value="">Seleccionar programa</option>
      <?php foreach ($programs as $p): ?>
        <option value="<?= htmlspecialchars($p["id"]) ?>">
          <?= htmlspecialchars($p["name"]) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <input type="text" name="name"
      placeholder="Nombre del semestre (ej: A, B, 1, 2)" required>

    <button>➕ Crear Semestre</button>
  </form>
</div>

<div class="list">
  <h2>📋 Semestres creados</h2>

  <?php foreach ($semesters as $s): ?>
    <div class="item">
      <strong>Semestre <?= htmlspecialchars($s["name"]) ?></strong>
    </div>
  <?php endforeach; ?>

</div>

</body>
</html>
