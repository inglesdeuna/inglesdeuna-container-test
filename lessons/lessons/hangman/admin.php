<?php
$file = "words.json";
$data = file_exists($file)
  ? json_decode(file_get_contents($file), true)
  : ["default" => []];
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Hangman – Teacher Panel</title>
<style>
body{
  font-family: Arial;
  background:#eef6ff;
  padding:40px;
}
.panel{
  max-width:400px;
  background:white;
  padding:20px;
  border-radius:12px;
}
input, button{
  width:100%;
  padding:10px;
  margin:8px 0;
}
</style>
</head>

<body>

<div class="panel">
<h2>🎓 Teacher Panel – Hangman</h2>

<form method="post" action="save_word.php">
  <input name="word" placeholder="WORD (example: CAT)" required>
  <input name="hint" placeholder="Hint (example: A pet 🐱)" required>
  <button type="submit">➕ Add word</button>
</form>

<hr>

<h3>📚 Current words</h3>
<ul>
<?php foreach($data["default"] as $w): ?>
  <li>
  <strong><?= $w["word"] ?></strong><br>
  <small><?= $w["hint"] ?></small><br>
  <span style="letter-spacing:8px;font-size:18px;">
   <?= str_repeat("_ ", strlen($w["word"])) ?>
  </span>
</li>

<?php endforeach; ?>
</ul>

</div>

</body>
</html>
