<?php
$file = "words.json";

$data = json_decode(file_get_contents($file), true);

$word = strtoupper(trim($_POST["word"]));
$hint = trim($_POST["hint"]);

$data["default"][] = [
  "word" => $word,
  "hint" => $hint
];

file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));

header("Location: admin.php");
exit;
