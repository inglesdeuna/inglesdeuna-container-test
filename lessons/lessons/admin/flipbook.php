<?php
// carpeta donde se guardan los PDFs
$uploadDir = __DIR__ . "/uploads/";
if (!is_dir($uploadDir)) {
  mkdir($uploadDir, 0777, true);
}

$pdfFile = null;

// subir PDF
if (isset($_FILES["pdf"])) {
  if ($_FILES["pdf"]["type"] === "application/pdf") {
    $name = time() . "_" . basename($_FILES["pdf"]["name"]);
    move_uploaded_file($_FILES["pdf"]["tmp_name"], $uploadDir . $name);
    $pdfFile = "uploads/" . $name;
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>📘 Flipbook – Teacher Panel</title>

<style>
body{
  font-family: Arial, sans-serif;
  background:#f2f7ff;
  padding:40px;
}

.panel{
  max-width:900px;
  margin:auto;
  background:white;
  border-radius:14px;
  padding:30px;
  box-shadow:0 8px 20px rgba(0,0,0,.15);
}

h1{
  color:#2a6edb;
}

input[type=file]{
  margin-top:10px;
}

button{
  margin-top:10px;
  padding:10px 18px;
  border:none;
  border-radius:10px;
  background:#2a6edb;
  color:white;
  font-size:15px;
  cursor:pointer;
}

.viewer{
  margin-top:30px;
  text-align:center;
}

iframe{
  width:100%;
  height:600px;
  border-radius:12px;
  border:1px solid #ccc;
}
</style>
</head>

<body>

<div class="panel">
  <h1>📘 PDF Flipbook</h1>
  <p>Upload a PDF to preview it as a flipbook.</p>

  <form method="post" enctype="multipart/form-data">
    <input type="file" name="pdf" accept="application/pdf" required>
    <br>
    <button type="submit">📤 Upload PDF</button>
  </form>

  <?php if ($pdfFile): ?>
    <div class="viewer">
      <h2>📖 Preview</h2>
      <iframe 
  src="/lessons/lessons/admin/uploads/<?= basename($pdfFile) ?>" 
  width="100%" 
  height="650" 
  style="border:none;">
</iframe>

    </div>
  <?php endif; ?>
</div>

</body>
</html>
