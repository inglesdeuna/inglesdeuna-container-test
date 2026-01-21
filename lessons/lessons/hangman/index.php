<?php
session_start();

$words = [
  ["word" => "APPLE", "hint" => "A fruit 🍎"],
  ["word" => "HOUSE", "hint" => "A place to live 🏠"],
  ["word" => "TEACHER", "hint" => "Works at a school 👩‍🏫"],
  ["word" => "DOG", "hint" => "A friendly animal 🐶"]
];

$maxWrong = 7;

/* ---------- RESET / INIT ---------- */
if (!isset($_SESSION['word']) || isset($_POST['reset'])) {
  $item = $words[array_rand($words)];
  $_SESSION['word'] = $item['word'];
  $_SESSION['hint'] = $item['hint'];
  $_SESSION['guessed'] = [];
  $_SESSION['wrong'] = 0;
}

$word = $_SESSION['word'];
$hint = $_SESSION['hint'];

/* ---------- AJAX LETTER ---------- */
if (isset($_POST['ajax']) && isset($_POST['letter'])) {
  $letter = $_POST['letter'];

  if (!in_array($letter, $_SESSION['guessed'])) {
    $_SESSION['guessed'][] = $letter;
    if (strpos($word, $letter) === false) {
      $_SESSION['wrong']++;
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

  echo json_encode([
    "display" => trim($display),
    "wrong" => $_SESSION['wrong'],
    "won" => $won,
    "lost" => $lost,
    "word" => $word,
    "img" => $img
  ]);
  exit;
}

/* ---------- INITIAL RENDER ---------- */
$display = '';
foreach (str_split($word) as $char) {
  $display .= '_ ';
}

$img = "/lessons/lessons/hangman/assets/hangman0.png";
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
.word {
  font-size: 32px;
  letter-spacing: 5px;
}
button {
  padding: 8px 12px;
  margin: 3px;
  font-size: 16px;
}
.win { color: green; font-size: 26px; }
.lose { color: red; font-size: 26px; }
img { transition: opacity 0.2s ease-in-out; }
</style>
</head>

<body>

<h1>🎯 Hangman – InglesDeUna</h1>

<img id="hangmanImg" src="<?php echo $img; ?>" width="250"><br><br>

<p><strong>Hint:</strong> <?php echo $hint; ?></p>

<p class="word" id="wordDisplay"><?php echo trim($display); ?></p>

<p id="wrongCount">Wrong attempts: 0 / <?php echo $maxWrong; ?></p>

<div id="message"></div>

<div id="letters">
<?php foreach (range('A','Z') as $l): ?>
  <button onclick="guess('<?php echo $l; ?>')" id="btn_<?php echo $l; ?>">
    <?php echo $l; ?>
  </button>
<?php endforeach; ?>
</div>

<form method="post">
  <button name="reset">🔄 Try Again</button>
</form>

<script>
/* -------- PRELOAD IMAGES -------- */
for (let i = 0; i <= <?php echo $maxWrong; ?>; i++) {
  const img = new Image();
  img.src = "/lessons/lessons/hangman/assets/hangman" + i + ".png";
}

/* -------- AJAX GUESS -------- */
function guess(letter) {
  document.getElementById("btn_" + letter).disabled = true;

  fetch("", {
    method: "POST",
    headers: {"Content-Type": "application/x-www-form-urlencoded"},
    body: "ajax=1&letter=" + letter
  })
  .then(res => res.json())
  .then(data => {
    document.getElementById("wordDisplay").innerText = data.display;
    document.getElementById("wrongCount").innerText =
      "Wrong attempts: " + data.wrong + " / <?php echo $maxWrong; ?>";

    const img = document.getElementById("hangmanImg");
    img.style.opacity = 0;
    setTimeout(() => {
      img.src = data.img;
      img.style.opacity = 1;
    }, 80);

    if (data.won) {
      document.getElementById("message").innerHTML =
        '<p class="win">🎉 CONGRATULATIONS! YOU WIN!</p>';
      document.getElementById("letters").style.display = "none";
    }

    if (data.lost) {
      document.getElementById("message").innerHTML =
        '<p class="lose">❌ Game Over<br>The word was: <strong>' + data.word + '</strong></p>';
      document.getElementById("letters").style.display = "none";
    }
  });
}
</script>

</body>
</html>

