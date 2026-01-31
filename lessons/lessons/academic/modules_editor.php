<?php
$programsFile  = __DIR__ . "/programs.json";
$semestersFile = __DIR__ . "/semesters.json";
$modulesFile   = __DIR__ . "/modules.json";

$programs = file_exists($programsFile)
  ? json_decode(file_get_contents($programsFile), true)
  : [];

$semesters = file_exists($semestersFile)
  ? json_decode(file_get_contents($semestersFile), true)
  : [];

$modules = file_exists($modulesFile)
  ? json_decode(file_get_contents($modulesFile), true)
  : [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {

  $semester_id = $_POST["semester_id"] ?? "";
  $name = trim($_POST["name"] ?? "");

  if ($semester_id && $name) {
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
<title>Módulos / Niveles</title>

<style>
body{
  font-family:Arial, Helvetica, sans-serif;
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

select, input{
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
.small{
  font-size:13px;
  color:#555;
}
</style>
</head>

<body>

<h1>📘 Módulos / Niveles</h1>

<div class="card">
  <form method="post">

    <select name="semester_id" required>
      <option value="">Seleccionar semestre</option>
      <?php foreach ($semesters as $s): ?>
        <?php
          $programName = "";
          foreach ($programs as $p) {
            if ($p["id"] === $s["program_id"]) {
              $programName = $p["name"];
              break;
            }
          }
        ?>
        <option value="<?= $s["id"] ?>">
          <?= htmlspecialchars($programName) ?> — Semestre <?= htmlspecialchars($s["name"]) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <input type="text" name="name"
      placeholder="Nombre del módulo (Preschool, Technical English, etc.)"
      required>

    <button>➕ Crear Módulo</button>

  </form>
</div>

<div class="list">
  <h2>📋 Módulos creados</h2>

  <?php foreach ($modules as $m): ?>
    <?php
      $semesterName = "";
      $programName  = "";

      foreach ($semesters as $s) {
        if ($s["id"] === $m["semester_id"]) {
          $semesterName = $s["name"];
          foreach ($programs as $p) {
            if ($p["id"] === $s["program_id"]) {
              $programName = $p["name"];
              break;
            }
          }
          break;
        }
      }
    ?>
    <div class="item">
      <strong><?= htmlspecialchars($m["name"]) ?></strong>
      <div class="small">
        <?= htmlspecialchars($programName) ?> — Semestre <?= htmlspecialchars($semesterName) ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>

</body>
</html>
