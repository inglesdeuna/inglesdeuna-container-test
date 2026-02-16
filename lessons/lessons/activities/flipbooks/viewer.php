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

/* ===== FLIPBOOK ===== */

.book-wrapper{
    position:relative;
    display:flex;
    justify-content:center;
    align-items:center;
    perspective:2000px;
}

#left-page,
#right-page{
    width:48%;
    margin:0 1%;
    border-radius:8px;
    box-shadow:0 10px 25px rgba(0,0,0,.25);
    background:white;
    transition:transform .35s ease;
}

.book-spine{
    position:absolute;
    width:4px;
    height:100%;
    background:linear-gradient(to right,#bbb,#eee,#bbb);
    left:50%;
    transform:translateX(-50%);
    z-index:2;
}

/* Flechas estilo esquina */
.corner{
    position:absolute;
    bottom:15px;
    width:45px;
    height:45px;
    background:white;
    border-radius:50%;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:22px;
    cursor:pointer;
    box-shadow:0 4px 12px rgba(0,0,0,.25);
    transition:.2s;
}

.corner:hover{
    transform:scale(1.1);
}

.left-corner{
    left:20px;
}

.right-corner{
    right:20px;
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

<div class="book-wrapper">
    <div class="book-spine"></div>

    <canvas id="left-page"></canvas>
    <canvas id="right-page"></canvas>

    <div class="corner left-corner" onclick="prevPage()">❮</div>
    <div class="corner right-corner" onclick="nextPage()">❯</div>
</div>

</div>

<script>

const url = "/lessons/lessons/<?= $currentPdf ?>";

/* Sonido real */
const soundPath = encodeURI("/lessons/lessons/activities/hangman/assets/freesound_community-pasando-por-las-paginas-43453 (1).mp3");
const pageSound = new Audio(soundPath);

document.addEventListener("click", function initSound(){
    pageSound.load();
    document.removeEventListener("click", initSound);
});

let pdfDoc = null;
let pageNum = 1;

const leftCanvas = document.getElementById('left-page');
const rightCanvas = document.getElementById('right-page');
const leftCtx = leftCanvas.getContext('2d');
const rightCtx = rightCanvas.getContext('2d');

function renderPage(num){

    const container = document.querySelector('.viewer-container');
    const containerWidth = container.clientWidth - 60;
    const singleWidth = containerWidth / 2;

    // PORTADA SOLA
    if(num === 1){

        pdfDoc.getPage(1).then(function(page){

            const viewport = page.getViewport({scale:1});
            const scale = singleWidth / viewport.width;
            const scaledViewport = page.getViewport({scale:scale});

            rightCanvas.height = scaledViewport.height;
            rightCanvas.width = scaledViewport.width;

            leftCanvas.width = 0;

            page.render({
                canvasContext: rightCtx,
                viewport: scaledViewport
            });

        });

    } else {

        // Página izquierda
        pdfDoc.getPage(num).then(function(page){

            const viewport = page.getViewport({scale:1});
            const scale = singleWidth / viewport.width;
            const scaledViewport = page.getViewport({scale:scale});

            leftCanvas.height = scaledViewport.height;
            leftCanvas.width = scaledViewport.width;

            page.render({
                canvasContext: leftCtx,
                viewport: scaledViewport
            });

        });

        // Página derecha
        if(num + 1 <= pdfDoc.numPages){
            pdfDoc.getPage(num + 1).then(function(page){

                const viewport = page.getViewport({scale:1});
                const scale = singleWidth / viewport.width;
                const scaledViewport = page.getViewport({scale:scale});

                rightCanvas.height = scaledViewport.height;
                rightCanvas.width = scaledViewport.width;

                page.render({
                    canvasContext: rightCtx,
                    viewport: scaledViewport
                });

            });
        }
    }

    document.getElementById('page_num').textContent = num;
    document.getElementById('page_count').textContent = pdfDoc.numPages;
}

function nextPage(){

    if(pageNum === 1){
        pageNum = 2;
    } else {
        if(pageNum + 2 > pdfDoc.numPages) return;
        pageNum += 2;
    }

    pageSound.currentTime = 0;
    pageSound.play();

    rightCanvas.style.transform = "rotateY(-15deg)";
    setTimeout(()=>{ rightCanvas.style.transform="rotateY(0deg)"; },200);

    renderPage(pageNum);
}

function prevPage(){

    if(pageNum <= 1) return;

    if(pageNum === 2){
        pageNum = 1;
    } else {
        pageNum -= 2;
    }

    pageSound.currentTime = 0;
    pageSound.play();

    leftCanvas.style.transform = "rotateY(15deg)";
    setTimeout(()=>{ leftCanvas.style.transform="rotateY(0deg)"; },200);

    renderPage(pageNum);
}

/* Cargar PDF */
pdfjsLib.getDocument(url).promise.then(function(pdfDoc_){
    pdfDoc = pdfDoc_;
    renderPage(pageNum);
});

window.addEventListener("resize", function(){
    renderPage(pageNum);
});

</script>

</body>
</html>
