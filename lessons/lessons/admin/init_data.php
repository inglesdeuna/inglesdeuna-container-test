<?php
$base = "/var/www/html/data";

if (!is_dir($base)) {
  mkdir($base, 0777, true);
}

chmod($base, 0777);

$files = [
  "programs.json",
  "semesters.json",
  "modules.json",
  "units.json",
  "assignments.json"
];

foreach ($files as $f) {
  $path = "$base/$f";
  if (!file_exists($path)) {
    file_put_contents($path, "[]");
  }
  chmod($path, 0666);
}

echo "OK";
