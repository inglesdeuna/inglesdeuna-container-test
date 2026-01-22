<?php
session_start();

/* ---------- CONFIG ---------- */
$words = [
  ["word"=>"APPLE","hint"=>"A fruit 🍎"],
  ["word"=>"HOUSE","hint"=>"A place to live 🏠"],
  ["word"=>"DOG","hint"=>"A friendly animal 🐶"],
  ["word"=>"CAT","hint"=>"A cute pet 🐱"]
];
$maxWrong = 7;

/* ---------- INIT ---------- */
if (!isset($_SESSION['word']) || isset($_POST['reset'])) {
  $pick = $words[array_rand($words)];
  $_SESSION['word'] = $pick['word'];
  $_SESSION['hint'] = $pick['hint'];
  $_SESSION['guessed'] = [];
  $_SESSION['wrong'] = 0;
}

/* ---------- INPUT ---------- */
if (isset($_POST['letter'])) {
  $l = $_POST['letter'];
  if (!in_array($l, $_SESSION['guessed'])) {
    $_SESSION['guessed'][] = $l;
    if (strpos($_SESSION['word'], $l) === false) {
      $_SESSION['wrong']++;
    }
  }
}

/* ---------- STATE ---------- */
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
$img = "assets/hangman".$_SESSION['wrong'].".png";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Hangman Kids</title>

<style>
body{
  font-family:"Comic Sans MS", Arial;
  background:#eaf6ff;
  text-align:center;
  margin:0;
}

h1{
  color:#4a90e2;
  margin:20px 0 10px;
}

.stage{
  height:320px;
  display:flex;
  justify-content:center;
  align-items:center;
}

#hangman{
  max-height:300px;
  transition:transform .15s ease;
}

.hint{
  font-size:18px;
  margin:8px 0;
}

.word{
  font-size:36px;
  letter-spacing:10px;
  margin:18px 0;
}

.letters button{
  padding:8px 12px;
  margin:3px;
  border-radius:10px;
  border:1px solid #aaa;
  cursor:pointer;
}

.letters button:disabled{
  opacity:.4;
}

.win{
  color:#2ecc71;
  font-size:30px;
  animation:pop .6s infinite alternate;
}

.lose{
  color:#e74c3c;
  font-size:26px;
}

@keyframes pop{
  from{transform:scale(1);}
  to{transform:scale(1.12);}
}

.bounce{ transform:scale(1.05); }
.shake{ transform:translateX(-6px); }
</style>
</head>

<body>

<h1>🎯 Hangman Kids</h1>

<div class="stage">
  <img id="hangman" src="<?php echo $img; ?>" alt="hangman">
</div>

<p class="hint"><strong>Hint:</strong> <?php echo $_SESSION['hint']; ?></p>

<p class="word"><?php echo $display; ?></p>

<?php if ($won): ?>
  <div class="win">🎉 CONGRATULATIONS! 🎉</div>
<?php elseif ($lost): ?>
  <div class="lose">❌ Game Over</div>
  <p>The word was: <strong><?php echo $_SESSION['word']; ?></strong></p>
<?php else: ?>
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
  <button name="reset">🔄 Try Again</button>
</form>

<!-- AUDIO -->
<audio id="sndCorrect" src="assets/correct.wav" preload="auto"></audio>
<audio id="sndWrong"   src="assets/wrong.wav"   preload="auto"></audio>
<audio id="sndWin"     src="assets/win.mp3"     preload="auto"></audio>
<audio id="sndLose"    src="assets/lose.mp3"    preload="auto"></audio>

<script>
/* volumes */
sndCorrect.volume = 0.3;
sndWrong.volume   = 0.4;
sndWin.volume     = 0.6;
sndLose.volume    = 0.6;

/* preload images (anti-flash) */
for(let i=0;i<=7;i++){
  const im=new Image();
  im.src=`assets/hangman${i}.png`;
}

const img=document.getElementById("hangman");

/* play feedback */
<?php if (isset($_POST['letter'])): ?>
<?php if (strpos($_SESSION['word'], $_POST['letter']) !== false): ?>
  sndCorrect.currentTime=0; sndCorrect.play();
  img.classList.add("bounce");
<?php else: ?>
  sndWrong.currentTime=0; sndWrong.play();
  img.classList.add("shake");
<?php endif; ?>
setTimeout(()=>img.className="",200);
<?php endif; ?>

<?php if ($won): ?>
  sndWin.currentTime=0; sndWin.play();
<?php endif; ?>

<?php if ($lost): ?>
  sndLose.currentTime=0; sndLose.play();
<?php endif; ?>
</script>

</body>
</html>
