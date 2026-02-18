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

<div style="position:relative; width:900px; margin:auto;">
    <div id="flipbook" style="width:900px; height:600px;"></div>

    <div id="prevBtn" style="position:absolute; bottom:15px; left:10px; cursor:pointer; font-size:28px;">⬅</div>
    <div id="nextBtn" style="position:absolute; bottom:15px; right:10px; cursor:pointer; font-size:28px;">➡</div>
</div>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/turn.js/4.1.0/turn.min.css" />
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/turn.js/4.1.0/turn.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.min.js"></script>

<script>
const pdfUrl = "/lessons/lessons/<?= htmlspecialchars($pdfPath) ?>";

pdfjsLib.getDocument(pdfUrl).promise.then(function(pdf) {

    const flipbook = document.getElementById("flipbook");
    const totalPages = pdf.numPages;
    let renderedPages = 0;

    for (let i = 1; i <= totalPages; i++) {

        const pageDiv = document.createElement("div");
        pageDiv.style.width = "450px";
        pageDiv.style.height = "600px";
        pageDiv.style.background = "#fff";

        const canvas = document.createElement("canvas");
        pageDiv.appendChild(canvas);
        flipbook.appendChild(pageDiv);

        pdf.getPage(i).then(function(page) {

            const viewport = page.getViewport({ scale: 1 });

const scale = 450 / viewport.width; // forzar ancho exacto de media página
const scaledViewport = page.getViewport({ scale: scale });

canvas.width = scaledViewport.width;
canvas.height = scaledViewport.height;

page.render({
    canvasContext: canvas.getContext("2d"),
    viewport: scaledViewport
});


          const viewport = page.getViewport({ scale: 1 });

const scale = 450 / viewport.width;
const scaledViewport = page.getViewport({ scale: scale });

canvas.width = scaledViewport.width;
canvas.height = scaledViewport.height;

page.render({
    canvasContext: canvas.getContext("2d"),
    viewport: scaledViewport
});


                // Inicializar turn.js SOLO cuando todas las páginas estén listas
                if (renderedPages === totalPages) {

                    $("#flipbook").turn({
                        width: 900,
                        height: 600,
                        autoCenter: true,
                        display: "single", // empieza como carátula
                        elevation: 50,
                        gradients: true,
                        when: {
                            turning: function(event, page) {
                                if (page === 1) {
                                    $(this).turn("display", "single");
                                } else {
                                    $(this).turn("display", "double");
                                }
                            }
                        }
                    });

                    // Flechas
                    document.getElementById("prevBtn").onclick = function() {
                        $("#flipbook").turn("previous");
                    };

                    document.getElementById("nextBtn").onclick = function() {
                        $("#flipbook").turn("next");
                    };

                }

            });

        });

    }

});
</script>

<?php else: ?>

<p style="color:#dc2626; font-weight:600; text-align:center;">
    No PDF uploaded for this unit.
</p>

<?php endif; ?>

<?php
$activityContent = ob_get_clean();


/* 4️⃣ VARIABLES PARA TEMPLATE */
$activityTitle = "📖 Flipbooks";
$activitySubtitle = "Let's read together and explore a new story.";

/* 5️⃣ REQUIERE TEMPLATE AL FINAL */
require_once __DIR__ . "/../../core/_activity_viewer_template.php";
