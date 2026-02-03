<?php
/* ===== RUTAS SEGURAS ===== */
$baseDir = "/var/www/html/lessons/data";
$modulesFile = $baseDir . "/modules.json";
$unitsFile   = $baseDir . "/units.json";

/* ===== ASEGURAR DATA ===== */
if (!is_dir($baseDir)) {
  mkdir($baseDir, 0777, true);
}
foreach ([$modulesFile, $unitsFile] as $f) {
  if (!file_exists($f)) file_put_contents($f, "[]");
}

/* ===== CARGAR DATOS ===== */
$modules = json_decode(file_get_contents($modulesFile), true) ?? [];
$units   = json_decode(file_get_contents($unitsFile), true) ?? [];

/* ===== GUARDAR ===== */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $module_id = $_POST["module_id"] ?? "";
  $name = trim($_POST["name"] ?? "");

  if ($module_id && $name !== "") {
    $units[] = [
      "id" => uniqid("unit_"),
      "module_id" => $module_id,
      "name" => $name
    ];

    file_put_contents(
      $unitsFile,
      json_encode($units, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
  }

  header("Location: units_editor.php");
  exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Unidades</title>

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

<h1>📚 Unidades</h1>

<div class="card">
  <form method="post">

    <select name="module_id" required>
      <option value="">Seleccionar módulo</option>
      <?php foreach ($modules as $m): ?>
        <option value="<?= htmlspecialchars($m["id"]) ?>">
          <?= htmlspecialchars($m["name"]) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <input type="text" name="name"
      placeholder="Nombre de la unidad (ej: Unit 1 – Commands)" required>

    <button>➕ Crear Unidad</button>

  </form>
</div>

<div class="list">
  <h2>📋 Unidades creadas</h2>

  <?php foreach ($units as $u): ?>
    <div class="item">
      <strong><?= htmlspecialchars($u["name"]) ?></strong>
    </div>
  <?php endforeach; ?>
</div>
<hr style="margin:40px 0">

<div style="text-align:right">
  <a href="assignments_editor.php"
     style="
       padding:14px 24px;
       background:#2563eb;
       color:#fff;
       text-decoration:none;
       border-radius:10px;
       font-weight:700;
       font-size:16px;
     ">
    ➡️ Siguiente: Asignaciones
  </a>
</div>

</body>
</html>
