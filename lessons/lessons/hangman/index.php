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

$display = "";
$won = true;
foreach (str_split($_SESSION['word']) as $c) {
  if (in_array($c, $_SESSION['guessed'])) {
    $display .= $c . " ";
  } else {
    $display .= "_ ";
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

#hangmanImg {
  width: 250px;
  transition: transform 0.25s ease;
}

.shake {
  transform: translateX(-12px);
}

.bounce {
  transform: scale(1.05);
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

.win { color: green; font-size: 26px; }
.lose { color: red; font-size: 26px; }

#soundToggle {
  position: fixed;
  top: 12px;
  right: 14px;
  font-size: 22px;
  cursor: pointer;
}
</style>
</head>

<body>

<div id="soundToggle">🔊</div>

<h1>🎯 Hangman – InglesDeUna</h1>

<img id="hangmanImg" src="<?php echo $img; ?>"><br><br>

<p><strong>Hint:</strong> <?php echo $_SESSION['hint']; ?></p>
<p class="word"><?php echo $display; ?></p>
<p>Wrong attempts: <?php echo $_SESSION['wrong']; ?> / <?php echo $maxWrong; ?></p>

<?php if ($won): ?>
  <p class="win">🎉 YOU WIN!</p>
<?php elseif ($lost): ?>
  <p class="lose">❌ GAME OVER</p>
  <p>The word was: <strong><?php echo $_SESSION['word']; ?></strong></p>
<?php else: ?>
<form method="post">
<?php foreach (range('A','Z') as $l): ?>
<button name="letter" value="<?php echo $l; ?>" <?php echo in_array($l,$_SESSION['guessed'])?'disabled':''; ?>>
<?php echo $l; ?>
</button>
<?php endforeach; ?>
</form>
<?php endif; ?>

<form method="post">
<button name="reset">🔄 Try Again</button>
</form>

<!-- AUDIO -->
<audio id="ok" src="https://cdn.pixabay.com/audio/2022/03/15/audio_115b9fbb97.mp3"></audio>
<audio id="bad" src="https://cdn.pixabay.com/audio/2022/03/15/audio_c8b6d8b0c6.mp3"></audio>
<audio id="win" src="https://cdn.pixabay.com/audio/2022/10/16/audio_59f6f94d1e.mp3"></audio>
<audio id="lose" src="https://cdn.pixabay.com/audio/2022/03/10/audio_3c9e4f1b75.mp3"></audio>

<script>
let sound = localStorage.getItem("sound") !== "off";
const toggle = document.getElementById("soundToggle");
toggle.textContent = sound ? "🔊" : "🔇";

toggle.onclick = () => {
  sound = !sound;
  localStorage.setItem("sound", sound ? "on" : "off");
  toggle.textContent = sound ? "🔊" : "🔇";
};

const img = document.getElementById("hangmanImg");

<?php if (isset($_POST['letter'])): ?>
<?php if (strpos($_SESSION['word'], $_POST['letter']) !== false): ?>
  if (sound) document.getElementById("ok").play();
  img.classList.add("bounce");
<?php else: ?>
  if (sound) document.getElementById("bad").play();
  img.classList.add("shake");
<?php endif; ?>
setTimeout(()=>img.className="",250);
<?php endif; ?>

<?php if ($won): ?>
if (sound) document.getElementById("win").play();
<?php endif; ?>

<?php if ($lost): ?>
if (sound) document.getElementById("lose").play();
<?php endif; ?>
</script>

</body>
</html>
