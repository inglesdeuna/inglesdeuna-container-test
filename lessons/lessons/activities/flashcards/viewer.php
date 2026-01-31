<?php
$file = __DIR__ . "/flashcards.json";
$data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];

$index = isset($_GET["i"]) ? (int)$_GET["i"] : 0;
$c = $data[$index] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Flashcards</title>

<style>
body{
  font-family:Arial;
  background:#eaf2ff;
  text-align:center
}

.scene{
  width:420px;
  height:300px;
  margin:60px auto;
  perspective:1000px;
}

.card{
  width:100%;
  height:100%;
  position:relative;
  transform-style:preserve-3d;
  transition:transform .6s;
  cursor:pointer;
}

.card.is-flipped{
  transform:rotateY(180deg);
}

.face{
  position:absolute;
  width:100%;
  height:100%;
  backface-visibility:hidden;
  background:#fff;
  border-radius:16px;
  padding:25px;
  box-shadow:0 10px 25px rgba(0,0,0,.15);
}

.back{
  transform:rotateY(180deg);
  background:#f9fafb;
}

img{
  max-width:100%;
  border-radius:10px;
  margin-top:10px
}

button{
  margin-top:10px;
}
.nav{
  margin-top:20px;
}
.nav a{
  margin:0 15px;
  text-decoration:none;
  font-weight:bold;
}
</style>
</head>

<body>

<?php if (empty($data) || !$c): ?>
  <p>No hay flashcards disponibles.</p>
<?php else: ?>

<div class="scene">
  <div class="card" onclick="this.classList.toggle('is-flipped')">

    <!-- FRONT -->
    <div class="face front">
      <h2><?= htmlspecialchars($c["front_text"]) ?></h2>

      <button onclick="event.stopPropagation(); speak('<?= htmlspecialchars($c["front_text"]) ?>')">
        🔊 Audio
      </button>

      <?php if (!empty($c["front_image"])): ?>
        <img src="<?= htmlspecialchars($c["front_image"]) ?>">
      <?php endif; ?>

      <p><em>Click para ver el reverso</em></p>
    </div>

    <!-- BACK -->
    <div class="face back">
      <h2><?= htmlspecialchars($c["back_text"]) ?></h2>
      <p><em>Click para volver</em></p>
    </div>

  </div>
</div>

<!-- 🔁 NAVEGACIÓN -->
<div class="nav">
  <?php if ($index > 0): ?>
    <a href="?i=<?= $index - 1 ?>">⬅️ Anterior</a>
  <?php endif; ?>

  <?php if ($index < count($data) - 1): ?>
    <a href="?i=<?= $index + 1 ?>">Siguiente ➡️</a>
  <?php endif; ?>
</div>

<?php endif; ?>

<script>
function speak(text){
  const u = new SpeechSynthesisUtterance(text);
  u.lang = "en-US";
  u.rate = 0.9;
  speechSynthesis.cancel();
  speechSynthesis.speak(u);
}
</script>

</body>
</html>
