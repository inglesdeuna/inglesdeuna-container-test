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
  $l = $_POST['letter'];
  if (!in_array($l, $_SESSION['guessed'])) {
    $_SESSION['guessed'][] = $l;
    if (strpos($word, $l) === false) {
      $_SESSION['wrong']++;
    }
  }
}

$display = "";
$won = true;
foreach (str_split($word) as $c) {
  if (in_array($c, $_SESSION['guessed'])) {
    $display .= $c . " ";
  } else {
    $display .= "_ ";
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
<title>Hangman Kids – InglesDeUna</title>

<style>
body{
  font-family: "Comic Sans MS", Arial;
  background:#e8f4ff;
  text-align:center;
}

h1{ color:#4a90e2; }

.stage{
  height:380px;
  display:flex;
  justify-content:center;
  align-items:flex-start;
  margin-top:10px;
}

#hangmanImg{
  max-height:300px;
  transition:opacity 0.25s ease-in-out, transform 0.25s ease-in-out;
  margin-bottom:20px;
}

.word{
  font-size:34px;
  letter-spacing:8px;
}

button{
  padding:8px 12px;
  margin:4px;
  font-size:16px;
  border-radius:10px;
  border:1px solid #bbb;
  background:white;
  cursor:pointer;
}

button:disabled{
  opacity:0.4;
}

.win{
  color:#1a9c3c;
  font-size:28px;
}

.lose{
  color:#e53935;
  font-size:26px;
}

.bounce{
  transform:scale(1.08);
}

.shake{
  transform:translateX(-6px);
}
</style>
</head>

<body>

<h1>🎯 Hangman Kids – InglesDeUna</h1>

<div class="stage">
  <img id="hangmanImg" src="<?php echo $img; ?>">
</div>

<p><strong>Hint:</strong> <?php echo $hint; ?></p>

<p class="word"><?php echo $display; ?></p>
<p>Wrong attempts: <?php echo $_SESSION['wrong']; ?> / <?php echo $maxWrong; ?></p>

<?php if ($won): ?>
  <p class="win">🎉 CONGRATULATIONS! 👏👏</p>
<?php elseif ($lost): ?>
  <p class="lose">❌ GAME OVER</p>
  <p>The word was: <strong><?php echo $word; ?></strong></p>
<?php else: ?>
<form method="post">
<?php foreach(range('A','Z') as $l): ?>
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

<!-- SOUNDS -->
<audio id="sCorrect" src="assets/correct.wav"></audio>
<audio id="sWrong" src="assets/wrong.wav"></audio>
<audio id="sWin" src="assets/win.wav"></audio>
<audio id="sLose" src="assets/lose.wav"></audio>

<script>
const correct = <?php echo isset($_POST['letter']) && strpos($word,$_POST['letter'])!==false ? 'true':'false'; ?>;
const won = <?php echo $won?'true':'false'; ?>;
const lost = <?php echo $lost?'true':'false'; ?>;

function play(id, vol){
  const a = document.getElementById(id);
  a.volume = vol;
  a.currentTime = 0;
  a.play();
}

const img = document.getElementById("hangmanImg");

if(correct){
  play("sCorrect",0.5);
  img.classList.add("bounce");
  setTimeout(()=>img.classList.remove("bounce"),300);
}else if(<?php echo isset($_POST['letter'])?'true':'false'; ?>){
  play("sWrong",0.6);
  img.classList.add("shake");
  setTimeout(()=>img.classList.remove("shake"),300);
}

if(won){ play("sWin",0.7); }
if(lost){ play("sLose",0.7); }
</script>

</body>
</html>
