<?php
session_start();

/* =====================
   VALIDAR UNIDAD
   ===================== */
$unitId = $_GET["unit"] ?? null;
if (!$unitId) {
  die("Unidad no especificada");
}

/* =====================
   DATA REAL
   ===================== */
$baseDir   = dirname(__DIR__, 3) . "/admin/data";
$unitsFile = $baseDir . "/units.json";

if (!file_exists($unitsFile)) {
  die("Archivo de unidades no encontrado");
}

$units = json_decode(file_get_contents($unitsFile), true) ?? [];

/* =====================
   BUSCAR UNIDAD
   ===================== */
$unitIndex = null;
foreach ($units as $i => $u) {
  if (($u["id"] ?? null) === $unitId) {
    $unitIndex = $i;
    break;
  }
}

if ($unitIndex === null) {
  die("Unidad no encontrada");
}

/* =====================
   FILTRAR HANGMAN
   ===================== */
$activities = array_values(array_filter(
  $units[$unitIndex]["activities"] ?? [],
  fn($a) => ($a["type"] ?? "") === "hangman"
));

if (empty($activities)) {
  die("No hay actividades Hangman para esta unidad");
}

/* =====================
   SELECCIÓN RANDOM
   ===================== */
$currentIndex = $_GET["i"] ?? rand(0, count($activities) - 1);
$currentIndex = (int)$currentIndex;

if (!isset($activities[$currentIndex])) {
  $currentIndex = 0;
}

$current = $activities[$currentIndex]["data"];
$word = strtoupper($current["word"]);
$hint = $current["hint"] ?? "";

/* =====================
   ASSETS
   ===================== */
$assets = "assets/";
$maxFails = 7;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Hangman</title>

<style>
body{
  font-family:Arial;
  background:#eef6ff;
  padding:40px;
  text-align:center;
}
.card{
  background:#fff;
  max-width:480px;
  margin:auto;
  padding:25px;
  border-radius:16px;
  box-shadow:0 10px 25px rgba(0,0,0,.1);
}
.word{
  font-size:28px;
  letter-spacing:10px;
  margin:20px 0;
}
.letters button{
  margin:4px;
  padding:8px 12px;
  border:none;
  border-radius:8px;
  background:#2563eb;
  color:#fff;
  cursor:pointer;
}
.letters button:disabled{
  background:#9ca3af;
}
.actions{
  margin-top:20px;
}
.actions a{
  text-decoration:none;
  margin:6px;
  padding:10px 16px;
  border-radius:8px;
  background:#16a34a;
  color:#fff;
  font-weight:bold;
}
.back{background:#6b7280;}
.hint{color:#555;margin-bottom:10px;}
img{max-width:200px;margin:10px auto;}
</style>
</head>

<body>

<div class="card">
  <h2>🎯 Hangman</h2>

  <?php if ($hint): ?>
    <div class="hint">💡 <?= htmlspecialchars($hint) ?></div>
  <?php endif; ?>

  <img id="hangmanImg" src="<?= $assets ?>hangman0.png">

  <div class="word" id="word"></div>

  <div class="letters" id="letters"></div>

  <div class="actions">
    <a href="?unit=<?= urlencode($unitId) ?>&i=<?= $currentIndex + 1 ?>">➡️ Siguiente</a>
    <a class="back" href="../hub/index.php?unit=<?= urlencode($unitId) ?>">⬅ Volver</a>
  </div>
</div>

<audio id="correctSound" src="<?= $assets ?>correct.wav"></audio>
<audio id="wrongSound" src="<?= $assets ?>wrong.wav"></audio>
<audio id="winSound" src="<?= $assets ?>win.mp3"></audio>
<audio id="loseSound" src="<?= $assets ?>lose.mp3"></audio>

<script>
const word = "<?= $word ?>";
let guessed = [];
let fails = 0;
const maxFails = <?= $maxFails ?>;

const wordDiv = document.getElementById("word");
const lettersDiv = document.getElementById("letters");
const img = document.getElementById("hangmanImg");

function renderWord(){
  let display = "";
  let done = true;

  for(let l of word){
    if(guessed.includes(l)){
      display += l + " ";
    }else{
      display += "_ ";
      done = false;
    }
  }

  wordDiv.textContent = display.trim();

  if(done){
    document.getElementById("winSound").play();
    disableLetters();
  }
}

function disableLetters(){
  document.querySelectorAll(".letters button").forEach(b=>b.disabled=true);
}

function guess(letter, btn){
  btn.disabled = true;

  if(word.includes(letter)){
    guessed.push(letter);
    document.getElementById("correctSound").play();
  }else{
    fails++;
    img.src = "<?= $assets ?>hangman" + fails + ".png";
    document.getElementById("wrongSound").play();
  }

  if(fails >= maxFails){
    document.getElementById("loseSound").play();
    wordDiv.textContent = word.split("").join(" ");
    disableLetters();
  }

  renderWord();
}

function init(){
  renderWord();

  for(let i=65;i<=90;i++){
    const l = String.fromCharCode(i);
    const b = document.createElement("button");
    b.textContent = l;
    b.onclick = ()=>guess(l,b);
    lettersDiv.appendChild(b);
  }
}

init();
</script>

</body>
</html>

