<?php
$pdfUrl = null;

$uploadDir = __DIR__ . "/uploads/";
$publicPath = "uploads/";

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_FILES["pdf"])) {

  if ($_FILES["pdf"]["error"] === UPLOAD_ERR_OK) {

    $filename = time() . "_" . basename($_FILES["pdf"]["name"]);
    $targetFile = $uploadDir . $filename;

    if (move_uploaded_file($_FILES["pdf"]["tmp_name"], $targetFile)) {
      $pdfUrl = $publicPath . $filename;
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>PDF Flipbook</title>
<style>
body{
  font-family: Arial, sans-serif;
  background:#f2f7ff;
  padding:40px;
}
.container{
  max-width:900px;
  margin:auto;
  background:white;
  padding:30px;
  border-radius:14px;
  box-shadow:0 6px 14px rgba(0,0,0,.12);
}
h1{
  color:#2a6edb;
}
button{
  background:#2a6edb;
  color:white;
  border:none;
  padding:10px 18px;
  border-radius:10px;
  cursor:pointer;
}
.viewer{
  margin-top:30px;
}
iframe{
  width:100%;
  height:600px;
  border:1px solid #ccc;
  border-radius:10px;
}
</style>
</head>

<body>
<div class="container">
  <h1>📘 PDF Flipbook</h1>

  <form method="post" enctype="multipart/form-data">
    <input type="file" name="pdf" accept="application/pdf" required>
    <br><br>
    <button type="submit">⬆️ Upload PDF</button>
  </form>

  <?php if ($pdfUrl): ?>
    <div class="viewer">
      <h2>📖 Preview</h2>
      <iframe src="<?= htmlspecialchars($pdfUrl) ?>"></iframe>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
