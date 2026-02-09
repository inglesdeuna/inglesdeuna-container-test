<?php
session_start();

/* =====================
   VALIDAR UNIDAD
   ===================== */
$unitId = $_GET["unit"] ?? null;
if (!$unitId) {
  die("Unidad no especificada");
}

/* =====================
   DATA REAL (ÚNICA)
   ===================== */
$baseDir   = dirname(__DIR__, 3) . "/admin/data";
$unitsFile = $baseDir . "/units.json";

if (!file_exists($unitsFile)) {
  die("Archivo de unidades no encontrado");
}

$units = json_decode(file_get_contents($unitsFile), true) ?? [];

/* =====================
   BUSCAR UNIDAD
   ===================== */
$unitIndex = null;

foreach ($units as $i => $u) {
  if (($u["id"] ?? null) === $unitId) {
    $unitIndex = $i;
    break;
  }
}

if ($unitIndex === null) {
  die("Unidad no encontrada");
}

/* =====================
   ASEGURAR ACTIVIDADES
   ===================== */
if (!isset($units[$unitIndex]["activities"])) {
  $units[$unitIndex]["activities"] = [];
}

/* =====================
   GUARDAR PALABRA
   ===================== */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

  $word = trim($_POST["word"] ?? "");
  $hint = trim($_POST["hint"] ?? "");

  if ($word !== "") {
    $units[$unitIndex]["activities"][] = [
      "id"   => uniqid("hangman_"),
      "type" => "hangman",
      "data" => [
        "word" => $word,
        "hint" => $hint
      ]
    ];

    file_put_contents(
      $unitsFile,
      json_encode($units, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
  }

  // quedarse en el editor
  header("Location: editor.php?unit=" . urlencode($unitId));
  exit;
}

/* =====================
   ACTIVIDADES EXISTENTES
   ===================== */
$activities = array_filter(
  $units[$unitIndex]["activities"],
  fn($a) => ($a["type"] ?? "") === "hangman"
);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Hangman – Editor</title>
<style>
body{
  font-family:Arial;
  background:#f4f8ff;
  padding:40px;
}
.box{
  background:#fff;
  padding:25px;
  border-radius:14px;
  max-width:500px;
  box-shadow:0 10px 25px rgba(0,0,0,.1);
}
input, button{
  width:100%;
  padding:10px;
  margin-top:8px;
}
button{
  background:#2563eb;
  color:#fff;
  border:none;
  border-radius:8px;
  font-weight:bold;
  cursor:pointer;
}
.item{
  background:#eef2ff;
  padding:8px;
  border-radius:6px;
  margin-top:6px;
}
.actions{
  margin-top:20px;
  display:flex;
  justify-content:space-between;
}
.actions a{
  text-decoration:none;
  color:white;
  padding:10px 16px;
  border-radius:8px;
  background:#16a34a;
}
</style>
</head>

<body>

<div class="box">
  <h2>🎯 Hangman – Editor</h2>

  <form method="post">
    <input name="word" placeholder="Palabra" required>
    <input name="hint" placeholder="Pista (opcional)">
    <button>➕ Guardar palabra</button>
  </form>

  <hr>

  <h3>Palabras guardadas</h3>

  <?php if (empty($activities)): ?>
    <p>No hay palabras aún.</p>
  <?php else: ?>
    <?php foreach ($activities as $a): ?>
      <div class="item">
        <strong><?= htmlspecialchars($a["data"]["word"]) ?></strong><br>
        <small><?= htmlspecialchars($a["data"]["hint"] ?? "") ?></small>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <div class="actions">
    <a href="../hub/index.php?unit=<?= urlencode($unitId) ?>">⬅ Volver al Hub</a>
  </div>
</div>

</body>
</html>

