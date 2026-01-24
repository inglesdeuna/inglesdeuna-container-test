<?php
$uploadDir = __DIR__ . "/uploads/";
$webDir = "uploads/";
$pdfFile = null;

// Crear carpeta si no existe
if (!is_dir($uploadDir)) {
  mkdir($uploadDir, 0777, true);
}

// Procesar subida
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  if (isset($_FILES["pdf"]) && $_FILES["pdf"]["error"] === 0) {

    if (mime_content_type($_FILES["pdf"]["tmp_name"]) === "application/pdf") {
      $name = time() . "_" . preg_replace("/[^a-zA-Z0-9._-]/", "", $_FILES["pdf"]["name"]);
      $target = $uploadDir . $name;

      if (move_uploaded_file($_FILES["pdf"]["tmp_name"], $target)) {
        $pdfFile = $webDir . $name;
      } else {
        die("❌ ERROR: No se pudo mover el archivo");
      }

    } else {
      die("❌ ERROR: El archivo no es PDF");
    }

  } else {
    die("❌ ERROR: No llegó ningún archivo");
  }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Test Upload PDF</title>
<style>
body{font-family:Arial;background:#f2f7ff;padding:40px}
.container{max-width:900px;margin:auto;background:#fff;padding:30px;border-radius:12px}
</style>
</head>

<body>
<div class="container">
<h2>🧪 Test de subida de PDF</h2>

<form method="post" enctype="multipart/form-data">
  <input type="file" name="pdf" accept="application/pdf" required>
  <br><br>
  <button type="submit">Subir PDF</button>
</form>

<?php if ($pdfFile): ?>
<hr>
<h3>✅ PDF subido correctamente</h3>
<p><strong>Ruta:</strong> <?= $pdfFile ?></p>

<iframe src="<?= $pdfFile ?>" width="100%" height="600"></iframe>
<?php endif; ?>

</div>
</body>
</html>
