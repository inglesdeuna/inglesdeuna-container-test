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
<title>Hangman Kids – InglesDeUna</title>

<style>
body{
  font-family: "Comic Sans MS", Arial;
  background: linear-gradient(#e8f4ff, #ffffff);
  text-align:center;
  overflow:hidden;
}

h1{ color:#4a90e2; }

.stage{
  height:260px;
  display:flex;
  justify-content:center;
  align-items:center;
}

#hangmanImg{
  width:220px;
  transition: opacity .15s ease, transform .15s ease;
}

.bounce{ animation:bounce .3s; }
.shake{ animation:shake .3s; }

@keyframes bounce{
  0%{transform:scale(1);}
  50%{transform:scale(1.05);}
  100%{transform:scale(1);}
}

@keyframes shake{
  0%{transform:translateX(0);}
  25%{transform:translateX(-4px);}
  50%{transform:translateX(4px);}
  75%{transform:translateX(-4px);}
  100%{transform:translateX(0);}
}

.word{
  font-size:34px;
  letter-spacing:6px;
}

.letters button{
  padding:8px 12px;
  margin:3px;
  border-radius:10px;
  border:1px solid #aaa;
  cursor:pointer;
}

.win{
  color:green;
  font-size:28px;
  animation:bounce .6s infinite alternate;
}

.lose{
  color:red;
  font-size:26px;
}

button.reset{
  margin-top:12px;
  padding:10px 16px;
  font-size:16px;
}
</style>
</head>

<body>

<h1>🎯 Hangman Kids</h1>

<div class="stage">
  <img id="hangmanImg" src="<?php echo $img; ?>">
</div>

<!-- HINT SOLO AQUÍ -->
<p><strong>Hint:</strong> <?php echo $hint; ?></p>

<p class="word"><?php echo $display; ?></p>

<p>Wrong attempts: <?php echo $_SESSION['wrong']; ?> / <?php echo $maxWrong; ?></p>

<div id="message">
<?php if ($won): ?>
  <p class="win">🎉 CONGRATULATIONS! 🎉</p>
<?php elseif ($lost): ?>
  <p class="lose">❌ Game Over</p>
  <p>The word was: <strong><?php echo $word; ?></strong></p>
<?php endif; ?>
</div>

<?php if (!$won && !$lost): ?>
<form method="post" class="letters">
<?php foreach (range('A','Z') as $l): ?>
  <button name="letter" value="<?php echo $l; ?>"
  <?php echo in_array($l,$_SESSION['guessed'])?'disabled':''; ?>>
  <?php echo $l; ?>
  </button>
<?php endforeach; ?>
</form>
<?php endif; ?>

<form method="post">
  <button class="reset" name="reset">🔄 Try Again</button>
</form>

<!-- AUDIO FILES EXISTENTES -->
<audio id="sndCorrect" src="assets/correct.wav"></audio>
<audio id="sndWrong" src="assets/wrong.wav"></audio>
<audio id="sndWin" src="assets/win.wav"></audio>
<audio id="sndLose" src="assets/lose.wav"></audio>

<script>
const img = document.getElementById("hangmanImg");

<?php if(isset($_POST['letter'])): ?>
<?php if(strpos($word, $_POST['letter']) !== false): ?>
  document.getElementById("sndCorrect").volume = 0.25;
  document.getElementById("sndCorrect").play();
  img.classList.add("bounce");
<?php else: ?>
  document.getElementById("sndWrong").volume = 0.25;
  document.getElementById("sndWrong").play();
  img.classList.add("shake");
<?php endif; ?>
setTimeout(()=>img.className="",300);
<?php endif; ?>

<?php if($won): ?>
  document.getElementById("sndWin").volume = 0.35;
  document.getElementById("sndWin").play();
<?php endif; ?>

<?php if($lost): ?>
  document.getElementById("sndLose").volume = 0.35;
  document.getElementById("sndLose").play();
<?php endif; ?>
</script>

</body>
</html>
