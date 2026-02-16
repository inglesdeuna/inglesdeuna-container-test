<script>
const url = "/lessons/lessons/<?= $currentPdf ?>";

/* Sonido real */
const soundPath = encodeURI("/lessons/lessons/activities/hangman/assets/freesound_community-pasando-por-las-paginas-43453 (1).mp3");
const pageSound = new Audio(soundPath);

/* Activar audio tras primer click */
document.addEventListener("click", function initSound(){
    pageSound.load();
    document.removeEventListener("click", initSound);
});

let pdfDoc = null,
    pageNum = 1,
    pageIsRendering = false,
    pageNumIsPending = null,
    scale = 1,
    canvas = document.getElementById('pdf-render'),
    ctx = canvas.getContext('2d');

/* Ajuste horizontal automático SIN rotar manualmente */
function calculateScale(page){
    const container = document.querySelector('.viewer-container');
    const containerWidth = container.clientWidth - 40;

    const viewport = page.getViewport({scale:1});
    scale = containerWidth / viewport.width;
}

/* Animación */
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

window.addEventListener("resize", function(){
    renderPage(pageNum);
});
</script>
