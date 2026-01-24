<?php
$uploadDir = __DIR__ . "/uploads/";
$baseUrl = "flipbook.php?file=";

if (!file_exists($uploadDir)) {
  mkdir($uploadDir, 0777, true);
}

$message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_FILES["pdf"])) {
  if ($_FILES["pdf"]["type"] === "application/pdf") {

    $cleanName = preg_replace("/[^a-zA-Z0-9._-]/", "_", $_FILES["pdf"]["name"]);
    $name = time() . "_" . $cleanName;
    $target = $uploadDir . $name;

    if (move_uploaded_file($_FILES["pdf"]["tmp_name"], $target)) {
      $message = "PDF subido correctamente";
    }
  }
}

$files = array_diff(scandir($uploadDir), ['.', '..']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Contenedor del Docente – PDFs</title>
<style>
body{
  font-family: Arial, sans-serif;
  background:#f2f7ff;
  padding:40px;
}
.container{
  max-width:1000px;
  margin:auto;
  background:white;
  padding:30px;
  border-radius:12px;
}
table{
  width:100%;
  border-collapse:collapse;
  margin-top:20px;
}
th, td{
  padding:10px;
  border-bottom:1px solid #ddd;
}
a.button{
  background:#2a6edb;
  color:white;
  padding:6px 10px;
  text-decoration:none;
  border-radius:6px;
  font-size:14px;
}
.success{
  color:green;
  margin-top:10px;
}
</style>
</head>

<body>
<div class="container">
  <h2>📚 Contenedor del Docente – PDFs</h2>

  <form method="post" enctype="multipart/form-data">
    <input type="file" name="pdf" accept="application/pdf" required>
    <button type="submit">Subir PDF</button>
  </form>

  <?php if ($message): ?>
    <p class="success">✅ <?= $message ?></p>
  <?php endif; ?>

  <h3>📄 PDFs disponibles</h3>

  <table>
    <tr>
      <th>Archivo</th>
      <th>Vista</th>
      <th>Link para estudiantes</th>
    </tr>
    <?php foreach ($files as $file): ?>
      <tr>
        <td><?= htmlspecialchars($file) ?></td>
        <td>
          <a class="button" target="_blank"
             href="flipbook.php?file=<?= urlencode($file) ?>">
             Ver
          </a>
        </td>
        <td>
          <input type="text" style="width:100%"
            value="https://inglesdeuna-container-test.onrender.com/lessons/lessons/admin/flipbook.php?file=<?= urlencode($file) ?>"
            onclick="this.select()">
        </td>
      </tr>
    <?php endforeach; ?>
  </table>

</div>
</body>
</html>
