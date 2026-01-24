<?php
if (!isset($_GET["file"])) {
    die("PDF no especificado");
}

$file = basename($_GET["file"]);
$pdfPath = "uploads/" . $file;

if (!file_exists(__DIR__ . "/uploads/" . $file)) {
    die("Archivo no encontrado");
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Ver PDF</title>

<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>

<style>
body {
  font-family: Arial, sans-serif;
  background:#f2f7ff;
  padding:30px;
}
#viewer {
  width:100%;
  max-width:900px;
  margin:auto;
}
canvas {
  width:100%;
  margin-bottom:20px;
  box-shadow:0 4px 10px rgba(0,0,0,.2);
}
</style>
</head>
<body>

<h2>📄 Vista del PDF</h2>

<div id="viewer"></div>

<script>
pdfjsLib.GlobalWorkerOptions.workerSrc =
  "https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js";

const url = "<?= $pdfPath ?>";
const container = document.getElementById("viewer");

pdfjsLib.getDocument(url).promise.then(async pdf => {
  for (let i = 1; i <= pdf.numPages; i++) {
    const page = await pdf.getPage(i);
    const viewport = page.getViewport({ scale: 1.3 });

    const canvas = document.createElement("canvas");
    const ctx = canvas.getContext("2d");

    canvas.width = viewport.width;
    canvas.height = viewport.height;

    await page.render({ canvasContext: ctx, viewport }).promise;
    container.appendChild(canvas);
  }
});
</script>

</body>
</html>
