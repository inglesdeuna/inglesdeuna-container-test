<?php
$uploadDir = __DIR__ . "/uploads/";
$webDir = "uploads/";

if (!file_exists($uploadDir)) {
  mkdir($uploadDir, 0777, true);
}

$pdfFile = null;

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_FILES["pdf"])) {
  if ($_FILES["pdf"]["type"] === "application/pdf") {
    $name = time() . "_" . basename($_FILES["pdf"]["name"]);
    $target = $uploadDir . $name;

    if (move_uploaded_file($_FILES["pdf"]["tmp_name"], $target)) {
      $pdfFile = $webDir . $name;
    }
  }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>PDF Preview</title>

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

iframe{
  width:100%;
  height:600px;
  border:1px solid #ccc;
  border-radius:8px;
}

button{
  padding:8px 16px;
  border:none;
  border-radius:8px;
  background:#2a6edb;
  color:white;
  cursor:pointer;
}
</style>
</head>

<body>
<div class="container">
  <h2>📄 Subir y ver PDF</h2>

  <form method="post" enctype="multipart/form-data">
    <input type="file" name="pdf" accept="application/pdf" required>
    <br><br>
    <button type="submit">Subir PDF</button>
  </form>

<?php if ($pdfFile): ?>
<hr>
<h3>Vista previa</h3>

<iframe src="<?= htmlspecialchars($pdfFile) ?>"></iframe>

<?php endif; ?>

</div>
</body>
</html>
