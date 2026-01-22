<?php
session_start();

$words = [
  ["word" => "APPLE", "hint" => "A fruit 🍎"],
  ["word" => "HOUSE", "hint" => "A place to live 🏠"],
  ["word" => "TEACHER", "hint" => "Works at a school 👩‍🏫"],
  ["word" => "DOG", "hint" => "A friendly animal 🐶"]
];

$maxWrong = 7;

if (!isset($_SESSION['started'])) {
  $_SESSION['started'] = false;
}

if (isset($_POST['start'])) {
  $_SESSION['started'] = true;
  unset($_SESSION['word']);
}

if ($_SESSION['started']) {
  if (!isset($_SESSION['word']) || isset($_POST['reset'])) {
    $item = $words[array_rand($words)];
    $_SESSION['word'] = $item['word'];
    $_SESSION['hint'] = $item['hint'];
    $_SESSION['guessed'] = [];
    $_SESSION['wrong'] = 0;
  }

  if (isset($_POST['letter'])) {
    $letter = $_POST['letter'];
    if (!in_array($letter, $_SESSION['guessed'])) {
      $_SESSION['guessed'][] = $letter;
      if (strpos($_SESSION['word'], $letter) === false) {
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
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Hangman – InglesDeUna</title>

<style>
body { font-family: Arial; text-align:center; }
.word { font-size:32px; letter-spacing:6px; }
button { padding:8px 12px; margin:4px; font-size:16px; cursor:pointer; }
.win { color:green; font-size:26px; }
.lose { color:red; font-size:26px; }

#hangmanImg {
  width:250px;
  transition: opacity 0.25s ease;
}

.shake {
  animation: shake 0.4s;
}

@keyframes shake {
  0% { transform: translateX(0); }
  25% { transform: translateX(-5px); }
  50% { transform: translateX(5px); }
  75% { transform: translateX(-5px); }
  100% { transform: translateX(0); }
}

.celebrate {
  animation: pop 0.6s ease;
}

@keyframes pop {
  0% { transform: scale(1); }
  50% { transform: scale(1.15); }
  100% { transform: scale(1); }
}
</style>
</head>

<body>

<h1>🎯 Hangman – InglesDeUna</h1>

<?php if (!$_SESSION['started']): ?>

<form method="post">
  <button name="start">▶️ Start Game 🔊</button>
</form>

<?php else: ?>

<img id="hangmanImg" src="<?php echo $img; ?>">

<p><strong>Hint:</strong> <?php echo $_SESSION['hint']; ?></p>
<p class="word"><?php echo $display; ?></p>
<p>Wrong attempts: <?php echo $_SESSION['wrong']; ?> / <?php echo $maxWrong; ?></p>

<?php if ($won): ?>
  <p class="win celebrate">🎉 CONGRATULATIONS! YOU WIN!</p>
<?php elseif ($lost): ?>
  <p class="lose">❌ Game Over</p>
  <p>The word was: <strong><?php echo $_SESSION['word']; ?></strong></p>
<?php else: ?>
<form method="post">
<?php foreach (range('A','Z') as $l): ?>
<button name="letter" value="<?php echo $l; ?>"
<?php echo in_array($l,$_SESSION['guessed'])?'disabled':''; ?>>
<?php echo $l; ?>
</button>
<?php endforeach; ?>
</form>
<?php endif; ?>

<form method="post">
<button name="reset">🔄 Try Again</button>
</form>

<?php endif; ?>

<!-- AUDIO -->
<audio id="sndCorrect" src="/lessons/lessons/hangman/assets/correct.wav"></audio>
<audio id="sndWrong" src="/lessons/lessons/hangman/assets/wrong.wav"></audio>
<audio id="sndWin" src="/lessons/lessons/hangman/assets/win.wav"></audio>
<audio id="sndLose" src="/lessons/lessons/hangman/assets/lose.wav"></audio>

<script>
const correct = document.getElementById("sndCorrect");
const wrong   = document.getElementById("sndWrong");
const win     = document.getElementById("sndWin");
const lose    = document.getElementById("sndLose");
const img     = document.getElementById("hangmanImg");

[correct, wrong, win, lose].forEach(a => a.volume = 0.3);

<?php if ($_SESSION['started'] && isset($_POST['letter'])): ?>
<?php if ($won): ?>
win.play();
img.classList.add("celebrate");
<?php elseif ($lost): ?>
lose.play();
<?php elseif (strpos($_SESSION['word'], $_POST['letter']) === false): ?>
wrong.currentTime = 0;
wrong.play();
img.classList.add("shake");
setTimeout(()=>img.classList.remove("shake"),400);
<?php else: ?>
correct.currentTime = 0;
correct.play();
<?php endif; ?>
<?php endif; ?>
</script>

</body>
</html>
