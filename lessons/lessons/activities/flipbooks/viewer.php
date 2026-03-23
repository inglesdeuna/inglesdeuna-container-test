<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';

if ($activityId === '') {
    die('ID de actividad no especificado.');
}

$stmt = $pdo->prepare("SELECT id, data FROM activities WHERE id = :id LIMIT 1");
$stmt->execute(['id' => $activityId]);
$activity = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$activity) {
    die('Actividad no encontrada.');
}

$data = json_decode($activity['data'] ?? '', true);
if (!is_array($data)) {
    $data = [];
}

$title  = isset($data['title']) ? (string) $data['title'] : 'Downloadable';
$pdfUrl = isset($data['pdf_url']) ? (string) $data['pdf_url'] : '';

ob_start();
?>

<link rel="stylesheet" href="/lessons/lessons/activities/flipbooks/flipbook.css">

<?php if ($pdfUrl === ''): ?>
    <div class="flipbook-empty-state">
        <h3>No hay documento cargado</h3>
        <p>Abre el editor y sube un PDF.</p>
    </div>
<?php else: ?>

<div class="flipbook-viewer">

    <p style="text-align:center; margin-bottom:10px; color:#475569;">
        Visualiza el documento o descárgalo para consultarlo.
    </p>

    <div class="flipbook-viewer__card">

        <div class="flipbook-toolbar">

            <button id="open-pdf-btn" class="flipbook-btn flipbook-btn--primary">
                Abrir PDF
            </button>

            <button id="download-pdf-btn" class="flipbook-btn flipbook-btn--secondary">
                Descargar
            </button>

            <button id="full-screen-btn" class="flipbook-btn flipbook-btn--dark">
                Pantalla completa
            </button>

        </div>

        <div class="flipbook-stage" id="flipbook-stage">
            <iframe
                id="pdf-frame"
                class="flipbook-pdf-frame"
                src="<?php echo htmlspecialchars($pdfUrl, ENT_QUOTES, 'UTF-8'); ?>"
                title="Documento PDF"
            ></iframe>
        </div>

    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const pdfUrl = <?php echo json_encode($pdfUrl); ?>;

    document.getElementById('open-pdf-btn').onclick = function () {
        window.open(pdfUrl, '_blank');
    };

    document.getElementById('download-pdf-btn').onclick = function () {
        const a = document.createElement('a');
        a.href = pdfUrl;
        a.download = '';
        a.click();
    };

    document.getElementById('full-screen-btn').onclick = function () {
        const el = document.getElementById('flipbook-stage');
        if (!document.fullscreenElement) {
            el.requestFullscreen();
        } else {
            document.exitFullscreen();
        }
    };

});
</script>

<?php endif; ?>

<?php
$content = ob_get_clean();
render_activity_viewer($title, '📄', $content);
