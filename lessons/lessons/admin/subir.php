<?php
$uploadDir = __DIR__ . "/uploads/";
$webDir = "uploads/";

if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$msg = "";

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_FILES["pdf"])) {
    if ($_FILES["pdf"]["type"] === "application/pdf") {
        $name = time() . "_" . preg_replace("/[^a-zA-Z0-9._-]/", "", $_FILES["pdf"]["name"]);
        $target = $uploadDir . $name;

        if (move_uploaded_file($_FILES["pdf"]["tmp_name"], $target)) {
            header("Location: viewer.php?file=" . urlencode($name));
            exit;
        } else {
            $msg = "❌ Error al subir el archivo";
        }
    } else {
        $msg = "❌ Solo se permiten PDFs";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Subir PDF</title>
</head>
<body>

<h2>📤 Subir PDF</h2>

<form method="post" enctype="multipart/form-data">
  <input type="file" name="pdf" accept="application/pdf" required>
  <br><br>
  <button type="submit">Subir PDF</button>
</form>

<p><?= $msg ?></p>

</body>
</html>
