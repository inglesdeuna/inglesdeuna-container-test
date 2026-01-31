<?php
$programsFile = __DIR__ . "/programs.json";
$semestersFile = __DIR__ . "/semesters.json";

$programs = file_exists($programsFile)
  ? json_decode(file_get_contents($programsFile), true)
  : [];

$semesters = file_exists($semestersFile)
  ? json_decode(file_get_contents($semestersFile), true)
  : [];

if ($_SERVER["REQUEST_METHOD"] === "POST") {

  $program_id = $_POST["program_id"] ?? "";
  $name = trim($_POST["name"] ?? "");

  if ($program_id && $name) {
    $semesters[] = [
      "id" => uniqid("sem_"),
      "program_id" => $program_id,
      "name" => $name
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

<h1>📚 Semestres</h1>

<div class="card">
  <form method="post">

    <select name="program_id" required>
      <option value="">Seleccionar programa</option>
      <?php foreach ($programs as $p): ?>
        <option value="<?= $p["id"] ?>">
          <?= htmlspecialchars($p["name"]) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <input type="text" name="name"
      placeholder="Nombre del semestre (A, B, 1, 2, etc.)"
      required>

    <button>➕ Crear Semestre</button>

  </form>
</div>

<div class="list">
  <h2>📋 Semestres creados</h2>

  <?php foreach ($semesters as $s): ?>
    <?php
      $progName = "";
      foreach ($programs as $p) {
        if ($p["id"] === $s["program_id"]) {
          $progName = $p["name"];
          break;
        }
      }
    ?>
    <div class="item">
      <strong><?= htmlspecialchars($s["name"]) ?></strong>
      <div class="small">
        Programa: <?= htmlspecialchars($progName) ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>

</body>
</html>
