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
<title>Hangman Kids</title>

<style>
body{
  font-family: "Comic Sans MS", Arial;
  background:#eaf6ff;
  text-align:center;
  margin:0;
  padding:0;
}

h1{
  color:#4a90e2;
  margin:20px 0;
}

.stage{
  height:300px;
  display:flex;
  justify-content:center;
  align-items:center;
}

#hangmanImg{
  max-height:280px;
  transition:opacity .15s ease-in-out;
}

.hint{
  margin-top:10px;
  font-size:18px;
}

.word{
  font-size:36px;
  letter-spacing:8px;
  margin:20px 0;
}

button{
  padding:8px 12px;
  margin:4px;
  font-size:16px;
  border-radius:8px;
  cursor:pointer;
}

.win{
  color:green;
  font-size:26px;
  animation:pop .6s ease-in-out infinite alternate;
}

.lose{
  color:red;
  font-size:26px;
}

@keyframes pop{
  from{transform:scale(1)}
  to{transform:scale(1.1)}
}
</style>
</head>

<body>

<h1>🎯 Hangman Kids</h1>

<div class="stage">
  <img id="hangmanImg" src="<?php echo $img; ?>">
</div>

<p class="hint"><strong>Hint:</strong> <?php echo $hint; ?></p>

<p class="word"><?php echo $display; ?></p>

<p>Wrong attempts: <?php echo $_SESSION['wrong']; ?> / <?php echo $maxWrong; ?></p>

<?php if ($won): ?>
  <p class="win">🎉 CONGRATULATIONS! 🎉</p>
<?php elseif ($lost): ?>
  <p class="lose">❌ Game Over</p>
  <p>The word was: <strong><?php echo $word; ?></strong></p>
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

<!-- AUDIO -->
<audio id="soundCorrect" src="assets/correct.wav" preload="auto"></audio>
<audio id="soundWrong" src="assets/wrong.wav" preload="auto"></audio>
<audio id="soundWin" src="assets/win.mp3" preload="auto"></audio>
<audio id="soundLose" src="assets/lose.mp3" preload="auto"></audio>

<script>
const buttons = document.querySelectorAll("button[name='letter']");
const img = document.getElementById("hangmanImg");

buttons.forEach(btn=>{
  btn.addEventListener("click",()=>{
    const correct = <?php echo json_encode(str_split($word)); ?>.includes(btn.value);

    if(correct){
      play("sndCorrect");
    }else{
      play("sndWrong");
    }
  });
});

<?php if($won): ?>
  play("sndWin");
<?php endif; ?>

<?php if($lost): ?>
  play("sndLose");
<?php endif; ?>

function play(id){
  const a = document.getElementById(id);
  a.volume = 0.35;
  a.currentTime = 0;
  a.play();
}
</script>

</body>
</html>
