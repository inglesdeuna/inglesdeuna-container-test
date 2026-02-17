<?php
require_once __DIR__."/../../config/db.php";

$unit = $_GET['unit'] ?? null;
if (!$unit) die("Unidad no especificada");

/* =========================
   OBTENER ACTIVIDAD
========================= */

$stmt = $pdo->prepare("
    SELECT data
    FROM activities
    WHERE unit_id = :unit
    AND type = 'pronunciation'
");
$stmt->execute(["unit"=>$unit]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$data = json_decode($row["data"] ?? "[]", true);

if(empty($data)){
    die("No hay palabras para esta unidad");
}
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Pronunciation</title>

<style>
body{
    font-family:Arial;
    background:#eef6ff;
    padding:30px;
    text-align:center;
    position:relative;
}

/* BACK UNIFICADO */
.back-btn{
    position:absolute;
    top:20px;
    left:20px;
    background:#16a34a;
    color:white;
    padding:10px 18px;
    border-radius:12px;
    text-decoration:none;
    font-weight:bold;
}

h1{
    color:#0b5ed7;
    margin-bottom:5px;
}

.subtitle{
    margin-bottom:25px;
    color:#444;
}

.card{
    background:white;
    max-width:700px;
    margin:0 auto;
    padding:30px;
    border-radius:20px;
    box-shadow:0 10px 30px rgba(0,0,0,0.05);
}

.card img{
    width:150px;
    margin-bottom:20px;
    border-radius:15px;
}

.word{
    font-size:28px;
    font-weight:bold;
}

.phonetic{
    font-size:18px;
    color:#666;
}

.translation{
    margin-top:8px;
    font-size:16px;
}

button{
    margin:10px;
    padding:10px 20px;
    border:none;
    border-radius:12px;
    background:#2563eb;
    color:white;
    cursor:pointer;
    font-weight:bold;
}
</style>
</head>
<body>

<a class="back-btn" href="../hub/index.php?unit=<?= urlencode($unit) ?>">
    ↩ Volver al Hub
</a>

<h1>🎧 Pronunciation</h1>
<p class="subtitle">Listen and practice pronunciation.</p>

<div class="card">

    <img id="image">

    <div class="word" id="word"></div>
    <div class="phonetic" id="phonetic"></div>
    <div class="translation" id="translation"></div>

    <div>
        <button onclick="listen()">🔊 Listen</button>
        <button onclick="speak()">🎤 Speak</button>
        <button onclick="next()">➡</button>
    </div>

</div>

<script>

const words = <?= json_encode($data) ?>;

let index = 0;

function loadWord(){
    const w = words[index];

    document.getElementById("word").textContent = w.word;
    document.getElementById("phonetic").textContent = w.phonetic || "";
    document.getElementById("translation").textContent = w.translation || "";

    const img = document.getElementById("image");
    if(w.image){
        img.src = w.image;
        img.style.display = "block";
    }else{
        img.style.display = "none";
    }
}

function listen(){
    const msg = new SpeechSynthesisUtterance(words[index].word);
    msg.lang = "en-US";
    speechSynthesis.speak(msg);
}

function speak(){
    alert("Use microphone feature here if needed");
}

function next(){
    index++;
    if(index >= words.length){
        alert("🎉 You finished!");
        index = 0;
    }
    loadWord();
}

loadWord();

</script>

</body>
</html>
