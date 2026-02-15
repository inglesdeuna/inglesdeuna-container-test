<?php
require_once __DIR__."/../../config/db.php";

$unit = $_GET["unit"] ?? null;
if(!$unit) die("Unit missing");

/* LOAD DATA */

$stmt = $pdo->prepare("
    SELECT data FROM activities
    WHERE unit_id = :u AND type = 'flashcards'
");
$stmt->execute(["u"=>$unit]);

$row = $stmt->fetch(PDO::FETCH_ASSOC);
$data = json_decode($row["data"] ?? "[]", true);

if(empty($data)){
    die("No flashcards available");
}
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Flashcards</title>
<link rel="stylesheet" href="../../assets/css/ui.css">

<style>

/* Flashcard specific styling */

.card-container{
    perspective:1000px;
    margin-top:20px;
}

.card{
    width:300px;
    height:200px;
    margin:auto;
    position:relative;
    transform-style:preserve-3d;
    transition:0.6s;
    cursor:pointer;
}

.card.flip{
    transform:rotateY(180deg);
}

.card-side{
    position:absolute;
    width:100%;
    height:100%;
    backface-visibility:hidden;
    background:white;
    border-radius:16px;
    box-shadow:0 4px 12px rgba(0,0,0,.1);
    display:flex;
    align-items:center;
    justify-content:center;
    flex-direction:column;
    padding:15px;
    text-align:center;
}

.card-back{
    transform:rotateY(180deg);
}

.card img{
    max-width:120px;
    max-height:100px;
    object-fit:contain;
}

.controls{
    margin-top:20px;
}

.secondary-btn{
    background:#6c757d;
    color:white;
    border:none;
    padding:12px 26px;
    border-radius:16px;
    font-weight:bold;
    cursor:pointer;
    font-size:15px;
    transition:0.2s ease;
}

.secondary-btn:hover{
    background:#565e64;
}

</style>
</head>

<body>

<div class="box">

<h1 class="title">🃏 Flashcards</h1>

<div class="card-container">

<div class="card" id="card" onclick="flipCard()">

    <div class="card-side card-front" id="front"></div>

    <div class="card-side card-back" id="back"></div>

</div>

</div>

<div class="controls">

<button class="secondary-btn" onclick="prevCard()">⬅ Previous</button>
<button class="secondary-btn" onclick="nextCard()">Next ➡</button>

</div>

<br>

<button 
class="back-btn"
onclick="window.location.href='../hub/index.php?unit=<?= urlencode($unit) ?>'">
↩ Back
</button>

</div>

<script>

const flashcards = <?= json_encode($data) ?>;
let index = 0;

const front = document.getElementById("front");
const back  = document.getElementById("back");
const card  = document.getElementById("card");

function loadCard(){
    const item = flashcards[index];

    front.innerHTML = `<strong>${item.text}</strong>`;

    if(item.image){
        back.innerHTML = `<img src="/lessons/lessons/${item.image}">`;
    } else {
        back.innerHTML = `<em>No image</em>`;
    }

    card.classList.remove("flip");
}

function flipCard(){
    card.classList.toggle("flip");
}

function nextCard(){
    index++;
    if(index >= flashcards.length){
        index = 0;
    }
    loadCard();
}

function prevCard(){
    index--;
    if(index < 0){
        index = flashcards.length - 1;
    }
    loadCard();
}

loadCard();

</script>

</body>
</html>
