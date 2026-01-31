<?php
$file = __DIR__ . "/flashcards.json";
$data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Flashcards</title>
<style>
body{font-family:Arial;background:#eaf2ff;text-align:center}
.card{
  background:#fff;
  max-width:420px;
  margin:60px auto;
  padding:25px;
  border-radius:16px;
  box-shadow:0 10px 25px rgba(0,0,0,.15);
}
img{max-width:100%;border-radius:10px;margin-top:10px}
button{padding:10px 14px;margin:10px;border:none;border-radius:8px;background:#2563eb;color:#fff}
</style>
</head>
<body>

<?php if (empty($data)): ?>
<p>No hay flashcards disponibles.</p>
<?php else: ?>

<?php $c = $data[0]; ?>

<div class="card">
<h2><?= htmlspecialchars($c["front_text"]) ?></h2>

<?php if ($c["front_image"]): ?>
<img src="<?= htmlspecialchars($c["front_image"]) ?>">
<?php endif; ?>

<?php if ($c["audio"]): ?>
<audio controls src="<?= htmlspecialchars($c["audio"]) ?>"></audio>
<?php endif; ?>

<hr>

<p><?= htmlspecialchars($c["back_text"]) ?></p>
</div>

<?php endif; ?>

</body>
</html>
