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

$program_id = $_GET["program"] ?? "";
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Levels Manager</title>

<style>
body{
  font-family: Arial, Helvetica, sans-serif;
  background:#f4f8ff;
  padding:40px;
}

h1{
  color:#2563eb;
  margin-bottom:25px;
}

.card{
  background:#ffffff;
  padding:20px;
  border-radius:14px;
  max-width:600px;
  box-shadow:0 10px 25px rgba(0,0,0,.08);
  margin-bottom:20px;
}

select{
  width:100%;
  padding:12px;
  font-size:15px;
}

.list{
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

<h1>📘 Levels Manager</h1>

<!-- ===== PROGRAMA ===== -->
<div class="card">
  <form method="get">
    <label><strong>Programa</strong></label>
    <select name="program" onchange="this.form.submit()">
      <option value="">Seleccionar programa</option>

      <?php foreach ($programs as $p): ?>
        <option value="<?= htmlspecialchars($p["id"]) ?>"
          <?= $program_id === $p["id"] ? "selected" : "" ?>>
          <?= htmlspecialchars($p["name"]) ?>
        </option>
      <?php endforeach; ?>

    </select>
  </form>
</div>

<!-- ===== SEMESTRES ===== -->
<?php if ($program_id): ?>
  <div class="list">
    <h2>📂 Semestres</h2>

    <?php
    $has = false;
    foreach ($semesters as $s):
      if (($s["program_id"] ?? "") === $program_id):
        $has = true;
    ?>
      <div class="item">
        <strong>Semestre <?= htmlspecialchars($s["name"]) ?></strong>
      </div>
    <?php
      endif;
    endforeach;

    if (!$has):
    ?>
      <p>No hay semestres para este programa.</p>
    <?php endif; ?>

  </div>
<?php endif; ?>

</body>
</html>
