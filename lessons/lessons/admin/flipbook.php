<?php
$uploadDir = __DIR__ . "/uploads/";
$webDir = "uploads/";
$pdfFile = null;

if (!file_exists($uploadDir)) {
  mkdir($uploadDir, 0777, true);
}

// 👉 Leer PDF desde URL
if (isset($_GET["file"])) {
  $pdfFile = $webDir . basename($_GET["file"]);
}

// 👉 Subida de PDF
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_FILES["pdf"])) {
  if ($_FILES["pdf"]["type"] === "application/pdf") {
    $name = time() . "_" . basename($_FILES["pdf"]["name"]);
    $target = $uploadDir . $name;

    if (move_uploaded_file($_FILES["pdf"]["tmp_name"], $target)) {
      header("Location: flipbook.php?file=" . $name);
      exit;
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>PDF Flipbook</title>

<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script src="https://unpkg.com/page-flip/dist/page-flip.browser.js"></script>
<link rel="stylesheet" href="https://unpkg.com/page-flip/dist/page-flip.css">

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
  border-radius:14px;
  box-shadow:0 10px 20px rgba(0,0,0,.15);
}
#flipbook{
  width:900px;
  height:600px;
  margin:30px auto;
}
.page{
  background:white;
  display:flex;
  justify-content:center;
  align-items:center;
}
.page canvas{
  max-width:100%;
}
button{
  padding:8px 16px;
  border:none;
  border-radius:10px;
  background:#2a6edb;
  color:white;
}
</style>
</head>

<body>
<div class="container">
  <h2>📘 PDF Flipbook</h2>

  <form method="post" enctype="multipart/form-data">
    <input type="file" name="pdf" accept="application/pdf" required>
    <br><br>
    <button type="submit">Upload PDF</button>
  </form>

<?php if ($pdfFile): ?>
<hr>
<h3>📖 Flipbook Preview</h3>

<div id="flipbook"></div>

<script>
pdfjsLib.GlobalWorkerOptions.workerSrc =
  "https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js";

const url = "<?= $pdfFile ?>";
const flipbookEl = document.getElementById("flipbook");

const pageFlip = new St.PageFlip(flipbookEl, {
  width: 450,
  height: 600,
  size: "stretch",
  showCover: true,
  maxShadowOpacity: 0.4
});

pdfjsLib.getDocument(url).promise.then(async pdf => {
  const pages = [];

  for (let i = 1; i <= pdf.numPages; i++) {
    const page = await pdf.getPage(i);
    const viewport = page.getViewport({ scale: 1.5 });

    const canvas = document.createElement("canvas");
    const ctx = canvas.getContext("2d");
    canvas.width = viewport.width;
    canvas.height = viewport.height;

    await page.render({ canvasContext: ctx, viewport }).promise;

    const pageDiv = document.createElement("div");
    pageDiv.className = "page";
    pageDiv.appendChild(canvas);

    pages.push(pageDiv);
  }

  pageFlip.loadFromHTML(pages);
});
</script>
<?php endif; ?>

</div>
</body>
</html>
