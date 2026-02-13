<?php

$unit = $_GET["unit"] ?? null;
if(!$unit) die("Unidad no especificada");

$file = __DIR__."/flashcards.json";

$data = file_exists($file)
 ? json_decode(file_get_contents($file), true)
 : [];

if(!isset($data[$unit]) || empty($data[$unit])){
 die("No hay tarjetas para esta unidad");
}

$cards = $data[$unit];

?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Flashcards</title>

<style>

body{
 font-family:Arial;
 background:#eef6ff;
 text-align:center;
 padding:30px;
}

h1{
 color:#0b5ed7;
}

.flashcards-container{
 display:flex;
 flex-wrap:wrap;
 justify-content:center;
 gap:25px;
 margin-top:30px;
}

/* TARJETA */

.flashcard{
 width:220px;
 height:280px;
 perspective:1000px;
 cursor:pointer;
}

.flashcard-inner{
 position:relative;
 width:100%;
 height:100%;
 transition:transform .6s;
 transform-style:preserve-3d;
}

.flashcard.flip .flashcard-inner{
 transform:rotateY(180deg);
}

.flashcard-face{
 position:absolute;
 width:100%;
 height:100%;
 backface-visibility:hidden;
 border-radius:18px;
 display:flex;
 align-items:center;
 justify-content:center;
 padding:15px;
 box-shadow:0 4px 10px rgba(0,0,0,.15);
}

/* FRONT */

.flashcard-front{
 background:white;
 border:4px solid #4f46e5;
}

.flashcard-front img{
 max-width:90%;
 max-height:90%;
}

/* BACK */

.flashcard-back{
 background:#4f46e5;
 color:white;
 transform:rotateY(180deg);
 font-size:28px;
 font-weight:bold;
 text-align:center;
 padding:20px;
}

/* BOTONES */

button{
 background:#0b5ed7;
 color:white;
 border:none;
 padding:10px 18px;
 border-radius:12px;
 cursor:pointer;
 margin:6px;
}

.back{
 display:inline-block;
 margin-top:25px;
 background:#16a34a;
 color:white;
 padding:10px 20px;
 border-radius:12px;
 text-decoration:none;
 font-weight:bold;
}

</style>
</head>

<body>

<h1>🃏 Flashcards</h1>

<button onclick="speakAll()">🔊 Listen</button>

<div class="flashcards-container">

<?php foreach($cards as $card): ?>

<div class="flashcard" onclick="flipCard(this)">
 <div class="flashcard-inner">

  <div class="flashcard-face flashcard-front">
   <img src="../../<?= $card["image"] ?>">
  </div>

  <div class="flashcard-face flashcard-back">
   <?= htmlspecialchars($card["text"]) ?>
  </div>

 </div>
</div>

<?php endforeach; ?>

</div>

<a class="back" href="../hub/index.php?unit=<?= urlencode($unit) ?>">
↩ Volver al Hub
</a>

<script>

const cards = <?= json_encode($cards) ?>;

/* FLIP TARJETA */
function flipCard(card){
 card.classList.toggle("flip");
}

/* TTS */
function speakAll(){

 let text = cards.map(c => c.text).join(". ");

 let msg = new SpeechSynthesisUtterance(text);
 msg.lang = "en-US";
 msg.rate = 0.9;

 speechSynthesis.cancel();
 speechSynthesis.speak(msg);

}

</script>

</body>
</html>
