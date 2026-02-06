<?php
session_start();

/* PARAMETROS */
$courseId = $_GET["course"] ?? null;
$unitId   = $_GET["unit"] ?? null;
if (!$courseId || !$unitId) die("Curso o unidad no especificados");

/* ARCHIVO CORRECTO */
$file = dirname(__DIR__) . "/academic/courses.json";
$courses = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
if (!is_array($courses)) $courses = [];

/* BUSCAR CURSO Y UNIDAD */
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
  die("Curso no encontrado");
}

/* GUARDAR ACTIVIDAD */
if ($_SERVER["REQUEST_METHOD"] === "POST" && !empty($_POST["word"])) {

  $courses[$courseIndex]["units"][$unitIndex]["activities"][] = [
    "type" => "hangman",
    "data" => [
      "word" => trim($_POST["word"])
    ]
  ];

  file_put_contents($file, json_encode($courses, JSON_PRETTY_PRINT));
  header("Location: index.php?course=" . urlencode($courseId) . "&unit=" . urlencode($unitId));
  exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Hangman Editor</title>
</head>
<body>

<h2>🎯 Hangman – Editor</h2>

<form method="post">
  <input type="text" name="word" required placeholder="Palabra">
  <button>Guardar actividad</button>
</form>

</body>
</html>
