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

if (isset($_POST['letter'])) {
  $l = $_POST['letter'];
  if (!in_array($l, $_SESSION['guessed'])) {
    $_SESSION['guessed'][] = $l;
    if (strpos($_SESSION['word'], $l) === false) {
      $_SESSION['wrong']++;
    }
  }
}

$display = '';
$won = true;
foreach (str_split($_SESSION['word']) as $c) {
  if (in_array($c, $_SESSION['guessed'])) {
    $display .= $c . ' ';
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

.word {
  font-size: 32px;
  letter-spacing: 5px;
}

button {
  padding: 8px 12px;
  margin: 3px;
  font-size: 16px;
}

.win {
  color: green;
  font-size: 28px;
  animation: pop 0.6s ease-in-out infinite alternate;
}

.lose {
  color: red;
  font-size: 26px;
}

#hangmanImg {
  width: 250px;
  transition: opacity 0.3s ease, transform 0.3s ease;
}

.shake {
  animation: shake 0.4s;
}

.bounce {
  animation: bounce 0.4s;
}

@keyframes shake {
  0% { transform: translateX(0); }
  25% { transform: translateX(-6px); }
  50% { transform: translateX(6px); }
  75% { transform: translateX(-6px); }
  100% { transform: translateX(0); }
}

@keyframes bounce {
  0% { transform: scale(1); }
  50% { transform: scale(1.15); }
  100% { transform: scale(1); }
}

@keyframes pop {
  from { transform: scale(1); }
  to { transform: scale(1.2); }
}
</style>
</head>

<body>

<h1>🎯 Hangman – InglesDeUna</h1>

<button id="enableSound" onclick="enableSound()">🔊 Activate Sound</button>

<br><br>

<img id="hangmanImg" src="<?php echo $img; ?>">

<p><strong>Hint:</strong> <?php echo $_SESSION['hint']; ?></p>

<p class="word"><?php echo $display; ?></p>

<p id="wrongCount">
  Wrong attempts: <?php echo $_SESSION['wrong']; ?> / <?php echo $maxWrong; ?>
</p>

<?php if ($won): ?>
  <p class="win">🎉 YOU WIN! 🎉</p>
<?php elseif ($lost): ?>
  <p class="lose">❌ Game Over</p>
  <p>The word was: <strong><?php echo $_SESSION['word']; ?></strong></p>
<?php else: ?>
<form method="post">
<?php foreach (range('A','Z') as $l): ?>
  <button name="letter" value="<?php echo $l; ?>"
    <?php echo in_array($l, $_SESSION['guessed']) ? 'disabled' : ''; ?>>
    <?php echo $l; ?>
  </button>
<?php endforeach; ?>
</form>
<?php endif; ?>

<form method="post">
  <button name="reset">🔄 Try Again</button>
</form>

<!-- SOUNDS -->
<audio id="soundCorrect" preload="auto" volume="0.25">
  <source src="https://cdn.pixabay.com/audio/2022/03/15/audio_115b9fbb97.mp3">
</audio>

<audio id="soundWrong" preload="auto" volume="0.25">
  <source src="https://cdn.pixabay.com/audio/2022/03/15/audio_c8b6d8b0c6.mp3">
</audio>

<audio id="soundWin" preload="auto" volume="0.3">
  <source src="https://cdn.pixabay.com/audio/2022/10/03/audio_33c55c32b7.mp3">
</audio>

<audio id="soundLose" preload="auto" volume="0.3">
  <source src="https://cdn.pixabay.com/audio/2022/03/10/audio_3b1f6a9f50.mp3">
</audio>

<script>
let soundEnabled = false;

function enableSound() {
  const sounds = [
    soundCorrect,
    soundWrong,
    soundWin,
    soundLose
  ];
  sounds.forEach(s => {
    s.currentTime = 0;
    s.play().then(()=>s.pause());
  });
  soundEnabled = true;
  document.getElementById("enableSound").innerText = "🔊 Sound Enabled";
  document.getElementById("enableSound").disabled = true;
}

<?php if ($won): ?>
if (soundEnabled) soundWin.play();
<?php elseif ($lost): ?>
if (soundEnabled) soundLose.play();
<?php endif; ?>
</script>

</body>
</html>
