<?php
session_start();

require_once __DIR__ . "/../../core/db.php";

/* 1️⃣ DEFINIR UNIT */
$unit = $_GET['unit'] ?? null;
if (!$unit) die("Unidad no especificada");

/* 2️⃣ CONSULTA DB */
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
    $decoded = json_decode($row['data'], true);
    $pdfPath = $decoded['pdf'] ?? "";
}

/* 3️⃣ GENERAR CONTENIDO */
ob_start();
?>

<?php if ($pdfPath): ?>

<div id="flipbook" style="margin:auto; width:900px; height:600px;"></div>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/turn.js/4.1.0/turn.min.css" />
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/turn.js/4.1.0/turn.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.min.js"></script>

<script>
const pdfUrl = "/lessons/lessons/<?= htmlspecialchars($pdfPath) ?>";

pdfjsLib.getDocument(pdfUrl).promise.then(function(pdf) {

    const flipbook = document.getElementById("flipbook");

    for (let i = 1; i <= pdf.numPages; i++) {
        const pageDiv = document.createElement("div");
        pageDiv.style.width = "450px";
        pageDiv.style.height = "600px";

        const canvas = document.createElement("canvas");
        pageDiv.appendChild(canvas);
        flipbook.appendChild(pageDiv);

        pdf.getPage(i).then(function(page) {
            const viewport = page.getViewport({scale: 1});
            canvas.width = viewport.width;
            canvas.height = viewport.height;

            page.render({
                canvasContext: canvas.getContext("2d"),
                viewport: viewport
            });
        });
    }

    $("#flipbook").turn({
        width: 900,
        height: 600,
        autoCenter: true
    });

});
</script>

<?php else: ?>


<?php
$activityContent = ob_get_clean();

/* 4️⃣ VARIABLES PARA TEMPLATE */
$activityTitle = "📖 Flipbooks";
$activitySubtitle = "Let's read together and explore a new story.";

/* 5️⃣ REQUIERE TEMPLATE AL FINAL */
require_once __DIR__ . "/../../core/_activity_viewer_template.php";
