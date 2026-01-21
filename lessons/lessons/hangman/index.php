<?php
session_start();

/* ========= CONFIG ========= */
$word = "APPLE"; // palabra a adivinar (en mayúsculas)
$maxAttempts = 6;
/* ========================== */

if (!isset($_SESSION['attempts'])) {
    $_SESSION['attempts'] = [];
    $_SESSION['wrong'] = 0;
}

if (isset($_POST['letter'])) {
    $letter = strtoupper($_POST['letter']);
    if (!in_array($letter, $_SESSION['attempts'])) {
        $_SESSION['attempts'][] = $letter;
        if (strpos($word, $letter) === false) {
            $_SESSION['wrong']++;
        }
    }
}

if (isset($_POST['reset'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

$displayWord = "";
$won = true;

for ($i = 0; $i < strlen($word); $i++) {
    $char = $word[$i];
    if (in_array($char, $_SESSION['attempts'])) {
        $displayWord .= $char . " ";
    } else {
        $displayWord .= "_ ";
        $won = false;
    }
}

$lost = $_SESSION['wrong'] >= $maxAttempts;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Hangman – InglesDeUna</title>
    <style>
        body { font-family: Arial; text-align: center; margin-top: 40px; }
        .word { font-size: 32px; letter-spacing: 4px; }
        button { padding: 10px 15px; margin: 5px; font-size: 16px; }
        .wrong { color: red; }
        .win { color: green; }
    </style>
</head>
<body>

<h1>🎯 Hangman – InglesDeUna</h1>

<p class="word"><?php echo $displayWord; ?></p>

<p>Wrong attempts: <?php echo $_SESSION['wrong']; ?> / <?php echo $maxAttempts; ?></p>

<?php if ($won): ?>
    <p class="win">🎉 You win!</p>
<?php elseif ($lost): ?>
    <p class="wrong">❌ Game over. Word was: <strong><?php echo $word; ?></strong></p>
<?php else: ?>
    <form method="post">
        <?php foreach (range('A', 'Z') as $l): ?>
            <button type="submit" name="letter" value="<?php echo $l; ?>"
                <?php echo in_array($l, $_SESSION['attempts']) ? 'disabled' : ''; ?>>
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
