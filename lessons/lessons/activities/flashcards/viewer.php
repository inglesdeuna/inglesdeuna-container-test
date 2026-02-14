<?php
require_once __DIR__."/../../config/db.php";

$unit = $_GET["unit"] ?? null;
if(!$unit) die("Unit missing");

$stmt = $pdo->prepare("
    SELECT data FROM activities
    WHERE unit_id = :u AND type = 'flashcards'
");
$stmt->execute(["u"=>$unit]);

$row = $stmt->fetch(PDO::FETCH_ASSOC);
$data = json_decode($row["data"] ?? "[]", true);

if(!$data || count($data)==0){
    echo "<h3>No hay flashcards para esta unidad</h3>";
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Flashcards</title>

<style>
body{
    font-family:Arial;
    background:#e9f2fb;
    padding:40px;
    text-align:center;
}

.title{
    font-size:28px;
    font-weight:bold;
    color:#0b5ed7;
    margin-bottom:30px;
}

.card-wrapper{
    display:flex;
    justify-content:center;
    align-items:center;
}

.card-container{
    perspective:1000px;
}

.card{
    width:380px;
    height:420px;
    position:relative;
    transform-style:preserve-3d;
    transition:transform .6s;
    cursor:pointer;
}

.card.flip{
    transform:rotateY(180deg);
}

.side{
    position:absolute;
    width:100%;
    height:100%;
    backface-visibility:hidden;
    border-radius:20px;
    box-shadow:0 10px 25px rgba(0,0,0,.15);
    display:flex;
    flex-direction:column;
    justify-content:center;
    align-items:center;
    padding:25px;
}

.front{
    background:white;
}

.back{
    background:#2f6fed;
    color:white;
    transform:rotateY(180deg);
    font-size:30px;
    font-weight:bold;
}

img{
    max-width:260px;
    max-height:260px;
    object-fit:contain;
}

.buttons{
    position:absolute;
    bottom:20px;
    display:flex;
    gap:10px;
}

button{
    padding:8px 14px;
    border:none;
    border-radius:10px;
    cursor:pointer;
    font-weight:bold;
}

.listen{ background:#0b5ed7; color:white;}
.next{ background:#28a745; color:white;}
.hub{
    margin-top:30px;
    background:#28a745;
    color:white;
}
</style>
</head>

<body>

<div class="title">🎴 Flashcards</div>

<div class="card-wrapper">
    <div class="card-container">
        <div class="card" id="card">
            <div class="side front" id="front"></div>
            <div class="side back" id="back"></div>
        </div>
    </div>
</div>

<button class="hub" onclick="window.location.href='../hub/index.php?unit=<?=$unit?>'">
← Volver al Hub
</button>

<script>
const data = <?=json_encode($data, JSON_UNESCAPED_UNICODE)?>;

let index = 0;

const front = document.getElementById("front");
const back = document.getElementById("back");
const card = document.getElementById("card");

function loadCard(){
    const item = data[index];

    front.innerHTML = `
        <img src="/lessons/lessons/${item.image}">
        <div class="buttons">
            <button class="listen" onclick="speak(event)">🔊 Listen</button>
            <button class="next" onclick="nextCard(event)">Next ➜</button>
        </div>
    `;

    back.innerHTML = item.text;
}

function speak(e){
    e.stopPropagation();
    const utter = new SpeechSynthesisUtterance(data[index].text);
    utter.lang = "en-US";
    speechSynthesis.speak(utter);
}

function nextCard(e){
    e.stopPropagation();
    card.classList.remove("flip");
    index++;
    if(index >= data.length) index = 0;
    loadCard();
}

card.addEventListener("click", ()=>{
    card.classList.toggle("flip");
});

loadCard();
</script>

</body>
</html>
