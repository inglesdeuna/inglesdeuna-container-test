<?php
session_start();

$words = [
  ["word" => "APPLE", "hint" => "A fruit 🍎"],
  ["word" => "HOUSE", "hint" => "A place to live 🏠"],
  ["word" => "TEACHER", "hint" => "Works at a school 👩‍🏫"],
  ["word" => "DOG", "hint" => "A friendly animal 🐶"]
];

$maxWrong = 7;

if (!isset($_SESSION['word']) || isset($_POST['reset'])) {
  $item = $words[array_rand($words)];
  $_SESSION['word'] = $item['word'];
  $_SESSION['hint'] = $item['hint'];
  $_SESSION['guessed'] = [];
  $_SESSION['wrong'] = 0;
}

$word = $_SESSION['word'];
$hint = $_SESSION['hint'];

if (isset($_POST['letter'])) {
  $letter = $_POST['letter'];
  if (!in_array($letter, $_SESSION['guessed'])) {
    $_SESSION['guessed'][] = $letter;
    if (strpos($word, $letter) === false) {
      $_SESSION['wrong']++;
    }
  }
}

$display = '';
$won = true;
foreach (str_split($word) as $char) {
  if (in_array($char, $_SESSION['guessed'])) {
    $display .= $char . ' ';
  } else {
    $display .= '_ ';
    $won = false;
  }
}

$lost = $_SESSION['wrong'] >= $maxWrong;
$img = "assets/hangman" . $_SESSION['wrong'] . ".png";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Hangman – InglesDeUna</title>

<style>
body {
  font-family: Arial, sans-serif;
  text-align: center;
}

.word {
  font-size: 32px;
  letter-spacing: 6px;
}

button {
  padding: 8px 12px;
  margin: 3px;
  font-size: 16px;
}

img {
  width: 260px;
  transition: opacity .25s ease, transform .25s ease;
}

img.shake {
  transform: translateX(-6px);
}

img.bounce {
  transform: scale(1.05);
}

.win {
  color: green;
  font-size: 26px;
  animation: pop 0.6s ease;
}

.lose {
  color: red;
  font-size: 26px;
}

@keyframes pop {
  0% { transform: scale(.8); opacity: 0; }
  100% { transform: scale(1); opacity: 1; }
}
</style>
</head>

<body>

<h1>🎯 Hangman – InglesDeUna</h1>

<img id="hangmanImg" src="<?= $img ?>">

<p><strong>Hint:</strong> <?= $hint ?></p>
<p class="word"><?= $display ?></p>
<p>Wrong attempts: <?= $_SESSION['wrong'] ?> / <?= $maxWrong ?></p>

<?php if ($won): ?>
  <p class="win">🎉 CONGRATULATIONS!</p>
<?php elseif ($lost): ?>
  <p class="lose">❌ Game Over</p>
  <p>The word was: <strong><?= $word ?></strong></p>
<?php else: ?>
  <form method="post">
    <?php foreach (range('A','Z') as $l): ?>
      <button name="letter" value="<?= $l ?>" <?= in_array($l,$_SESSION['guessed'])?'disabled':'' ?>>
        <?= $l ?>
      </button>
    <?php endforeach; ?>
  </form>
<?php endif; ?>

<form method="post">
  <button name="reset">🔄 Try Again</button>
</form>

<!-- 🔊 AUDIO FILES (LOCAL, WAV) -->
<audio id="sndCorrect" src="assets/correct.wav" preload="auto"></audio>
<audio id="sndWrong"   src="assets/wrong.wav"   preload="auto"></audio>
<audio id="sndWin"     src="assets/win.wav"     preload="auto"></audio>
<audio id="sndLose"    src="assets/lose.wav"    preload="auto"></audio>

<script>
const img = document.getElementById("hangmanImg");

function playSound(id){
  const s = document.getElementById(id);
  s.volume = 0.25;
  s.currentTime = 0;
  s.play();
}

// Detect last action
<?php if (isset($_POST['letter'])): ?>
  <?php if (strpos($word, $_POST['letter']) !== false): ?>
    playSound("sndCorrect");
    img.classList.add("bounce");
    setTimeout(()=>img.classList.remove("bounce"),250);
  <?php else: ?>
    playSound("sndWrong");
    img.classList.add("shake");
    setTimeout(()=>img.classList.remove("shake"),250);
  <?php endif; ?>
<?php endif; ?>

<?php if ($won): ?>
  playSound("sndWin");
<?php endif; ?>

<?php if ($lost): ?>
  playSound("sndLose");
<?php endif; ?>
</script>

</body>
</html>
