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
<link rel="stylesheet" href="../../assets/css/ui.css">

<style>
body{
    font-family:Arial;
    background:#e9f2fb;
    padding:40px;
    text-align:center;
    position:relative;
}

/* BOTÓN BACK */
.back-btn{
    position:absolute;
    top:20px;
    left:20px;
    background:#16a34a;
    color:white;
    padding:12px 28px;
    border:none;
    border-radius:16px;
    font-weight:bold;
    cursor:pointer;
    font-size:15px;
    transition:0.2s ease;
}

.back-btn:hover{
    background:#15803d;
}

/* TÍTULO */
.title{
    font-size:28px;
    font-weight:bold;
    color:#0b5ed7;
    margin-bottom:25px;
}

/* LISTEN */
.listen-wrapper{
    margin-bottom:15px;
}

button{
    padding:8px 16px;
    border:none;
    border-radius:10px;
    cursor:pointer;
    font-weight:bold;
}

.listen{ background:#0b5ed7; color:white;}
.next{ background:#28a745; color:white;}

/* CARD */
.card-container{
    perspective:1000px;
    display:flex;
    justify-content:center;
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

/* IMAGEN PERFECTAMENTE CENTRADA */
.front img{
    max-width:260px;
    max-height:260px;
    object-fit:contain;
    margin-bottom:20px;
}

/* NEXT dentro del card */
.next-wrapper{
    position:absolute;
    bottom:20px;
}
</style>
</head>

<body>

<button class="back-btn" onclick="window.location.href='../hub/index.php?unit=<?= urlencode($unit) ?>'">
↩ Back
</button>

<div class="title">🧸 Flashcards</div>

<div class="listen-wrapper">
    <button class="listen" onclick="speak(event)">🔊 Listen</button>
</div>

<div class="card-container">
    <div class="card" id="card">
        <div class="side front" id="front"></div>
        <div class="side back" id="back"></div>
    </div>
</div>

<script>
const data = <?=json_encode($data, JSON_UNESCAPED_UNICODE)?>;

let index = 0;

const front = document.getElementById("front");
const back = document.getElementById("back");
const card = document.getElementById("card");

function loadCard(){
    const item = data[index];

    front.innerHTML = `
        ${item.image ? `<img src="${item.image}" alt="">` : ""}
        <div class="next-wrapper">
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
