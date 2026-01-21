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
$img = "/lessons/lessons/hangman/assets/hangman" . $_SESSION['wrong'] . ".png";
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

#soundToggle {
  cursor: pointer;
  font-size: 26px;
  position: fixed;
  top: 20px;
  right: 20px;
}

img {
  width: 250px;
  transition: transform 0.3s ease;
}

.bounce {
  animation: bounce 0.4s;
}

.shake {
  animation: shake 0.4s;
}

@keyframes bounce {
  0% { transform: scale(1); }
  50% { transform: scale(1.1); }
  100% { transform: scale(1); }
}

@keyframes shake {
  0% { transform: translateX(0); }
  25% { transform: translateX(-5px); }
  50% { transform: translateX(5px); }
  75% { transform: translateX(-5px); }
  100% { transform: translateX(0); }
}

.win {
  color: green;
  font-size: 28px;
}

.lose {
  color: red;
  font-size: 26px;
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
</style>
</head>

<body>

<div id="soundToggle">🔊</div>

<h1>🎯 Hangman – InglesDeUna</h1>

<img id="hangmanImg" src="<?= $img ?>">

<p><strong>Hint:</strong> <?= $hint ?></p>

<p class="word"><?= $display ?></p>

<p>Wrong attempts: <?= $_SESSION['wrong'] ?> / <?= $maxWrong ?></p>

<?php if ($won): ?>
  <p class="win">🎉 YOU WIN! 🎉</p>
<?php elseif ($lost): ?>
  <p class="lose">❌ GAME OVER</p>
  <p>The word was: <strong><?= $word ?></strong></p>
<?php else: ?>
  <form method="post">
    <?php foreach (range('A','Z') as $l): ?>
      <button name="letter" value="<?= $l ?>"
        <?= in_array($l, $_SESSION['guessed']) ? 'disabled' : '' ?>>
        <?= $l ?>
      </button>
    <?php endforeach; ?>
  </form>
<?php endif; ?>

<form method="post">
  <button name="reset">🔄 Try Again</button>
</form>

<!-- Sounds -->
<audio id="sCorrect" src="https://cdn.pixabay.com/audio/2022/03/15/audio_115b9fbb97.mp3"></audio>
<audio id="sWrong" src="https://cdn.pixabay.com/audio/2022/03/15/audio_c8b6d8b0c6.mp3"></audio>
<audio id="sWin" src="https://cdn.pixabay.com/audio/2022/10/30/audio_946b2a3b8b.mp3"></audio>
<audio id="sLose" src="https://cdn.pixabay.com/audio/2022/03/10/audio_4c8eeb1c38.mp3"></audio>

<script>
let soundOn = localStorage.getItem("soundOn") !== "false";

const icon = document.getElementById("soundToggle");
icon.textContent = soundOn ? "🔊" : "🔇";

icon.onclick = () => {
  soundOn = !soundOn;
  localStorage.setItem("soundOn", soundOn);
  icon.textContent = soundOn ? "🔊" : "🔇";
};

function play(id) {
  if (!soundOn) return;
  const a = document.getElementById(id);
  a.volume = 0.25;
  a.currentTime = 0;
  a.play();
}

<?php if (isset($_POST['letter'])): ?>
  <?php if (strpos($word, $_POST['letter']) !== false): ?>
    play("sCorrect");
    document.getElementById("hangmanImg").classList.add("bounce");
  <?php else: ?>
    play("sWrong");
    document.getElementById("hangmanImg").classList.add("shake");
  <?php endif; ?>
<?php endif; ?>

<?php if ($won): ?>
  play("sWin");
<?php elseif ($lost): ?>
  play("sLose");
<?php endif; ?>
</script>

</body>
</html>
