<?php
session_start();

/* ---------- CONFIGURACIÓN ---------- */
$words = ["APPLE", "BANANA", "ORANGE", "SCHOOL", "TEACHER", "ENGLISH"];
$maxAttempts = 6;

/* ---------- RESET ---------- */
if (isset($_POST['reset'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

/* ---------- INICIALIZACIÓN ---------- */
if (!isset($_SESSION['word'])) {
    $_SESSION['word'] = $words[array_rand($words)];
    $_SESSION['guessed'] = [];
    $_SESSION['attempts'] = $maxAttempts;
}

$word = $_SESSION['word'];
$guessed = $_SESSION['guessed'];
$attempts = $_SESSION['attempts'];

/* ---------- JUGADA ---------- */
if (isset($_POST['letter'])) {
    $letter = $_POST['letter'];

    if (!in_array($letter, $guessed)) {
        $_SESSION['guessed'][] = $letter;
        if (strpos($word, $letter) === false) {
            $_SESSION['attempts']--;
        }
    }
}

/* ---------- ESTADO ---------- */
$displayWord = "";
$won = true;

foreach (str_split($word) as $char) {
    if (in_array($char, $_SESSION['guessed'])) {
        $displayWord .= $char . " ";
    } else {
        $displayWord .= "_ ";
        $won = false;
    }
}

$lost = $_SESSION['attempts'] <= 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Hangman Game</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            text-align: center;
            background: #f4f6f8;
        }
        h1 {
            color: #333;
        }
        .word {
            font-size: 32px;
            letter-spacing: 5px;
            margin: 20px;
        }
        .letters button {
            margin: 4px;
            padding: 10px 14px;
            font-size: 14px;
            cursor: pointer;
        }
        .info {
            font-size: 18px;
            margin: 10px;
        }
        .win {
            color: green;
            font-size: 22px;
        }
        .wrong {
            color: red;
            font-size: 22px;
        }
    </style>
</head>
<body>

<h1>🎯 Hangman</h1>

<p class="word"><?php echo $displayWord; ?></p>

<p class="info">Attempts left: <?php echo $_SESSION['attempts']; ?></p>

<?php if ($won): ?>
    <p class="win">🎉 You win!</p>

<?php elseif ($lost): ?>
    <p class="wrong">❌ Game over. Word was: <strong><?php echo $word; ?></strong></p>

<?php else: ?>
    <form method="post" class="letters">
        <?php foreach (range('A', 'Z') as $l): ?>
            <button type="submit" name="letter" value="<?php echo $l; ?>"
                <?php echo in_array($l, $_SESSION['guessed']) ? 'disabled' : ''; ?>>
                <?php echo $l; ?>
            </button>
        <?php endforeach; ?>
    </form>
<?php endif; ?>

<form method="post">
    <button type="submit" name="reset">🔄 Reset</button>
</form>

</body>
</html>
