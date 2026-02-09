<?php
$unit = $_GET["unit"] ?? null;
if (!$unit) die("Unit missing");

$jsonFile = __DIR__ . "/hangman.json";

$data = file_exists($jsonFile)
    ? json_decode(file_get_contents($jsonFile), true)
    : [];

if (!isset($data[$unit]) || empty($data[$unit])) {
    $word = "TEST";
} else {
    $words = $data[$unit];
    $word = strtoupper($words[array_rand($words)]["word"] ?? "TEST");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Hangman</title>

<style>
body{
    font-family:Arial;
    background:#eef4ff;
    text-align:center;
    padding:40px;
}

#hangmanImg{
    width:220px;
    margin:20px;
}

.word{
    font-size:32px;
    letter-spacing:10px;
    margin:20px;
}

.keyboard button{
    padding:10px;
    margin:4px;
    cursor:pointer;
}
</style>
</head>

<body>

<h2>🎯 Hangman</h2>

<img id="hangmanImg" src="assets.keep/hangman0.png">

<div id="word" class="word"></div>

<div id="keyboard" class="keyboard"></div>

<br>

<button id="nextBtn" style="display:none">Next ➜</button>
<button id="retryBtn" style="display:none">Try Again</button>

<a href="../hub/index.php?unit=<?= urlencode($unit) ?>">
<button>⬅ Volver al Hub</button>
</a>

<!-- SOUNDS -->
<audio id="soundCorrect" src="assets.keep/correct.mp3"></audio>
<audio id="soundWrong" src="assets.keep/wrong.mp3"></audio>
<audio id="soundWin" src="assets.keep/win.mp3"></audio>
<audio id="soundLose" src="assets.keep/lose.mp3"></audio>

<script>

let word = <?= json_encode($word) ?>;
let guessed = [];
let mistakes = 0;
let maxMistakes = 6;

function renderWord(){
    let display="";
    let win=true;

    for(let l of word){
        if(guessed.includes(l)){
            display += l + " ";
        }else{
            display += "_ ";
            win=false;
        }
    }

    document.getElementById("word").innerText=display;

    if(win){
        document.getElementById("soundWin").play();
        document.getElementById("nextBtn").style.display="inline-block";
    }
}

function guess(letter){

    if(guessed.includes(letter)) return;

    guessed.push(letter);

    if(word.includes(letter)){
        document.getElementById("soundCorrect").play();
    }else{
        mistakes++;
        document.getElementById("soundWrong").play();

        document.getElementById("hangmanImg").src =
        "assets.keep/hangman"+mistakes+".png";

        if(mistakes>=maxMistakes){
            document.getElementById("soundLose").play();
            document.getElementById("retryBtn").style.display="inline-block";
        }
    }

    renderWord();
}

function buildKeyboard(){
    let letters="ABCDEFGHIJKLMNOPQRSTUVWXYZ";
    let html="";

    for(let l of letters){
        html+=`<button onclick="guess('${l}')">${l}</button>`;
    }

    document.getElementById("keyboard").innerHTML=html;
}

document.getElementById("retryBtn").onclick=()=>location.reload();
document.getElementById("nextBtn").onclick=()=>location.reload();

renderWord();
buildKeyboard();

</script>

</body>
</html>

