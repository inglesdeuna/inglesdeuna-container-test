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

.card{
  background:white;
  border-radius:14px;
  padding:25px;
  box-shadow:0 10px 25px rgba(0,0,0,.08);
}

.card h2{
  margin-top:0;
}

.card a{
  display:inline-block;
  margin-top:15px;
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
    <h2>🎯 Hangman</h2>
    <p>Editar palabras, pistas y probar el juego.</p>
    <a href="admin.php">✏️ Editar Hangman</a>
  </div>

  <div class="card">
    <h2>📘 Flipbooks</h2>
    <p>Subir PDFs, nombrar lecciones y previsualizar.</p>
    <a href="../admin/flipbook.php">✏️ Editar Flipbooks</a>
    
  </div>
<div class="card">
  <h2>🌐 Actividades Externas</h2>
  <p>Wordwall, Liveworksheets, Genially, etc.</p>
  <a href="../admin/external_links.php">✏️ Editar actividades</a>

</div>
 <div class="card">
  <h3>Multiple Choice</h3>
  <p>Crear y editar preguntas de selección múltiple con texto, imágenes y audio.</p>
  <a href="../activities/multiple_choice/viewer.php" class="btn">
    ✏️ Editar Multiple Choice
  </a>
</div>

  </a>
</div>

</div>

</body>
</html>
