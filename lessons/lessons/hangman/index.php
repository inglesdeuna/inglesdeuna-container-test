<?php
session_start();

$words = [
  ["word" => "APPLE", "hint" => "A fruit 🍎"],
  ["word" => "HOUSE", "hint" => "A place to live 🏠"],
  ["word" => "TEACHER", "hint" => "Works at a school 👩‍🏫"],
  ["word" => "DOG", "hint" => "A friendly animal 🐶"]
];

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
    if (!str_contains($word, $letter)) {
      $_SESSION['wrong']++;
    }
  }
}

$wrong = $_SESSION['wrong'];
$max = 6;

$display = "";
$won = true;
foreach (str_split($word) as $l) {
  if (in_array($l, $_SESSION['guessed'])) {
    $display .= $l . " ";
  } else {
    $display .= "_ ";
    $won = false;
  }
}

$lost = $wrong >= $max;
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Hangman – InglesDeUna</title>
<style>
body { font-family: Arial; text-align: center; }
.word { font-size: 32px; letter-spacing: 8px; }
button { margin: 4px; padding: 8px 12px; }
.win { color: green; font-size: 24px; }
.lose { color: red; font-size: 22px; }
.hint { font-style: italic; margin: 10px; }
</style>
</head>

<body>

<h1>🎯 Hangman – InglesDeUna</h1>

<img src="https://raw.githubusercontent.com/inglesdeuna/inglesdeuna-container-test/main/assets/hangman<?= $wrong ?>.png"
     alt="Hangman" width="200">

<p class="word"><?= $display ?></p>
<p>Wrong attempts: <?= $wrong ?> / <?= $max ?></p>

<p class="hint">💡 Hint: <?= $hint ?></p>

<?php if ($won): ?>
  <p class="win">🎉 CONGRATULATIONS!</p>
  <img src="https://media.giphy.com/media/111ebonMs90YLu/giphy.gif" width="200">
<?php elseif ($lost): ?>
  <p class="lose">❌ Try again! Word was: <b><?= $word ?></b></p>
<?php else: ?>
<form method="post">
<?php foreach (range('A','Z') as $l): ?>
  <button name="letter" value="<?= $l ?>"
    <?= in_array($l, $_SESSION['guessed']) ? "disabled" : "" ?>>
    <?= $l ?>
  </button>
<?php endforeach; ?>
</form>
<?php endif; ?>

<form method="post">
  <button name="reset">🔄 Reset</button>
</form>

</body>
</html>
