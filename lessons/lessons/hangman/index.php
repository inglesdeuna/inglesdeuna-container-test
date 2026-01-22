<?php
session_start();

$words = [
  ["word"=>"APPLE","hint"=>"A fruit 🍎"],
  ["word"=>"HOUSE","hint"=>"A place to live 🏠"],
  ["word"=>"TEACHER","hint"=>"Works at a school 👩‍🏫"],
  ["word"=>"DOG","hint"=>"A friendly animal 🐶"]
];

$maxWrong = 7;

if (!isset($_SESSION['word']) || isset($_POST['reset'])) {
  $w = $words[array_rand($words)];
  $_SESSION['word'] = $w['word'];
  $_SESSION['hint'] = $w['hint'];
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

$word   = $_SESSION['word'];
$wrong  = $_SESSION['wrong'];
$hint   = $_SESSION['hint'];
$won    = true;
$display = "";

foreach (str_split($word) as $c) {
  if (in_array($c, $_SESSION['guessed'])) {
    $display .= $c." ";
  } else {
    $display .= "_ ";
    $won = false;
  }
}

$lost = $wrong >= $maxWrong;
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Hangman – InglesDeUna</title>

<style>
body{font-family:Arial;text-align:center}
.word{font-size:32px;letter-spacing:6px}
button{padding:8px 12px;margin:3px;font-size:16px}
#hangman{width:250px;transition:transform .25s ease}
.ok{transform:scale(1.05)}
.bad{transform:translateX(-12px)}
#sound{position:fixed;top:15px;right:15px;font-size:22px;cursor:pointer}
.win{color:green;font-size:26px}
.lose{color:red;font-size:26px}
</style>
</head>

<body>

<div id="sound">🔊</div>

<h1>🎯 Hangman – InglesDeUna</h1>

<img id="hangman" src="assets/hangman<?= $wrong ?>.png">

<p><strong>Hint:</strong> <?= $hint ?></p>
<p class="word"><?= $display ?></p>
<p>Wrong attempts: <?= $wrong ?> / <?= $maxWrong ?></p>

<?php if ($won): ?>
  <p class="win">🎉 CONGRATULATIONS!</p>
<?php elseif ($lost): ?>
  <p class="lose">❌ GAME OVER</p>
  <p>The word was: <strong><?= $word ?></strong></p>
<?php else: ?>
<div id="letters">
<?php foreach(range('A','Z') as $l): ?>
<button onclick="guess('<?= $l ?>')" <?= in_array($l,$_SESSION['guessed'])?'disabled':'' ?>>
<?= $l ?>
</button>
<?php endforeach; ?>
</div>
<?php endif; ?>

<form method="post">
  <button name="reset">🔄 Try Again</button>
</form>

<script>
/* ---------- SOUND (NO FILES, NO LINKS) ---------- */
let soundOn = localStorage.getItem("sound") !== "off";
const icon = document.getElementById("sound");
icon.textContent = soundOn ? "🔊" : "🔇";

icon.onclick = () => {
  soundOn = !soundOn;
  localStorage.setItem("sound", soundOn ? "on" : "off");
  icon.textContent = soundOn ? "🔊" : "🔇";
};

const ctx = new (window.AudioContext || window.webkitAudioContext)();

function beep(freq, time){
  if(!soundOn) return;
  const o = ctx.createOscillator();
  const g = ctx.createGain();
  o.frequency.value = freq;
  g.gain.value = 0.15;
  o.connect(g);
  g.connect(ctx.destination);
  o.start();
  setTimeout(()=>o.stop(), time);
}

function sOk(){beep(700,120)}
function sBad(){beep(200,200)}
function sWin(){beep(900,150);setTimeout(()=>beep(1200,200),180)}
function sLose(){beep(150,400)}

/* ---------- GAME FLOW ---------- */
function guess(letter){
  fetch("index.php",{
    method:"POST",
    headers:{"Content-Type":"application/x-www-form-urlencoded"},
    body:"letter="+letter
  })
  .then(r=>r.text())
  .then(html=>{
    document.open();
    document.write(html);
    document.close();
  });
}

const img = document.getElementById("hangman");

<?php if(isset($_POST['letter'])): ?>
<?php if(strpos($word,$_POST['letter'])!==false): ?>
  sOk(); img.classList.add("ok");
<?php else: ?>
  sBad(); img.classList.add("bad");
<?php endif; ?>
setTimeout(()=>img.className="",250);
<?php endif; ?>

<?php if($won): ?> sWin(); <?php endif; ?>
<?php if($lost): ?> sLose(); <?php endif; ?>
</script>

</body>
</html>
