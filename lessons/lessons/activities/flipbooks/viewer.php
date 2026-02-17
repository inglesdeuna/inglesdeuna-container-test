<?php
session_start();

require_once __DIR__ . "/../../core/db.php";
require_once __DIR__ . "/../../core/_activity_viewer_template.php";

/* ==========================
   VALIDAR UNIT
========================== */
$unit = $_GET['unit'] ?? null;
if (!$unit) {
    die("Unit not specified");
}

/* ==========================
   OBTENER PDF DESDE DB
========================== */
$stmt = $pdo->prepare("
    SELECT data
    FROM activities
    WHERE unit_id = :unit
    AND type = 'flipbooks'
    LIMIT 1
");
$stmt->execute([":unit" => $unit]);
$row = $stmt->fetchColumn();

$pdfPath = "";

if ($row) {
    $decoded = json_decode($row, true);
    $pdfPath = $decoded["pdf"] ?? "";
}

/* ==========================
   VALIDAR EXISTENCIA FISICA
========================== */
$absolutePath = __DIR__ . "/../../" . $pdfPath;
$fileExists = $pdfPath && file_exists($absolutePath);

/* ==========================
   CONTENIDO DEL VISOR
========================== */
ob_start();
?>

<?php if ($fileExists): ?>

<div style="text-align:center;">
    <canvas id="pdf-canvas" style="border-radius:12px;"></canvas>
</div>

<div style="margin-top:15px; text-align:center;">
    <button onclick="prevPage()">⬅ Prev</button>
    <span id="page-info"></span>
    <button onclick="nextPage()">Next ➡</button>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script>
const url = "/<?= htmlspecialchars($pdfPath) ?>";

let pdfDoc = null,
    pageNum = 1,
    pageRendering = false,
    pageNumPending = null,
    scale = 1.2,
    canvas = document.getElementById('pdf-canvas'),
    ctx = canvas.getContext('2d');

pdfjsLib.getDocument(url).promise.then(function(pdfDoc_) {
    pdfDoc = pdfDoc_;
    document.getElementById('page-info').textContent =
        "Page " + pageNum + " / " + pdfDoc.numPages;
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
        "Page " + num + " / " + pdfDoc.numPages;
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

<p style="color:#ef4444; font-weight:bold; text-align:center;">
    No PDF uploaded for this unit.
</p>

<?php endif; ?>

<?php
$content = ob_get_clean();

/* ==========================
   RENDER TEMPLATE
========================== */
render_activity_viewer(
    "📖 Flipbooks",
    "📖",
    "Let's read together and explore a new story.",
    $content
);
