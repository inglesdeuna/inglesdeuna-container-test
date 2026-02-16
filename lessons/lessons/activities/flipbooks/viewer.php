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

/* Botón Back */
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

/* Rectángulo blanco */
.viewer-container{
    max-width:1100px;
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
    max-width:100%;
    height:auto;
    border-radius:10px;
    box-shadow:0 4px 15px rgba(0,0,0,.2);
    transform-style: preserve-3d;
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

/* Ruta real del sonido con espacios */
const soundPath = encodeURI("/lessons/lessons/activities/hangman/assets/freesound_community-pasando-por-las-paginas-43453 (1).mp3");
const pageSound = new Audio(soundPath);

let pdfDoc = null,
    pageNum = 1,
    pageIsRendering = false,
    pageNumIsPending = null,
    scale = 1,
    canvas = document.getElementById('pdf-render'),
    ctx = canvas.getContext('2d');

/* Ajuste horizontal automático */
function calculateScale(page){
    const container = document.querySelector('.viewer-container');
    const containerWidth = container.clientWidth - 40;
    const viewport = page.getViewport({scale:1});
    scale = containerWidth / viewport.width;
}

/* Animación de giro */
function animateFlip(){
    canvas.style.transition = "transform 0.25s ease";
    canvas.style.transform = "rotateY(15deg)";
    setTimeout(()=>{
        canvas.style.transform = "rotateY(0deg)";
    },150);
}

pdfjsLib.getDocument(url).promise.then(pdfDoc_ => {
    pdfDoc = pdfDoc_;
    document.getElementById('page_count').textContent = pdfDoc.numPages;
    renderPage(pageNum);
});

function renderPage(num){
    pageIsRendering = true;

    pdfDoc.getPage(num).then(page => {

        calculateScale(page);

        const viewport = page.getViewport({scale:scale});

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
    pageSound.currentTime = 0;
    pageSound.play();
    animateFlip();
    queueRenderPage(pageNum);
}

function nextPage(){
    if(pageNum >= pdfDoc.numPages) return;
    pageNum++;
    pageSound.currentTime = 0;
    pageSound.play();
    animateFlip();
    queueRenderPage(pageNum);
}

/* Recalcular si cambia pantalla */
window.addEventListener("resize", function(){
    renderPage(pageNum);
});
</script>

</body>
</html>
