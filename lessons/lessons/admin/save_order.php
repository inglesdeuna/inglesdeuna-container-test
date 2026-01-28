<?php
$file = __DIR__ . "/external_links.json";
$data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];

$order = json_decode(file_get_contents("php://input"), true);
$new = [];

foreach ($order as $i) {
  if (isset($data[$i])) {
    $new[] = $data[$i];
  }
}

file_put_contents($file, json_encode($new, JSON_PRETTY_PRINT));
