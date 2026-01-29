<?php
$file = __DIR__ . "/flashcards.json";
$cards = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Flashcards</title>

<style>
body{
  font-family: Arial, sans-serif;
  background:#f4f8ff;
  margin:0;
  padding:40px;
}

h1{
  text-align:center;
  color:#2563eb;
  margin-bottom:30px;
}

.flashcard-wrapper{
  max-width:420px;
  margin:0 auto;
  text-align:center;
}

.card{
  background:white;
  border-radius:16px;
  padding:30px;
  min-height:260px;
  box-shadow:0 10px 25px rgba(0,0,0,.08);
  display:flex;
  flex-direction:column;
  justify-content:center;
  align-items:center;
}

.card img{
  max-width:100%;
  max-height:160px;
  margin-bottom:15px;
  border-radius:10px;
}

.card-text{
  font-size:22px;
  font-weight:bold;
}

.controls{
  margin-top:20px;
  display:flex;
  justify-content:space-between;
  align-items:center;
}

.arrow{
  font-size:26px;
  background:#e5e7eb;
  border:none;
  padding:10px 16px;
  border-radius:10px;
  cursor:pointer;
}

.flip{
  background:#2563eb;
  color:white;
  border:none;
  width:52px;
  height:52px;
  border-radius:12px;
  font-size:20px;
  cursor:pointer;
}

.audio{
  margin-top:15px;
  background:#10b981;
  color:white;
  border:none;
  padding:10px 16px;
  border-radius:12px;
  cursor:pointer;
}
</style>
</head>

<body>

<h1>🃏 Flashcards</h1>

<?php if(empty($cards)): ?>
<p style="text-align:center">No flashcards available.</p>
<?php else: ?>

<div class="flashcard-wrapper">
  <div class="card" id="card"></div>

  <button class="audio" onclick="speak()">🔊 Audio</button>

  <div class="controls">
    <button class="arrow" onclick="prev()">⬅️</button>
    <button class="flip" onclick="flip()">🔄</button>
    <button class="arrow" onclick="next()">➡️</button>
  </div>
</div>

<?php endif; ?>

<script>
const cards = <?= json_encode($cards) ?>;
let index = 0;
let side = "front";

function render(){
  const c = cards[index];
  const card = document.getElementById("card");
  card.innerHTML = "";

  const data = side === "front" ? c.front : c.back;

  if(data.image){
    const img = document.createElement("img");
    img.src = data.image;
    card.appendChild(img);
  }

  const text = document.createElement("div");
  text.className = "card-text";
  text.textContent = data.text;
  card.appendChild(text);
}

function flip(){
  side = side === "front" ? "back" : "front";
  render();
}

function next(){
  index = (index + 1) % cards.length;
  side = "front";
  render();
}

function prev(){
  index = (index - 1 + cards.length) % cards.length;
  side = "front";
  render();
}

function speak(){
  const text = cards[index][side].text;
  const msg = new SpeechSynthesisUtterance(text);
  msg.lang = "en-US";
  msg.rate = 0.9;
  speechSynthesis.cancel();
  speechSynthesis.speak(msg);
}

render();
</script>

</body>
</html>
