<?php
$unit = $_GET['unit'] ?? null;

$file = __DIR__ . "/hangman.json";
$data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];

$activity = $data[0] ?? null;
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

.word{
  font-size:32px;
  letter-spacing:8px;
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
  font-size:20px;
  font-weight:bold;
}
</style>
</head>

<body>

<h1>🎯 Hangman</h1>

<?php if (!$activity): ?>
  <p>No hangman activity available.</p>
<?php else: ?>

<?php
$word = strtoupper($activity["word"]);
$hint = $activity["hint"] ?? "";
?>

<p><strong>Hint:</strong> <?= htmlspecialchars($hint) ?></p>

<div class="word" id="word"></div>
<div class="letters" id="letters"></div>
<div id="result" class="result"></div>

<script>
const word = "<?= $word ?>";
let guessed = [];

const wordDiv = document.getElementById("word");
const lettersDiv = document.getElementById("letters");

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
    document.getElementById("result").textContent = "🌟 You win!";
  }
}

"ABCDEFGHIJKLMNOPQRSTUVWXYZ".split("").forEach(l=>{
  const btn = document.createElement("button");
  btn.textContent = l;
  btn.onclick = ()=>{
    guessed.push(l);
    btn.disabled = true;
    renderWord();
  };
  lettersDiv.appendChild(btn);
});

renderWord();
</script>

<?php endif; ?>

</body>
</html>
