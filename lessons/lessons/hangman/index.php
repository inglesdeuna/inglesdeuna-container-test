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
}

.grid{
  display:grid;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap:20px;
}

/* CARD */
.card{
  background:white;
  border-radius:14px;
  padding:25px;
  box-shadow:0 10px 25px rgba(0,0,0,.08);
  display:flex;
  flex-direction:column;
}

/* HEADER ICON + TITLE */
.card-header{
  display:flex;
  align-items:center;
  gap:10px;
  margin-bottom:10px;
}

.card-header h3{
  font-size:18px;
  margin:0;
}

.icon{
  font-size:22px;
}

/* BUTTON */
.card a{
  margin-top:auto;
  display:inline-block;
  padding:12px 18px;
  background:#2563eb;
  color:white;
  text-decoration:none;
  border-radius:10px;
  font-weight:bold;
  width:fit-content;
}
</style>
</head>

<body>

<h1>🎮 Contenedor General de Juegos – LET’S</h1>

<div class="grid">

  <!-- HANGMAN -->
  <div class="card">
    <div class="card-header">
      <span class="icon">🎯</span>
      <h3>Hangman</h3>
    </div>
    <p>Editar palabras, pistas y probar el juego.</p>
    <a href="admin.php">✏️ Editar Hangman</a>
  </div>

  <!-- FLIPBOOKS -->
  <div class="card">
    <div class="card-header">
      <span class="icon">📘</span>
      <h3>Flipbooks</h3>
    </div>
    <p>Subir PDFs, nombrar lecciones y previsualizar.</p>
    <a href="../admin/flipbook.php">✏️ Editar Flipbooks</a>
  </div>

  <!-- EXTERNAL -->
  <div class="card">
    <div class="card-header">
      <span class="icon">🌐</span>
      <h3>Actividades Externas</h3>
    </div>
    <p>Wordwall, Liveworksheets, Genially, etc.</p>
    <a href="../admin/external_links.php">✏️ Editar actividades</a>
  </div>

  <!-- MULTIPLE CHOICE -->
  <div class="card">
    <div class="card-header">
      <span class="icon">📝</span>
      <h3>Multiple Choice</h3>
    </div>
    <p>Crear y editar preguntas de selección múltiple con texto, imágenes y audio.</p>
    <a href="../activities/multiple_choice/viewer.php">✏️ Editar Multiple Choice</a>
  </div>

</div>

</body>
</html>
