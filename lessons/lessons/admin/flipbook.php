<?php
// ===== CONFIG =====
$uploadDir = __DIR__ . '/uploads/';
$webDir    = '/lessons/lessons/admin/uploads/';
$pdfFile   = null;

// ===== ENSURE UPLOADS FOLDER =====
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

// ===== HANDLE UPLOAD =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['pdf'])) {
    if ($_FILES['pdf']['error'] === 0) {

        $safeName = time() . '_' . basename($_FILES['pdf']['name']);
        $target   = $uploadDir . $safeName;

        if (move_uploaded_file($_FILES['pdf']['tmp_name'], $target)) {
            $pdfFile = $webDir . $safeName;
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
.card{
  max-width:800px;
  margin:auto;
  background:white;
  padding:30px;
  border-radius:16px;
  box-shadow:0 8px 20px rgba(0,0,0,.15);
}
h1{ color:#2a6edb; }
button{
  background:#2a6edb;
  color:white;
  border:none;
  padding:10px 18px;
  border-radius:10px;
  cursor:pointer;
}
iframe{
  width:100%;
  height:650px;
  border:none;
  margin-top:20px;
}
</style>
</head>

<body>
<div class="card">
  <h1>📘 PDF Flipbook</h1>
  <p>Upload a PDF to preview it.</p>

  <form method="post" enctype="multipart/form-data">
    <input type="file" name="pdf" accept="application/pdf" required>
    <br><br>
    <button type="submit">⬆ Upload PDF</button>
  </form>

  <?php if ($pdfFile): ?>
    <h2 style="margin-top:30px;">👀 Preview</h2>
    <iframe src="<?= $pdfFile ?>"></iframe>
  <?php endif; ?>
</div>
</body>
</html>
