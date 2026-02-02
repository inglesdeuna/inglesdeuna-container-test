<?php
/* ===============================
   HANGMAN – VIEWER (ESTUDIANTE)
   =============================== */

$unit = $_GET['unit'] ?? null;

$file = __DIR__ . "/hangman.json";
$data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];

$activity = null;

// Por ahora: toma la primera actividad
// (luego filtramos por unit)
if (!empty($data)) {
  $activity = $data[0];
}

if (!$activity) {
  die("No hangman activity available.");
}

$word = strtoupper($activity['word'] ?? '');
$hint = $activity['hint'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Hangman</title>

<style>
body{
  font-family:Arial, sans-serif;
  background:#eef6ff;
  padding:30px;
  text-align:center;
}

h1{color:#2563eb;}

.hangman-img{
  max-width:220px;
  margin:20px auto;
}

.word{
  font-size:32px;
  letter-spacing:10px;
  margin:20px 0;
}

.letters{
  display:flex;
  flex-wrap:wrap;
  justify-content:center;
  gap:10px;
}

.letters button{
  padding:10px 14px;
  border:none;
  border-radius:8px;
  background:#2563eb;
  color:white;
  font-weight:bold;
  cursor:pointer;
}

.letters button:disabled{
  background:#ccc;
  cursor:not-allowed;
}

.result{
  margin-top:20px;
  font-size:22px;
  font-weight:bold;
}
</style>
</head>

<body>

<h1>🎯 Hangman</h1>

<?php if ($hint): ?>
<p><strong>Hint:</strong> <?= htmlspecialchars($hint) ?></p>
<?php endif; ?>

<img id="hangmanImg" class="hangman-img"
     src="assets/hangman0.png" alt="Hangman">

<div class="word" id="word"></div>
<div class="letters" id="letters"></div>
<div id="result" class="result"></div>

<audio id="soundCorrect" src="assets/correct.wav"></audio>
<audio id="soundWin" src="assets/win.mp3"></audio>
<audio id="soundLose" src="assets/lose.mp3"></audio>

<script>
const word = "<?= $word ?>";
const maxErrors = 7;

let guessed = [];
let errors = 0;

const wordDiv = document.getElementById("word");
const lettersDiv = document.getElementById("letters");
const img = document.getElementById("hangmanImg");
const result = document.getElementById("result");

const soundCorrect = document.getElementById("soundCorrect");
const soundWin = document.getElementById("soundWin");
const soundLose = document.getElementById("soundLose");

function renderWord(){
  let display = "";
  let completed = true;

  for(const c of word){
    if(guessed.includes(c)){
      display += c + " ";
    }else{
      display += "_ ";
      completed = false;
    }
  }

  wordDiv.textContent = display.trim();

  if(completed){
    result.textContent = "🌟 You win!";
    soundWin.play();
    disableAll();
  }
}

function disableAll(){
  document.querySelectorAll(".letters button")
    .forEach(b => b.disabled = true);
}

"ABCDEFGHIJKLMNOPQRSTUVWXYZ".split("").forEach(l=>{
  const btn = document.createElement("button");
  btn.textContent = l;

  btn.onclick = ()=>{
    btn.disabled = true;

    if(word.includes(l)){
      guessed.push(l);
      soundCorrect.play();
      renderWord();
    }else{
      errors++;
      img.src = "assets/hangman" + errors + ".png";

      if(errors >= maxErrors){
        result.textContent = "❌ You lost!";
        soundLose.play();
        disableAll();
      }
    }
  };

  lettersDiv.appendChild(btn);
});

renderWord();
</script>

</body>
</html>
