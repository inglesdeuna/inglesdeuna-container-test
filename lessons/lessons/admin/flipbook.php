<?php
$uploadDir = __DIR__ . "/uploads/";
$webDir = "uploads/";
$pdfFile = null;

if (!file_exists($uploadDir)) {
  mkdir($uploadDir, 0777, true);
}

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
<html lang="en">
<head>
<meta charset="UTF-8">
<title>PDF Flipbook</title>

<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>

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
  box-shadow:0 10px 20px rgba(0,0,0,.15);
}

canvas{
  border-radius:10px;
  box-shadow:0 6px 14px rgba(0,0,0,.2);
}

.controls{
  margin-top:15px;
  text-align:center;
}

button{
  padding:8px 16px;
  margin:0 10px;
  border:none;
  border-radius:10px;
  background:#2a6edb;
  color:white;
  cursor:pointer;
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

<canvas id="pdfCanvas"></canvas>

<div class="controls">
  <button onclick="prevPage()">⬅ Prev</button>
  <span id="pageInfo"></span>
  <button onclick="nextPage()">Next ➡</button>
</div>

<script>
const url = "<?= $pdfFile ?>";

let pdfDoc = null,
    pageNum = 1,
    canvas = document.getElementById('pdfCanvas'),
    ctx = canvas.getContext('2d');

pdfjsLib.getDocument(url).promise.then(pdf => {
  pdfDoc = pdf;
  renderPage(pageNum);
});

function renderPage(num) {
  pdfDoc.getPage(num).then(page => {
    const viewport = page.getViewport({ scale: 1.4 });
    canvas.height = viewport.height;
    canvas.width = viewport.width;

    page.render({ canvasContext: ctx, viewport: viewport });
    document.getElementById("pageInfo").innerText =
      "Page " + num + " / " + pdfDoc.numPages;
  });
}

function prevPage(){
  if (pageNum <= 1) return;
  pageNum--;
  renderPage(pageNum);
}

function nextPage(){
  if (pageNum >= pdfDoc.numPages) return;
  pageNum++;
  renderPage(pageNum);
}
</script>
<?php endif; ?>

</div>
</body>
</html>
