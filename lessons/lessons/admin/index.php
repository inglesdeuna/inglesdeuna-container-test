<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Teacher Panel – LET’S Platform</title>

<style>
body{
  font-family: Arial, sans-serif;
  background:#f2f7ff;
  padding:40px;
}

h1{
  color:#2a6edb;
  text-align:center;
}

.subtitle{
  text-align:center;
  font-size:16px;
  color:#444;
}

.games{
  display:grid;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap:20px;
  margin-top:40px;
  max-width:900px;
  margin-left:auto;
  margin-right:auto;
}

.card{
  background:white;
  border-radius:14px;
  padding:20px;
  box-shadow:0 6px 14px rgba(0,0,0,.12);
  text-align:center;
}

.card h2{
  margin:10px 0;
}

.card p{
  color:#555;
  font-size:14px;
}

.actions{
  margin-top:15px;
}

.actions a{
  display:inline-block;
  margin:6px;
  padding:10px 18px;
  border-radius:10px;
  text-decoration:none;
  font-size:14px;
  font-weight:bold;
}

.edit{
  background:#2a6edb;
  color:white;
}

.preview{
  background:#4caf50;
  color:white;
}

.disabled{
  opacity:.5;
  pointer-events:none;
}
</style>
</head>

<body>

<h1>🎒 Teacher Panel</h1>
<p class="subtitle">Manage games and learning activities</p>

<div class="games">

  <!-- HANGMAN GAME -->
  <div class="card">
    <div style="font-size:48px;">🎯</div>
    <h2>Hangman Kids</h2>
    <p>Vocabulary & spelling game with sounds and hints</p>

    <div class="actions">
      <a class="edit" href="../hangman/admin.php">✏️ Configure</a>
      <a class="preview" href="../hangman/index.php" target="_blank">▶ Preview</a>
    </div>
  </div>

  <!-- FUTURE GAME -->
  <div class="card disabled">
    <div style="font-size:48px;">🧩</div>
    <h2>Next Game</h2>
    <p>Coming soon</p>

    <div class="actions">
      <a class="edit">Configure</a>
      <a class="preview">Preview</a>
    </div>
  </div>

</div>

</body>
</html>
