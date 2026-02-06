<?php
$coursesFile = __DIR__ . "/courses.json";

$courses = file_exists($coursesFile)
  ? json_decode(file_get_contents($coursesFile), true)
  : [];

foreach ($courses as &$c) {

  // students
  if (!isset($c["students"]) || !is_array($c["students"])) {
    $c["students"] = [];
  }

  // units
  if (!isset($c["units"]) || !is_array($c["units"])) {
    $c["units"] = [];
  }

  // teacher
  if (isset($c["teacher"]) && is_string($c["teacher"])) {
    $c["teacher"] = [
      "id" => $c["teacher"],
      "permission" => "editor"
    ];
  }

  if (!isset($c["teacher"]) || !is_array($c["teacher"])) {
    $c["teacher"] = null;
  }
}

file_put_contents($coursesFile, json_encode($courses, JSON_PRETTY_PRINT));

echo "Cursos normalizados correctamente";
