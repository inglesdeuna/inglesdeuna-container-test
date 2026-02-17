<?php
session_start();

require_once __DIR__ . "/../../core/db.php";
require_once __DIR__ . "/../../core/_activity_viewer_template.php";

$unit = $_GET['unit'] ?? null;
if (!$unit) die("Unidad no especificada");

/* ===== OBTENER DESDE DB ===== */
$stmt = $pdo->prepare("
    SELECT data
    FROM activities
    WHERE unit_id = :unit
    AND type = 'flipbooks'
    LIMIT 1
");
$stmt->execute(['unit' => $unit]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$pdfPath = "";
if ($row) {
    $content = json_decode($row['data'], true);
    $pdfPath = $content['pdf'] ?? "";
}

$publicPdfUrl = "/lessons/lessons/" . $pdfPath;

/* ===== CONTENIDO PARA TEMPLATE ===== */
ob_start();
?>

<?php if ($pdfPath): ?>

<div id="flipbook-container" style="text-align:center;">
    <canvas id="pdf-render"></canvas>

    <div style="margin-top:15px;">
        <button onclick="prevPage()">⬅ Previous</button>
        <span id="page-info"></span>
        <button onclick="nextPage()">Next ➡</button>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.min.js"></script>

<script>
const url = "<?= $publicPdfUrl ?>";

let pdfDoc = null,
    pageNum = 1,
    pageRendering = false,
    pageNumPending = null,
    scale = 1.5,
    canvas = document.getElementById('pdf-render'),
    ctx = canvas.getContext('2d');

pdfjsLib.getDocument(url).promise.then(function(pdfDoc_) {
    pdfDoc = pdfDoc_;
    document.getElementById('page-info').textContent = "Page 1 of " + pdfDoc.numPages;
    renderPage(pageNum);
});

function renderPage(num) {
    pageRendering = true;

    pdfDoc.getPage(num).then(function(page) {
        const viewport = page.getViewport({scale: scale});
        canvas.height = viewport.height;
        canvas.width = viewport.width;

        const renderContext = {
            canvasContext: ctx,
            viewport: viewport
        };

        const renderTask = page.render(renderContext);

        renderTask.promise.then(function() {
            pageRendering = false;
            if (pageNumPending !== null) {
                renderPage(pageNumPending);
                pageNumPending = null;
            }
        });
    });

    document.getElementById('page-info').textContent =
        "Page " + num + " of " + pdfDoc.numPages;
}

function queueRenderPage(num) {
    if (pageRendering) {
        pageNumPending = num;
    } else {
        renderPage(num);
    }
}

function prevPage() {
    if (pageNum <= 1) return;
    pageNum--;
    queueRenderPage(pageNum);
}

function nextPage() {
    if (pageNum >= pdfDoc.numPages) return;
    pageNum++;
    queueRenderPage(pageNum);
}
</script>

<?php else: ?>

<p style="color:#ef4444; font-weight:bold;">
    No PDF uploaded for this unit.
</p>

<?php endif; ?>

<?php
$content = ob_get_clean();

render_activity_viewer("📖 Flipbooks", "Let's read together and explore a new story.", $content);
