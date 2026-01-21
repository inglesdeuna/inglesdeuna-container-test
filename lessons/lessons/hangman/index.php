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

if (isset($_POST['ajax'])) {
  $letter = $_POST['letter'] ?? null;

  if ($letter && !in_array($letter, $_SESSION['guessed'])) {
    $_SESSION['guessed'][] = $letter;
    if (strpos($_SESSION['word'], $letter) === false) {
      $_SESSION['wrong']++;
      $correct = false;
    } else {
      $correct = true;
    }
  } else {
    $correct = true;
  }

  $display = '';
  $won = true;
  foreach (str_split($_SESSION['word']) as $c) {
    if (in_array($c, $_SESSION['guessed'])) {
      $display .= $c . ' ';
    } else {
      $display .= '_ ';
      $won = false;
    }
  }

  $lost = $_SESSION['wrong'] >= $maxWrong;

  echo json_encode([
    "display" => $display,
    "wrong" => $_SESSION['wrong'],
    "img" => "/lessons/lessons/hangman/assets/hangman" . $_SESSION['wrong'] . ".png",
    "correct" => $correct,
    "won" => $won,
    "lost" => $lost,
    "word" => $_SESSION['word']
  ]);
  exit;
}

$display = '';
foreach (str_split($_SESSION['word']) as $c) {
  $display .= in_array($c, $_SESSION['guessed']) ? $c . ' ' : '_ ';
}
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
  letter-spacing: 6px;
}

button {
  padding: 8px 12px;
  margin: 3px;
  font-size: 16px;
  cursor: pointer;
}

img {
  width: 250px;
  transition: opacity .25s ease;
}

.bounce {
  animation: bounce .4s;
}

.shake {
  animation: shake .4s;
}

@keyframes bounce {
  0% { transform: scale(1); }
  50% { transform: scale(1.1); }
  100% { transform: scale(1); }
}

@keyframes shake {
  0% { transform: translateX(0); }
  25% { transform: translateX(-5px); }
  50% { transform: translateX(5px); }
  75% { transform: translateX(-5px); }
  100% { transform: translateX(0); }
}

.win { color: green; font-size: 26px; }
.lose { color: red; font-size: 26px; }
</style>
</head>

<body>

<h1>🎯 Hangman – InglesDeUna</h1>

<img id="hangmanImg" src="/lessons/lessons/hangman/assets/hangman<?php echo $_SESSION['wrong']; ?>.png">

<p><strong>Hint:</strong> <?php echo $_SESSION['hint']; ?></p>

<p id="wordDisplay" class="word"><?php echo $display; ?></p>

<p id="wrongCount">Wrong attempts: <?php echo $_SESSION['wrong']; ?> / <?php echo $maxWrong; ?></p>

<div id="letters">
<?php foreach (range('A','Z') as $l): ?>
  <button onclick="guess('<?php echo $l; ?>')" <?php echo in_array($l,$_SESSION['guessed'])?'disabled':''; ?>>
    <?php echo $l; ?>
  </button>
<?php endforeach; ?>
</div>

<div id="message"></div>

<form method="post">
  <button name="reset">🔄 Try Again</button>
</form>

<!-- Sounds -->
<audio id="soundCorrect" preload="auto">
  <source src="https://cdn.pixabay.com/audio/2022/03/15/audio_115b9fbb97.mp3" type="audio/mpeg">
</audio>

<audio id="soundWrong" preload="auto">
  <source src="https://cdn.pixabay.com/audio/2022/03/15/audio_c8b6d8b0c6.mp3" type="audio/mpeg">
</audio>

<script>
let audioUnlocked = false;

function unlockAudio() {
  if (audioUnlocked) return;

  const ok = document.getElementById("soundCorrect");
  const bad = document.getElementById("soundWrong");

  ok.muted = true;
  bad.muted = true;

  ok.play().then(() => {
    ok.pause();
    ok.currentTime = 0;
    ok.muted = false;
    bad.muted = false;
    audioUnlocked = true;
  }).catch(()=>{});
}

document.addEventListener("click", unlockAudio, { once:true });

function guess(letter) {
  fetch("", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: "ajax=1&letter=" + letter
  })
  .then(r => r.json())
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

    if (data.correct) {
      const ok = document.getElementById("soundCorrect");
      ok.currentTime = 0;
      ok.play();
      img.classList.add("bounce");
      setTimeout(()=>img.classList.remove("bounce"),400);
    } else {
      const bad = document.getElementById("soundWrong");
      bad.currentTime = 0;
      bad.play();
      img.classList.add("shake");
      setTimeout(()=>img.classList.remove("shake"),400);
    }

    if (data.won) {
      document.getElementById("message").innerHTML =
        '<p class="win">🎉 CONGRATULATIONS!</p>';
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
