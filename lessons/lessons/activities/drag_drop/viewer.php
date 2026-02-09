<?php
$unit = $_GET['unit'] ?? null;
if (!$unit) die("Unidad no especificada");

$file = __DIR__ . "/drag_drop.json";
$data = file_exists($file)
  ? json_decode(file_get_contents($file), true)
  : [];

if (!isset($data[$unit]) || empty($data[$unit])) {
  die("No hay actividades para esta unidad");
}

$sentences = array_map(fn($s)=>$s["sentence"], $data[$unit]);
shuffle($sentences);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Build the Sentence</title>

<style>
body{
  font-family: Arial, sans-serif;
  background:#eef6ff;
  text-align:center;
  padding:20px;
}
h1{color:#0b5ed7;}
#sentenceBox{
  margin:20px auto;
  padding:15px;
  background:white;
  border-radius:15px;
  max-width:700px;
}
#words, #answer{
  display:flex;
  flex-wrap:wrap;
  justify-content:center;
  gap:10px;
  margin:15px 0;
}
.word{
  padding:8px 14px;
  border-radius:10px;
  color:white;
  font-weight:bold;
  cursor:grab;
  background:#2563eb;
}
.drop-zone{
  background:#fff;
  border:2px dashed #0b5ed7;
  border-radius:12px;
  padding:15px;
  min-height:60px;
}
button{
  padding:10px 18px;
  border:none;
  border-radius:12px;
  background:#0b5ed7;
  color:white;
  cursor:pointer;
  margin:6px;
}
#feedback{font-size:18px;font-weight:bold}
.good{color:green;}
.bad{color:crimson;}
a.back{
  display:inline-block;
  margin-top:20px;
  background:#16a34a;
  color:#fff;
  padding:10px 18px;
  border-radius:12px;
  text-decoration:none;
  font-weight:bold;
}
</style>
</head>

<body>

<h1>🎯 Build the Sentence</h1>
<p>Drag the words to build the sentence.</p>

<div id="sentenceBox">
  <button onclick="speak()">🔊 Listen</button>
</div>

<h3>Words</h3>
<div id="words"></div>

<h3>Your sentence</h3>
<div id="answer" class="drop-zone"></div>

<button onclick="checkSentence()">✅ Check</button>
<div id="feedback"></div>

<a class="back" href=

