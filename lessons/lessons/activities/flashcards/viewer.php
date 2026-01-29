<?php
$file = __DIR__ . "/flashcards.json";
$cards = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Flashcards</title>

<style>
body{
  font-family:Arial, sans-serif;
  background:#f5f7fb;
  margin:0;
  padding:40px;
}

.container{
  max-width:520px;
  margin:0 auto;
  text-align:center;
}

.card{
  background:#fff;
  border-radius:16px;
  padding:30px;
  box-shadow:0 10px 25px rgba(0,0,0,.08);
  min-height:260px;
  display:flex;
  flex-direction:column;
  justify-content:center;
}

.card img{
  max-width:100%;
  max-height:160px;
  margin:15px auto;
  border-radius:10px;
}

.text{
  font-size:22px;
  font-weight:bold;
}

.controls{
  margin-top:20px;
  display:flex;
  justify-content:space-between;
  gap:10px;
}

button{
  flex:1;
  padding:12px;
  border:none;
  border-radius:10px;
  background:#2563eb;
  color:#fff;
  font-weight:bold;
  cursor:pointer;
}

button.secondary{
  background:#e5e7eb;
  color:#111;
}

.top-controls{
  margin-bottom:15px;
  display:flex;
  justify-content:space-between;
  gap:10px;
}

.audio-btn{
  background:#10b981;
}
</style>
</head>

<body>

<div class="container">

<h2>🃏 Flashcards</h2>

<?php if (empty($cards)): ?>
  <p>No hay flashcards configuradas.</p>
<?php else: ?>

<div class="card" id="card"></div>

<div class="top-controls">
  <button class="secondary" onclick="flip()">🔄 Voltear</button>
  <button class="audio-btn" onclick="playAudio()">🔊 Audio</button>
</div>

<div class="controls">
  <button class="secondary" onclick="prev()">⬅️ Anterior</button>
  <button onclick="next()">Siguiente ➡️</button>
</div>

<?php endif; ?>

</div>

<script>
const cards = <?= json_encode($cards) ?>;
let index = 0;
let side = "front";

function render(){
  const c = cards[index][side];
  let html = "";

  if(c.image){
    html += `<img src="${c.image}">`;
  }

  html += `<div class="text">${c.text || ""}</div>`;
  document.getElementById("card").innerHTML = html;
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

function playAudio(){
  const text = cards[index][side].text;
  const lang = cards[index][side].lang || "es";
  if(!text) return;

  const msg = new SpeechSynthesisUtterance(text);
  msg.lang = lang === "en" ? "en-US" : "es-ES";
  msg.rate = 0.9;

  window.speechSynthesis.cancel();
  window.speechSynthesis.speak(msg);
}

render();
</script>

</body>
</html>
