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
.win {
  color: green;
  font-size: 26px;
}
.lose {
  color: red;
  font-size: 26px;
}
</style>
</head>

<body>

<h1>🎯 Hangman – InglesDeUna</h1>

<img src="<?php echo $img; ?>" width="250"><br><br>

<p><strong>Hint:</strong> <?php echo $hint; ?></p>

<p class="word"><?php echo $display; ?></p>

<p>Wrong attempts: <?php echo $_SESSION['wrong']; ?> / <?php echo $maxWrong; ?></p>

<?php if ($won): ?>
  <p class="win">🎉 CONGRATULATIONS! YOU WIN!</p>
<?php elseif ($lost): ?>
  <p class="lose">❌ Game Over</p>
  <p>The word was: <strong><?php echo $word; ?></strong></p>
<?php else: ?>
  <form method="post">
    <?php foreach (range('A', 'Z') as $l): ?>
      <button name="letter" value="<?php echo $l; ?>"
        <?php echo in_array($l, $_SESSION['guessed']) ? 'disabled' : ''; ?>>
        <?php echo $l; ?>
      </button>
    <?php endforeach; ?>
  </form>
<?php endif; ?>

<form method="post">
  <button name="reset">🔄 Try Again</button>
</form>

</body>
</html>
