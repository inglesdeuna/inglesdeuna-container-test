<?php
session_start();

/* =====================
   VALIDAR PARAMETROS
   ===================== */
$courseId = $_GET["course"] ?? null;
$unitId   = $_GET["unit"] ?? null;

if (!$courseId || !$unitId) {
  die("Curso o unidad no especificados");
}

/* =====================
   ARCHIVO DE CURSOS
   ===================== */
$file = dirname(__DIR__) . "/academic/courses.json";
$courses = file_exists($file)
  ? json_decode(file_get_contents($file), true)
  : [];

if (!is_array($courses)) {
  $courses = [];
}

/* =====================
   BUSCAR CURSO Y UNIDAD
   ===================== */
$courseIndex = null;
$unitIndex = null;

foreach ($courses as $ci => $c) {
  if (($c["id"] ?? null) === $courseId) {
    $courseIndex = $ci;

    foreach ($c["units"] as $ui => $u) {
      if (($u["id"] ?? null) === $unitId) {
        $unitIndex = $ui;
        break;
      }
    }
    break;
  }
}

if ($courseIndex === null || $unitIndex === null) {
  die("Curso o unidad no encontrados");
}

/* =====================
   GUARDAR ACTIVIDAD
   ===================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["save_hangman"])) {

  $word = trim($_POST["word"] ?? "");

  if ($word !== "") {
    $courses[$courseIndex]["units"][$unitIndex]["activities"][] = [
      "type" => "hangman",
      "data" => [
        "word" => $word
      ]
    ];

    file_put_contents($file, json_encode($courses, JSON_PRETTY_PRINT));
  }

  header("Location: index.php?course=" . urlencode($courseId) . "&unit=" . urlencode($unitId));
  exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Hangman Editor</title>
<style>
body{font-family:Arial;background:#f4f8ff;padding:40px}
.card{background:#fff;padding:25px;border-radius:12px;max-width:500px}
input,button{padding:10px;width:100%;margin-top:10px}
</style>
</head>
<body>

<div class="card">
  <h2>🎯 Hangman – Editor</h2>

  <form method="post">
    <input type="text" name="word" placeholder="Palabra" required>
    <button name="save_hangman">Guardar actividad</button>
  </form>
</div>

</body>
</html>
