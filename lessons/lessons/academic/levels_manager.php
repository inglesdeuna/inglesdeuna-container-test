<?php
$programsFile = __DIR__ . "/programs.json";

$programs = file_exists($programsFile)
  ? json_decode(file_get_contents($programsFile), true)
  : [];

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
  margin-bottom:20px;
}

.card{
  background:#ffffff;
  padding:20px;
  border-radius:14px;
  max-width:500px;
  box-shadow:0 10px 25px rgba(0,0,0,.08);
}

select{
  width:100%;
  padding:12px;
  font-size:15px;
}
</style>
</head>

<body>

<h1>📘 Levels Manager</h1>

<div class="card">
  <label><strong>Programa</strong></label>
  <select>
    <option value="">Seleccionar programa</option>

    <?php foreach ($programs as $p): ?>
      <option value="<?= $p["id"] ?>">
        <?= htmlspecialchars($p["name"]) ?>
      </option>
    <?php endforeach; ?>

  </select>
</div>

</body>
</html>
