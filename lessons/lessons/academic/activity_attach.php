<?php
/* =====================
   REGISTRAR ACTIVIDAD EN UNIDAD
   ===================== */

$unitId  = $_POST['unit']  ?? null;
$type    = $_POST['type']  ?? null;
$title   = $_POST['title'] ?? '';
$viewer  = $_POST['viewer'] ?? null;

if (!$unitId || !$type || !$viewer) {
  die("Datos incompletos");
}

/* Ruta REAL a data */
$baseDir   = dirname(__DIR__) . "/admin/data";
$unitsFile = $baseDir . "/units.json";

if (!file_exists($unitsFile)) {
  die("Archivo de unidades no encontrado");
}

$units = json_decode(file_get_contents($unitsFile), true);
if (!is_array($units)) $units = [];

/* Buscar unidad */
$unitIndex = null;
foreach ($units as $i => $u) {
  if (($u['id'] ?? null) === $unitId) {
    $unitIndex = $i;
    break;
  }
}

if ($unitIndex === null) {
  die("Unidad no encontrada");
}

/* Asegurar activities */
if (
  !isset($units[$unitIndex]['activities']) ||
  !is_array($units[$unitIndex]['activities'])
) {
  $units[$unitIndex]['activities'] = [];
}

/* Registrar actividad */
$units[$unitIndex]['activities'][] = [
  'id'     => uniqid('act_'),
  'type'   => $type,
  'title'  => $title,
  'viewer' => $viewer
];

file_put_contents(
  $unitsFile,
  json_encode($units, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

/* Volver al hub */
header("Location: /lessons/lessons/activities/hub/index.php?unit=" . urlencode($unitId));
exit;
