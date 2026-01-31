<?php
$programsFile = __DIR__ . "/programs.json";
$semestersFile = __DIR__ . "/semesters.json";
$modulesFile = __DIR__ . "/modules.json";
$unitsFile = __DIR__ . "/units.json";

$programs = file_exists($programsFile)
  ? json_decode(file_get_contents($programsFile), true)
  : [];

$semesters = file_exists($semestersFile)
  ? json_decode(file_get_contents($semestersFile), true)
  : [];

$modules = file_exists($modulesFile)
  ? json_decode(file_get_contents($modulesFile), true)
  : [];

$units = file_exists($unitsFile)
  ? json_decode(file_get_contents($unitsFile), true)
  : [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {

  $module_id = $_POST["module_id"] ?? "";
  $name = trim($_POST["name"] ?? "");

  if ($module_id && $name) {
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
  font-family:Arial, Helvetica, sans-serif;
  background:#f4f8ff;
  padding:40px;
}

h1{color:#2563eb;}

.card{
  background:white;
  padding:20px;
  border-radius:14px;
  max-width:650px;
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
  max-width:650px;
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

<h1>📗 Unidades</h1>

<div class="card">
  <form method="post">

    <select name="module_id" required>
      <option value="">Seleccionar módulo</option>

      <?php foreach ($modules as $m): ?>
        <?php
          $semesterName = "";
          $programName = "";

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
        <option value="<?= $m["id"] ?>">
          <?= htmlspecialchars($programName) ?> — Sem <?= htmlspecialchars($semesterName) ?> — <?= htmlspecialchars($m["name"]) ?>
        </option>
      <?php endforeach; ?>

    </select>

    <input type="text" name="name"
      placeholder="Nombre de la unidad (Unit 1, Classroom Commands, etc.)"
      required>

    <button>➕ Crear Unidad</button>

  </form>
</div>

<div class="list">
  <h2>📋 Unidades creadas</h2>

  <?php foreach ($units as $u): ?>
    <?php
      $moduleName = "";
      $semesterName = "";
      $programName = "";

      foreach ($modules as $m) {
        if ($m["id"] === $u["module_id"]) {
          $moduleName = $m["name"];
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
          break;
        }
      }
    ?>
    <div class="item">
      <strong><?= htmlspecialchars($u["name"]) ?></strong>
      <div class="small">
        <?= htmlspecialchars($programName) ?> — Sem <?= htmlspecialchars($semesterName) ?> — <?= htmlspecialchars($moduleName) ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>

</body>
</html>
