<?php
$unitsFile = __DIR__ . "/units.json";
$assignmentsFile = __DIR__ . "/assignments.json";

$units = file_exists($unitsFile)
  ? json_decode(file_get_contents($unitsFile), true)
  : [];

$assignments = file_exists($assignmentsFile)
  ? json_decode(file_get_contents($assignmentsFile), true)
  : [];

$activities = [
  "flashcards"     => "../activities/flashcards/viewer.php",
  "multiple_choice"=> "../activities/multiple_choice/viewer.php",
  "pronunciation"  => "../activities/pronunciation/viewer.php",
  "unscramble"     => "../activities/unscramble/viewer.php",
  "drag_drop"      => "../activities/drag_drop/viewer.php",
  "listen_order"   => "../activities/listen_order/viewer.php",
  "match"          => "../activities/match/viewer.php"
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {

  $unit_id = $_POST["unit_id"] ?? "";
  $activity = $_POST["activity"] ?? "";

  if ($unit_id && isset($activities[$activity])) {
    $assignments[] = [
      "id" => uniqid("asg_"),
      "unit_id" => $unit_id,
      "activity" => $activity,
      "path" => $activities[$activity]
    ];

    file_put_contents(
      $assignmentsFile,
      json_encode($assignments, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
  }

  header("Location: assignments_editor.php");
  exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Asignar Actividades</title>

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
  max-width:700px;
  box-shadow:0 10px 25px rgba(0,0,0,.08);
}

select{
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
  max-width:700px;
}

.item{
  background:#fff;
  padding:12px;
  border-radius:10px;
  margin-bottom:10px;
  box-shadow:0 4px 8px rgba(0,0,0,.08);
  font-size:14px;
}
</style>
</head>

<body>

<h1>🎯 Asignar Actividades a Unidades</h1>

<div class="card">
  <form method="post">

    <select name="unit_id" required>
      <option value="">Seleccionar unidad</option>
      <?php foreach ($units as $u): ?>
        <option value="<?= $u["id"] ?>">
          <?= htmlspecialchars($u["name"]) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <select name="activity" required>
      <option value="">Seleccionar actividad</option>
      <?php foreach ($activities as $k => $v): ?>
        <option value="<?= $k ?>">
          <?= ucfirst(str_replace("_"," ",$k)) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <button>➕ Asignar Actividad</button>

  </form>
</div>

<div class="list">
  <h2>📋 Actividades asignadas</h2>

  <?php foreach ($assignments as $a): ?>
    <?php
      $unitName = "";
      foreach ($units as $u) {
        if ($u["id"] === $a["unit_id"]) {
          $unitName = $u["name"];
          break;
        }
      }
    ?>
    <div class="item">
      <strong><?= htmlspecialchars($unitName) ?></strong> →
      <?= ucfirst(str_replace("_"," ",$a["activity"])) ?>
    </div>
  <?php endforeach; ?>
</div>

</body>
</html>
