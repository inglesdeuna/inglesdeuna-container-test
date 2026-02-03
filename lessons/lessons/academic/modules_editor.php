<?php
/* ===== RUTAS SEGURAS ===== */
$baseDir = "/var/www/html/lessons/data";
$programsFile = $baseDir . "/programs.json";
$semestersFile = $baseDir . "/semesters.json";
$modulesFile   = $baseDir . "/modules.json";

/* ===== ASEGURAR DATA ===== */
if (!is_dir($baseDir)) {
  mkdir($baseDir, 0777, true);
}
foreach ([$programsFile, $semestersFile, $modulesFile] as $f) {
  if (!file_exists($f)) file_put_contents($f, "[]");
}

/* ===== CARGAR DATOS ===== */
$programs  = json_decode(file_get_contents($programsFile), true) ?? [];
$semesters = json_decode(file_get_contents($semestersFile), true) ?? [];
$modules   = json_decode(file_get_contents($modulesFile), true) ?? [];

/* ===== GUARDAR ===== */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $semester_id = $_POST["semester_id"] ?? "";
  $name = trim($_POST["name"] ?? "");

  if ($semester_id && $name !== "") {
    $modules[] = [
      "id" => uniqid("mod_"),
      "semester_id" => $semester_id,
      "name" => $name
    ];
    file_put_contents(
      $modulesFile,
      json_encode($modules, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
  }
  header("Location: modules_editor.php");
  exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Módulos</title>

<style>
body{
  font-family: Arial, Helvetica, sans-serif;
  background:#f4f8ff;
  padding:40px;
}
h1{color:#2563eb;}
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

<h1>📦 Módulos</h1>

<div class="card">
  <form method="post">

    <select name="semester_id" required>
      <option value="">Seleccionar semestre</option>
      <?php foreach ($semesters as $s): ?>
        <option value="<?= htmlspecialchars($s["id"]) ?>">
          <?= htmlspecialchars($s["name"]) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <input type="text" name="name"
      placeholder="Nombre del módulo (ej: Inglés Técnico, Grammar)" required>

    <button>➕ Crear Módulo</button>
  </form>
</div>

<div class="list">
  <h2>📋 Módulos creados</h2>

  <?php foreach ($modules as $m): ?>
    <div class="item">
      <strong><?= htmlspecialchars($m["name"]) ?></strong>
    </div>
  <?php endforeach; ?>
</div>
<hr style="margin:40px 0">

<div style="text-align:right">
  <a href="units_editor.php"
     style="
       padding:14px 24px;
       background:#2563eb;
       color:#fff;
       text-decoration:none;
       border-radius:10px;
       font-weight:700;
       font-size:16px;
     ">
    ➡️ Siguiente: Unidades
  </a>
</div>

</body>
</html>
