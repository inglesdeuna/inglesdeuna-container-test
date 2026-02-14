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

<style>
.flash-wrapper{
    display:flex;
    justify-content:center;
    margin-top:40px;
}

.card-container{
    perspective:1000px;
}

.card{
    width:350px;
    height:400px;
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
    box-shadow:0 10px 25px rgba(0,0,0,.2);
    display:flex;
    flex-direction:column;
    justify-content:center;
    align-items:center;
    padding:20px;
}

.front{ background:white; }

.back{
    background:#2f6fed;
    color:white;
    transform:rotateY(180deg);
    font-size:28px;
    font-weight:bold;
}

img{
    max-width:250px;
    max-height:250px;
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
    border-radius:8px;
    cursor:pointer;
    font-weight:bold;
}

.next{ background:#28a745; color:white;}
.listen{ background:#0b5ed7; color:white;}

.hub-btn{
    margin-top:30px;
    background:#28a745;
    color:white;
    padding:10px 18px;
    border:none;
    border-radius:8px;
    cursor:pointer;
}
</style>

<h2 style="text-align:center;">Flashcards</h2>

<div class="flash-wrapper">
    <div class="card-container">
        <div class="card" id="card">
            <div class="side front" id="front"></div>
            <div class="side back" id="back"></div>
        </div>
    </div>
</div>

<div style="text-align:center;">
    <a href="../hub/index.php?unit=<?=$unit?>">
        <button class="hub-btn">← Volver al Hub</button>
    </a>
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
