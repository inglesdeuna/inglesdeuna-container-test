<?php
session_start();

$words = [
  ["word" => "APPLE", "hint" => "A fruit 🍎"],
  ["word" => "HOUSE", "hint" => "A place to live 🏠"],
  ["word" => "TEACHER", "hint" => "Works at a school 👩‍🏫"],
  ["word" => "DOG", "hint" => "A friendly animal 🐶"]
];

$maxWrong = 7;

/* INIT / RESET */
if (!isset($_SESSION['word']) || isset($_POST['reset'])) {
  $item = $words[array_rand($words)];
  $_SESSION['word'] = $item['word'];
  $_SESSION['hint'] = $item['hint'];
  $_SESSION['guessed'] = [];
  $_SESSION['wrong'] = 0;
}

$word = $_SESSION['word'];
$hint = $_SESSION['hint'];

/* AJAX */
if (isset($_POST['ajax']) && isset($_POST['letter'])) {
  $letter = $_POST['letter'];
  $correct = false;

  if (!in_array($letter, $_SESSION['guessed'])) {
    $_SESSION['guessed'][] = $letter;
    if (strpos($word, $letter) === false) {
      $_SESSION['wrong']++;
    } else {
      $correct = true;
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

  echo json_encode([
    "display" => trim($display),
    "wrong" => $_SESSION['wrong'],
    "won" => $won,
    "lost" => $lost,
    "correct" => $correct,
    "word" => $word,
    "img" => "/lessons/lessons/hangman/assets/hangman" . $_SESSION['wrong'] . ".png"
  ]);
  exit;
}

/* INITIAL */
$display = '';
foreach (str_split($word) as $char) {
  $display .= '_ ';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Hangman – InglesDeUna</title>

<style>
body { font-family: Arial; text-align: center; }
.word { font-size: 32px; letter-spacing: 6px; }
button { padding: 8px 12px; margin: 3px; font-size: 16px; }

.win { color: green; font-size: 26px; }
.lose { color: red; font-size: 26px; }

#hangmanImg {
  width: 250px;
  transition: transform 0.3s ease, opacity 0.2s ease;
}

.shake {
  animation: shake 0.4s;
}

.bounce {
  animation: bounce 0.4s;
}

@keyframes shake {
  0% { transform: translateX(0); }
  25% { transform: translateX(-6px); }
  50% { transform: translateX(6px); }
  75% { transform: translateX(-6px); }
  100% { transform: translateX(0); }
}

@keyframes bounce {
  0% { transform: scale(1); }
  50% { transform: scale(1.1); }
  100% { transform: scale(1); }
}
</style>
</head>

<body>

<h1>🎯 Hangman – InglesDeUna</h1>

<img id="hangmanImg"
     src="/lessons/lessons/hangman/assets/hangman0.png"><br><br>

<p><strong>Hint:</strong> <?php echo $hint; ?></p>

<p class="word" id="wordDisplay"><?php echo trim($display); ?></p>

<p id="wrongCount">Wrong attempts: 0 / <?php echo $maxWrong; ?></p>

<div id="message"></div>

<div id="letters">
<?php foreach (range('A','Z') as $l): ?>
  <button id="btn_<?php echo $l; ?>" onclick="guess('<?php echo $l; ?>')">
    <?php echo $l; ?>
  </button>
<?php endforeach; ?>
</div>

<form method="post">
  <button name="reset">🔄 Try Again</button>
</form>

<!-- 🔊 Sounds -->
<audio id="soundCorrect" preload="auto">
  <source src="https://cdn.pixabay.com/audio/2022/03/15/audio_115b9fbb97.mp3" type="audio/mpeg">
</audio>

<audio id="soundWrong" preload="auto">
  <source src="https://cdn.pixabay.com/audio/2022/03/15/audio_c8b6d8b0c6.mp3" type="audio/mpeg">
</audio>


<script>
/* Preload images */
for (let i = 0; i <= <?php echo $maxWrong; ?>; i++) {
  const img = new Image();
  img.src = "/lessons/lessons/hangman/assets/hangman" + i + ".png";
}

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
    }, 60);

   if (data.correct) {
  const ok = document.getElementById("soundCorrect");
  ok.currentTime = 0;
  ok.play();

  img.classList.add("bounce");
  setTimeout(() => img.classList.remove("bounce"), 400);
} else {
  const bad = document.getElementById("soundWrong");
  bad.currentTime = 0;
  bad.play();

  img.classList.add("shake");
  setTimeout(() => img.classList.remove("shake"), 400);
}


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
<!-- Sounds -->
<audio id="soundCorrect" preload="auto">
  <source src="https://cdn.pixabay.com/audio/2022/03/15/audio_115b9fbb97.mp3" type="audio/mpeg">
</audio>

<audio id="soundWrong" preload="auto">
  <source src="https://cdn.pixabay.com/audio/2022/03/15/audio_c8b6d8b0c6.mp3" type="audio/mpeg">
</audio>

</body>
</html>
