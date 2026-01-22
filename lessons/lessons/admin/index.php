<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>LET’S Platform – Teacher Panel</title>

<style>
body{
  font-family: Arial, sans-serif;
  background:#f2f7ff;
  padding:40px;
}

h1{
  color:#2a6edb;
}

.games{
  display:grid;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap:20px;
  margin-top:30px;
}

.card{
  background:white;
  border-radius:14px;
  padding:20px;
  box-shadow:0 6px 14px rgba(0,0,0,.12);
}

.card h2{
  margin:0 0 10px;
}

.card p{
  color:#555;
  font-size:14px;
}

.actions a{
  display:inline-block;
  margin-top:12px;
  margin-right:10px;
  padding:8px 16px;
  border-radius:10px;
  text-decoration:none;
  font-size:14px;
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

<h1>🎓 Teacher Panel – LET’S Platform</h1>
<p>Manage your games and learning activities</p>

<div class="games">

  <!-- HANGMAN -->
  <div class="card">
    <h2>🎯 Hangman Kids</h2>
    <p>Vocabulary game with sounds, hints and animations.</p>
    <div class="actions">
      <a class="edit" href="../hangman/admin.php">Configure</a>
      <a class="preview" href="../hangman/index.php" target="_blank">Preview</a>
    </div>
  </div>

  <!-- FUTURE GAMES -->
  <div class="card disabled">
    <h2>🧠 Memory Game</h2>
    <p>Coming soon</p>
    <div class="actions">
      <a class="edit">Configure</a>
      <a class="preview">Preview</a>
    </div>
  </div>

</div>

</body>
</html>
