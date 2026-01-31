<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Contenedor General de Juegos – LET’S</title>

<style>
/* ===== ESTILO GLOBAL ===== */
body{
  font-family: Arial, Helvetica, sans-serif;
  background:#f4f8ff;
  margin:0;
  padding:40px;
  color:#111;
}

h1{
  color:#2563eb;
  margin-bottom:30px;
  display:flex;
  align-items:center;
  gap:10px;
  font-size:28px;
}

/* ===== GRID ===== */
.grid{
  display:grid;
  grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
  gap:24px;
}

/* ===== CARD ===== */
.card{
  background:#ffffff;
  border-radius:14px;
  padding:25px;
  box-shadow:0 10px 25px rgba(0,0,0,.08);
  display:flex;
  flex-direction:column;
  justify-content:space-between;
}

/* ===== CARD HEADER ===== */
.card-header{
  display:flex;
  align-items:center;
  gap:10px;
  margin-bottom:10px;
}

.card-header h2{
  font-size:20px;
  margin:0;
  font-weight:700;
  color:#111;
}

.icon{
  font-size:22px;
}

/* ===== TEXTO ===== */
.card p{
  margin:10px 0 20px 0;
  color:#333;
  font-size:15px;
  line-height:1.4;
}

/* ===== BOTONES (UNIFICADOS) ===== */
.card a{
  display:inline-block;
  padding:12px 18px;
  background:#2563eb;
  color:#ffffff;
  text-decoration:none;
  border-radius:10px;
  font-weight:700;
  font-size:14px;
  transition:background .2s ease;
}

.card a:hover{
  background:#1e4ed8;
}

/* separación entre múltiples botones */
.card a + a{
  margin-top:12px;
}
</style>
</head>

<body>

<h1>🎮 Contenedor General de Juegos – LET’S</h1>

<div class="grid">

  <!-- Hangman -->
  <div class="card">
    <div class="card-header">
      <span class="icon">🎯</span>
      <h2>Hangman</h2>
    </div>
    <p>Editar palabras, pistas y probar el juego.</p>
    <a href="../hangman/admin.php">✏️ Editar Hangman</a>
  </div>

  <!-- Flipbooks -->
  <div class="card">
    <div class="card-header">
      <span class="icon">📘</span>
      <h2>Flipbooks</h2>
    </div>
    <p>Subir PDFs, nombrar lecciones y previsualizar.</p>
    <a href="../admin/flipbook.php">✏️ Editar Flipbooks</a>
  </div>

  <!-- Actividades Externas -->
  <div class="card">
    <div class="card-header">
      <span class="icon">🌐</span>
      <h2>Actividades Externas</h2>
    </div>
    <p>Wordwall, Liveworksheets, Genially, etc.</p>
    <a href="../admin/external_links.php">✏️ Editar actividades</a>
  </div>

  <!-- Multiple Choice -->
  <div class="card">
    <div class="card-header">
      <span class="icon">📝</span>
      <h2>Multiple Choice</h2>
    </div>
    <p>Crear y editar preguntas de selección múltiple con texto, imágenes y audio.</p>
    <a href="../activities/multiple_choice/editor.php">
      ✏️ Editar Multiple Choice
    </a>
  </div>

  <!-- Flashcards -->
  <div class="card">
    <div class="card-header">
      <span class="icon">🃏</span>
      <h2>Flashcards</h2>
    </div>
    <p>Crear flashcards con texto, imágenes y audio (frente y reverso).</p>

    <a href="../activities/flashcards/editor.php">
      ✏️ Editar Flashcards
    </a>

    <a href="../activities/flashcards/viewer.php">
      👀 Ver Flashcards
    </a>
  </div>
<div class="card">
  <div class="card-header">
    <span class="icon">🎧</span>
    <h2>Pronunciation</h2>
  </div>

  <p>Practicar pronunciación con audio AI y reconocimiento de voz.</p>

  <a href="../activities/pronunciation/editor.php">
    ✏️ Editar Pronunciation
  </a>

  <a href="../activities/pronunciation/viewer.php">
    👀 Ver Pronunciation
  </a>
</div>

</div>

</body>
</html>
