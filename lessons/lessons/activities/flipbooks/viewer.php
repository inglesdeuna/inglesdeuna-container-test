<?php
$unit = $_GET['unit'] ?? null;
if (!$unit) die("Unidad no especificada");

$jsonFile = __DIR__ . "/flipbooks.json";
$data = json_decode(file_get_contents($jsonFile), true);
$currentPdf = $data[$unit]["pdf"] ?? "";
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Flipbook</title>

<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.min.js"></script>

<style>
body{
    margin:0;
    background:#eef6ff;
    font-family:Arial;
}

/* Back button */
.back-btn{
    position:absolute;
    top:20px;
    left:20px;
    background:#16a34a;
    padding:8px 14px;
    border:none;
    border-radius:10px;
    color:white;
    cursor:pointer;
    font-weight:bold;
}

/* Main container */
.viewer-container{
    max-width:900px;
    margin:80px auto 40px auto;
    background:white;
    padding:25px;
    border-radius:16px;
    box-shadow:0 4px 20px rgba(0,0,0,.1);
    text-align:center;
}

h1{
    color:#0b5ed7;
    font-size:28px;
    margin-bottom:20px;
}

canvas{
    border-radius:10px;
    box-shadow:0 4px 15px rgba(0,0,0,.2);
}

.controls{
    margin:15px 0;
}

.controls button{
    padding:8px 15px;
    margin:5px;
    border:none;
    border-radius:8px;
    background:#0b5ed7;
    color:white;
    cursor:pointer;
}
</style>
</head>

<body>

<button 
class="back-btn"
onclick="window.location.href='../hub/index.php?unit=<?= urlencode($unit) ?>'">
↩ Back
</button>

<div class="viewer-container">

<h1>📖 Flipbook</h1>

<div class="controls">
<button onclick="prevPage()">◀ Prev</button>
<span>Page: <span id="page_num"></span> / <span id="page_count"></span></span>
<button onclick="nextPage()">Next ▶</button>
</div>

<canvas id="pdf-render"></canvas>

</div>

<script>
const url = "/lessons/lessons/<?= $currentPdf ?>";

let pdfDoc = null,
    pageNum = 1,
    pageIsRendering = false,
    pageNumIsPending = null,
    scale = 1.2,
    canvas = document.getElementById('pdf-render'),
    ctx = canvas.getContext('2d');

pdfjsLib.getDocument(url).promise.then(pdfDoc_ => {
    pdfDoc = pdfDoc_;
    document.getElementById('page_count').textContent = pdfDoc.numPages;
    renderPage(pageNum);
});

function renderPage(num){
    pageIsRendering = true;

    pdfDoc.getPage(num).then(page => {
        const viewport = page.getViewport({scale});
        canvas.height = viewport.height;
        canvas.width = viewport.width;

        const renderCtx = {
            canvasContext: ctx,
            viewport
        };

        page.render(renderCtx).promise.then(() => {
            pageIsRendering = false;

            if(pageNumIsPending !== null){
                renderPage(pageNumIsPending);
                pageNumIsPending = null;
            }
        });

        document.getElementById('page_num').textContent = num;
    });
}

function queueRenderPage(num){
    if(pageIsRendering){
        pageNumIsPending = num;
    } else {
        renderPage(num);
    }
}

function prevPage(){
    if(pageNum <= 1) return;
    pageNum--;
    queueRenderPage(pageNum);
}

function nextPage(){
    if(pageNum >= pdfDoc.numPages) return;
    pageNum++;
    queueRenderPage(pageNum);
}
</script>

</body>
</html>
