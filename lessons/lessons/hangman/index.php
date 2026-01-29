<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Contenedor General de Juegos – LET’S</title>

<style>
body{
  font-family: Arial, sans-serif;
  background:#f4f8ff;
  margin:0;
  padding:40px;
}

h1{
  color:#2563eb;
  margin-bottom:30px;
  display:flex;
  align-items:center;
  gap:10px;
}

.grid{
  display:grid;
  grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
  gap:24px;
}

.card{
  background:white;
  border-radius:14px;
  padding:25px;
  box-shadow:0 10px 25px rgba(0,0,0,.08);
  display:flex;
  flex-direction:column;
  justify-content:space-between;
}

.card-header{
  display:flex;
  align-items:center;
  gap:10px;
  margin-bottom:10px;
}

.card-header h2{
  font-size:20px;
  margin:0;
}

.icon{
  font-size:22px;
}

.card p{
  margin:10px 0 20px 0;
  color:#333;
}

.card a{
  align-self:flex-start;
  padding:12px 18px;
  background:#2563eb;
  color:white;
  text-decoration:none;
  border-radius:10px;
  font-weight:bold;
}
</style>
</head>

<body>

<h1>🎮 Contenedor General de Juegos – LET’S</h1>

<div class="grid">

  <div class="card">
    <div class="card-header">
      <span class="icon">🎯</span>
      <h2>Hangman</h2>
    </div>
    <p>Editar palabras, pistas y probar el juego.</p>
    <a href="../hangman/admin.php">✏️ Editar Hangman</a>
  </div>

  <div class="card">
    <div class="card-header">
      <span class="icon">📘</span>
      <h2>Flipbooks</h2>
    </div>
    <p>Subir PDFs, nombrar lecciones y previsualizar.</p>
    <a href="../admin/flipbook.php">✏️ Editar Flipbooks</a>
  </div>

  <div class="card">
    <div class="card-header">
      <span class="icon">🌐</span>
      <h2>Actividades Externas</h2>
    </div>
    <p>Wordwall, Liveworksheets, Genially, etc.</p>
    <a href="../admin/external_links.php">✏️ Editar actividades</a>
  </div>

  <div class="card">
  <div class="card-header">
    <span class="icon">📝</span>
    <h3>Multiple Choice</h3>
  </div>

  <p>Crear y editar preguntas de selección múltiple con texto, imágenes y audio.</p>

  <a href="../activities/multiple_choice/editor.php" class="btn">
    ✏️ Editar Multiple Choice
  </a>
</div>
<div class="card">
  <div class="card-header">
    <span class="icon">🃏</span>
    <h3>Flashcards</h3>
  </div>

  <p>Crear flashcards con texto, imágenes y audio (frente y reverso).</p>

  <a href="../activities/flashcards/editor.php" class="btn">
    ✏️ Editar Flashcards
  </a>
</div>


</body>
</html>
