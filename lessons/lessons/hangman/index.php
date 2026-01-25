<?php
session_start();

/* ==============================
   HANGMAN CONFIG
================================ */
$data = json_decode(file_get_contents("words.json"), true);
$words = $data["default"];
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
$img = "assets/hangman".$_SESSION['wrong'].".png";
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>LET'S – Contenedor de Juegos</title>

<style>
body{
  font-family:Arial, sans-serif;
  background:#f2f7ff;
  margin:0;
  padding:30px;
}

h1{ color:#2a6edb; }
h2{ margin-top:40px; }

.section{
  background:white;
  border-radius:16px;
  padding:25px;
  margin-bottom:40px;
  box-shadow:0 10px 20px rgba(0,0,0,.15);
}

/* ---------- HANGMAN ---------- */
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
}

.word{
  font-size:36px;
  letter-spacing:10px;
}

.letters button{
  padding:8px 12px;
  margin:3px;
  border-radius:10px;
}

.win{ color:#2ecc71; font-size:28px; }
.lose{ color:#e74c3c; font-size:26px; }

iframe{
  width:100%;
  height:820px;
  border:none;
  border-radius:14px;
  background:#f9f9f9;
}
</style>
</head>

<body>

<h1>🎮 Contenedor General de Juegos – LET’S</h1>

<!-- =======================
     HANGMAN – EDITOR
======================= -->
<div class="section">
  <h2>🎯 Hangman – Editor / Preview</h2>

  <div class="stage">
    <img id="hangman" src="<?php echo $img; ?>">
  </div>

  <p class="hint"><strong>Hint:</strong> <?php echo $_SESSION['hint']; ?></p>
  <p class="word"><?php echo $display; ?></p>

<?php if ($won): ?>
  <div class="win">🎉 CONGRATULATIONS!</div>
<?php elseif ($lost): ?>
  <div class="lose">❌ Game Over</div>
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
</div>

<!-- =======================
     FLIPBOOK – EDITOR
======================= -->
<div class="section">
  <h2>📘 Flipbook – Editor Docente</h2>
<?php
$flipbooksFile = __DIR__ . "/../admin/flipbooks.json";
if (file_exists($flipbooksFile)):
  $flipbooks = json_decode(file_get_contents($flipbooksFile), true);
?>
<div style="margin:20px 0; text-align:left;">
  <h3>📚 Flipbooks creados</h3>

  <?php foreach ($flipbooks as $fb): ?>
    <div style="padding:10px; border-bottom:1px solid #ddd;">
      <strong><?php echo htmlspecialchars($fb["title"]); ?></strong><br>
      <a
        href="/lessons/lessons/admin/viewer.php?file=<?php echo urlencode($fb["file"]); ?>"
        target="_blank"
        style="color:#2a6edb;"
      >
        👉 Abrir visor del estudiante
      </a>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

  <iframe
  src="../admin/flipbook.php"
  style="
    width:100%;
    height:820px;
    border:none;
    border-radius:14px;
    background:#f9f9f9;
  ">
</iframe>

</div>

</body>
</html>
