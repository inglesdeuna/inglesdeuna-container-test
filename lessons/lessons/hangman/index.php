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
  background: #f7fbff;
}

.hangman {
  width: 260px;
  transition: transform 0.35s ease;
}

.word {
  font-size: 40px;
  letter-spacing: 12px;
  margin: 20px 0;
}

button {
  padding: 10px 14px;
  margin: 4px;
  font-size: 16px;
  border-radius: 12px;
  border: 2px solid #dce9ff;
  background: #fff;
  cursor: pointer;
}

button:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

.win {
  color: #2ecc71;
  font-size: 26px;
}

.lose {
  color: #e74c3c;
  font-size: 26px;
}

/* Animaciones */
.correct {
  animation: bounce 0.35s;
}

.wrong {
  animation: shake 0.35s;
}

@keyframes bounce {
  0% { transform: scale(1); }
  50% { transform: scale(1.08); }
  100% { transform: scale(1); }
}

@keyframes shake {
  0% { transform: translateX(0); }
  25% { transform: translateX(-6px); }
  50% { transform: translateX(6px); }
  75% { transform: translateX(-6px); }
  100% { transform: translateX(0); }
}
</style>
</head>

<body>

<h1>🎯 Hangman – InglesDeUna</h1>

<img id="hangmanImg" class="hangman" src="<?php echo $img; ?>" alt="Hangman">

<p><strong>Hint:</strong> <?php echo $hint; ?></p>

<p class="word"><?php echo $display; ?></p>

<p>Wrong attempts: <?php echo $_SESSION['wrong']; ?> / <?php echo $maxWrong; ?></p>

<?php if ($won): ?>
  <p class="win">🎉 CONGRATULATIONS! YOU WIN!</p>
<?php elseif ($lost): ?>
  <p class="lose">❌ Game Over</p>
  <p>The word was: <strong><?php echo $word; ?></strong></p>
<?php else: ?>
  <form method="post">
    <?php foreach (range('A', 'Z') as $l): ?>
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

<!-- Audios (sin botón) -->
<audio id="soundCorrect" src="/lessons/lessons/hangman/assets/correct.wav"></audio>
<audio id="soundWrong" src="/lessons/lessons/hangman/assets/wrong.wav"></audio>
<audio id="soundWin" src="/lessons/lessons/hangman/assets/win.wav"></audio>
<audio id="soundLose" src="/lessons/lessons/hangman/assets/lose.wav"></audio>

<script>
// volumen infantil
["soundCorrect","soundWrong","soundWin","soundLose"].forEach(id => {
  document.getElementById(id).volume = 0.3;
});

// pre-carga imágenes (elimina flashes)
for (let i = 0; i <= 7; i++) {
  const img = new Image();
  img.src = `/lessons/lessons/hangman/assets/hangman${i}.png`;
}

// animaciones + sonido
const img = document.getElementById("hangmanImg");
<?php if (isset($_POST['letter'])): ?>
  <?php if (strpos($word, $_POST['letter']) !== false): ?>
    document.getElementById("soundCorrect").play();
    img.classList.add("correct");
  <?php else: ?>
    document.getElementById("soundWrong").play();
    img.classList.add("wrong");
  <?php endif; ?>
<?php endif; ?>

<?php if ($won): ?>
  document.getElementById("soundWin").play();
<?php endif; ?>

<?php if ($lost): ?>
  document.getElementById("soundLose").play();
<?php endif; ?>

setTimeout(() => {
  img.classList.remove("correct","wrong");
}, 400);
</script>

</body>
</html>
