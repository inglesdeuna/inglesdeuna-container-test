<?php
session_start();

/* ---------- CONFIG ---------- */
$data = json_decode(file_get_contents("words.json"), true);
$words = $data["default"];
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
<title>Docente – Juegos y Flipbooks</title>

<style>
body{
  font-family:"Comic Sans MS", Arial;
  background:#eaf6ff;
  margin:0;
  padding:20px;
  text-align:center;
}

h1{
  color:#4a90e2;
  margin:10px 0;
}

.stage{
  height:320px;
  display:flex;
  justify-content:center;
  align-items:center;
}

#hangman{
  max-height:300px;
}

.hint{
  font-size:18px;
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

.win{ color:#2ecc71; font-size:28px; }
.lose{ color:#e74c3c; font-size:24px; }

section{
  max-width:1000px;
  margin:40px auto;
  background:white;
  padding:30px;
  border-radius:16px;
  box-shadow:0 8px 18px rgba(0,0,0,.15);
}
</style>
</head>

<body>

<h1>🎯 Hangman – Docente</h1>

<div class="stage">
  <img id="hangman" src="<?php echo $img; ?>">
</div>

<p class="hint"><strong>Hint:</strong> <?php echo $_SESSION['hint']; ?></p>
<p class="word"><?php echo $display; ?></p>

<?php if ($won): ?>
  <div class="win">🎉 CONGRATULATIONS 🎉</div>
<?php elseif ($lost): ?>
  <div class="lose">❌ Game Over – <?php echo $_SESSION['word']; ?></div>
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

<!-- ================= FLIPBOOK DOCENTE ================= -->

<section>
  <h2 style="color:#2a6edb;">📘 Flipbook – Editor Docente</h2>
  <p>
    Aquí puedes subir un PDF, editar el nombre de la lección y generar el flipbook
    que verán los estudiantes.
  </p>

  <iframe
    src="../flipbook.php"
    style="
      width:100%;
      height:780px;
      border:none;
      border-radius:12px;
      background:#f9f9f9;
    ">
  </iframe>
</section>

</body>
</html>
