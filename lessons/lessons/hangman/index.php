<?php
session_start();

$words = [
  ["word"=>"APPLE","hint"=>"A fruit 🍎"],
  ["word"=>"HOUSE","hint"=>"A place to live 🏠"],
  ["word"=>"DOG","hint"=>"A friendly animal 🐶"],
  ["word"=>"CAT","hint"=>"A cute pet 🐱"]
];

$maxWrong = 7;

if (!isset($_SESSION['word']) || isset($_POST['reset'])) {
  $pick = $words[array_rand($words)];
  $_SESSION['word'] = $pick['word'];
  $_SESSION['hint'] = $pick['hint'];
  $_SESSION['guessed'] = [];
  $_SESSION['wrong'] = 0;
}

if (isset($_POST['letter'])) {
  $l = $_POST['letter'];
  if (!in_array($l, $_SESSION['guessed'])) {
    $_SESSION['guessed'][] = $l;
    if (strpos($_SESSION['word'],$l) === false) {
      $_SESSION['wrong']++;
    }
  }
}

$display = "";
$won = true;
foreach(str_split($_SESSION['word']) as $c){
  if(in_array($c,$_SESSION['guessed'])) $display .= $c." ";
  else { $display .= "_ "; $won=false; }
}

$lost = $_SESSION['wrong'] >= $maxWrong;
$img = "/lessons/lessons/hangman/assets/hangman".$_SESSION['wrong'].".png";
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Hangman Kids</title>

<style>
body{
  font-family: "Comic Sans MS", Arial;
  background: linear-gradient(#e8f4ff,#ffffff);
  text-align:center;
  overflow:hidden;
}

h1{ color:#4a90e2; }

.stage{
  height:280px;
  display:flex;
  justify-content:center;
  align-items:center;
}

#hangman{
  width:240px;
  animation: float 1.4s ease-in-out infinite;
}

@keyframes float{
  0%{ transform: translateY(0); }
  50%{ transform: translateY(-10px); }
  100%{ transform: translateY(0); }
}

.word{
  font-size:42px;
  letter-spacing:14px;
  margin:20px 0;
}

button{
  padding:12px 16px;
  margin:4px;
  font-size:18px;
  border-radius:14px;
  border:none;
  background:#fff;
  box-shadow:0 4px 0 #cfe2ff;
  cursor:pointer;
}

button:disabled{ opacity:.4; }

.win{
  font-size:34px;
  color:#2ecc71;
  animation: pop 0.6s ease infinite alternate;
}

@keyframes pop{
  from{ transform: scale(1); }
  to{ transform: scale(1.12); }
}

.lose{
  font-size:28px;
  color:#e74c3c;
}

/* 🎉 CONFETTI */
.confetti{
  position:fixed;
  top:-10px;
  width:12px;
  height:12px;
  animation: fall linear infinite;
}
@keyframes fall{
  to{ transform: translateY(110vh) rotate(360deg); }
}
</style>
</head>

<body>

<h1>🎯 Hangman Kids</h1>

<div class="stage">
  <img id="hangman" src="<?=$img?>">
</div>

<p><strong>Hint:</strong> <?=$_SESSION['hint']?></p>
<p class="word"><?=$display?></p>

<?php if($won): ?>
  <div class="win">🎉 YOU WIN! 🎉</div>
<?php elseif($lost): ?>
  <div class="lose">😢 GAME OVER</div>
<?php else: ?>
<form method="post">
<?php foreach(range('A','Z') as $l): ?>
<button name="letter" value="<?=$l?>" <?=in_array($l,$_SESSION['guessed'])?'disabled':''?>>
<?=$l?>
</button>
<?php endforeach; ?>
</form>
<?php endif; ?>

<form method="post">
<button name="reset">🔄 New Word</button>
</form>

<!-- AUDIO (DIFERENCIADOS) -->
<audio id="ok"   src="assets/correct.wav"></audio>
<audio id="bad"  src="assets/wrong.wav"></audio>
<audio id="win"  src="assets/win.wav"></audio>
<audio id="lose" src="assets/lose.wav"></audio>

<script>
// volumen infantil
ok.volume   = 0.25;
bad.volume  = 0.25;
win.volume  = 0.4;
lose.volume = 0.4;

// precarga imágenes
for(let i=0;i<=7;i++){
  const im=new Image();
  im.src=`/lessons/lessons/hangman/assets/hangman${i}.png`;
}

<?php if(isset($_POST['letter'])): ?>
<?php if($won): ?>
  win.play();
  launchConfetti();
<?php elseif($lost): ?>
  lose.play();
<?php elseif(strpos($_SESSION['word'],$_POST['letter'])!==false): ?>
  ok.play();
<?php else: ?>
  bad.play();
<?php endif; ?>
<?php endif; ?>

// 🎉 CONFETTI SCRIPT
function launchConfetti(){
  for(let i=0;i<40;i++){
    const c=document.createElement("div");
    c.className="confetti";
    c.style.left=Math.random()*100+"vw";
    c.style.background=`hsl(${Math.random()*360},80%,60%)`;
    c.style.animationDuration=2+Math.random()*2+"s";
    document.body.appendChild(c);
    setTimeout(()=>c.remove(),4000);
  }
}
</script>

</body>
</html>
