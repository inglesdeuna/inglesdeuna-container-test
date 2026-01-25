<?php
$flipbooksFile = __DIR__ . "/flipbooks.json";
$file = $_GET["file"] ?? null;
$title = "Lección";

if ($file && file_exists($flipbooksFile)) {
  $data = json_decode(file_get_contents($flipbooksFile), true);
  foreach ($data as $item) {
    if ($item["file"] === basename($file)) {
      $title = $item["title"];
      break;
    }
  }
}

$pdfPath = "uploads/" . basename($file);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?php echo htmlspecialchars($title); ?></title>

<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>

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
}
h1{
  text-align:center;
  color:#2a6edb;
}
canvas{
  display:block;
  margin:20px auto;
  box-shadow:0 6px 14px rgba(0,0,0,.2);
}
.controls{
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
  <h1><?php echo htmlspecialchars($title); ?></h1>

  <canvas id="pdfCanvas"></canvas>

  <div class="controls">
    <button onclick="prevPage()">⬅ Anterior</button>
    <span id="pageInfo"></span>
    <button onclick="nextPage()">Siguiente ➡</button>
  </div>
</div>

<script>
pdfjsLib.GlobalWorkerOptions.workerSrc =
  "https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js";

const url = "<?php echo $pdfPath; ?>";

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
    page.render({ canvasContext: ctx, viewport });
    document.getElementById("pageInfo").innerText =
      "Página " + num + " / " + pdfDoc.numPages;
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

</body>
</html>
