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

/*
 * IMPORTANTE:
 * Dejamos todo funcionando igual, solo cambiamos el nombre visible.
 * No renombramos carpetas, rutas, type ni archivos del módulo.
 */
$title         = 'Downloadable';
$pdfUrl        = isset($data['pdf_url']) ? (string) $data['pdf_url'] : '';

$pdfDisplayUrl = '';
$pdfDownloadUrl = '';

if ($pdfUrl !== '') {
    $rawPdfUrl = trim($pdfUrl);
    $pathPart = parse_url($rawPdfUrl, PHP_URL_PATH);
    $localName = basename((string) ($pathPart !== null && $pathPart !== false ? $pathPart : $rawPdfUrl));
    $localPath = __DIR__ . '/uploads/pdfs/' . $localName;

    if ($localName !== '' && is_file($localPath)) {
        $canonicalLocalUrl = '/lessons/lessons/activities/flipbooks/uploads/pdfs/' . rawurlencode($localName);
        $pdfDisplayUrl = $canonicalLocalUrl;
        $pdfDownloadUrl = $canonicalLocalUrl;
    } elseif (preg_match('/^https?:\/\//i', $rawPdfUrl) === 1) {
        $proxyBase = '/lessons/lessons/activities/flipbooks/pdf_proxy.php?url=';
        $pdfDisplayUrl = $proxyBase . rawurlencode($rawPdfUrl);
        $pdfDownloadUrl = $rawPdfUrl;
    } else {
        $normalized = '/' . ltrim($rawPdfUrl, '/');
        $pdfDisplayUrl = $normalized;
        $pdfDownloadUrl = $normalized;
    }
}

ob_start();
?>

<link rel="stylesheet" href="/lessons/lessons/activities/flipbooks/flipbook.css">

<?php if ($pdfDisplayUrl === ''): ?>
    <div class="flipbook-empty-state">
        <h3>No hay un PDF cargado todavía</h3>
        <p>Abre el editor y sube un archivo para poder visualizarlo.</p>
    </div>
<?php else: ?>
    <div
        class="flipbook-viewer"
        id="flipbook-viewer"
        data-pdf-url="<?php echo htmlspecialchars($pdfDisplayUrl, ENT_QUOTES, 'UTF-8'); ?>"
        data-pdf-download-url="<?php echo htmlspecialchars($pdfDownloadUrl, ENT_QUOTES, 'UTF-8'); ?>"
    >
        <div class="flipbook-viewer__header">
            <p class="flipbook-viewer__subtitle">
                Visualiza el documento o descárgalo para consultarlo.
            </p>
        </div>

        <div class="flipbook-viewer__card">
            <div class="flipbook-toolbar">
                <div class="flipbook-toolbar__right">
                    <button type="button" id="open-pdf-btn" class="flipbook-btn flipbook-btn--secondary">
                        Abrir PDF
                    </button>

                    <button type="button" id="download-pdf-btn" class="flipbook-btn flipbook-btn--primary">
                        Descargar PDF
                    </button>

                    <button type="button" id="full-screen-btn" class="flipbook-btn flipbook-btn--dark">
                        Pantalla completa
                    </button>
                </div>
            </div>

            <div class="flipbook-stage" id="flipbook-stage">
                <iframe
                    id="pdf-frame"
                    class="flipbook-pdf-frame"
                    src="<?php echo htmlspecialchars($pdfDisplayUrl, ENT_QUOTES, 'UTF-8'); ?>#toolbar=1&navpanes=0&scrollbar=1"
                    title="Documento PDF"
                ></iframe>
            </div>
        </div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const viewer = document.getElementById('flipbook-viewer');
        const pdfFrame = document.getElementById('pdf-frame');
        const openBtn = document.getElementById('open-pdf-btn');
        const downloadBtn = document.getElementById('download-pdf-btn');
        const fullScreenBtn = document.getElementById('full-screen-btn');

        if (!viewer) {
            return;
        }

        const pdfUrl = viewer.getAttribute('data-pdf-url') || '';
        const pdfDownloadUrl = viewer.getAttribute('data-pdf-download-url') || pdfUrl;

        if (openBtn) {
            openBtn.addEventListener('click', function () {
                if (!pdfUrl) {
                    return;
                }
                window.open(pdfUrl, '_blank', 'noopener');
            });
        }

        if (downloadBtn) {
            downloadBtn.addEventListener('click', function () {
                if (!pdfDownloadUrl) {
                    return;
                }

                const link = document.createElement('a');
                link.href = pdfDownloadUrl;
                link.target = '_blank';
                link.rel = 'noopener';
                link.download = 'downloadable.pdf';
                document.body.appendChild(link);
                link.click();
                link.remove();
            });
        }

        if (fullScreenBtn && pdfFrame) {
            fullScreenBtn.addEventListener('click', function () {
                const stage = document.getElementById('flipbook-stage');
                if (!stage) {
                    return;
                }

                if (document.fullscreenElement) {
                    document.exitFullscreen().catch(function () {});
                } else {
                    stage.requestFullscreen().catch(function () {});
                }
            });
        }
    });
    </script>
<?php endif; ?>

<?php
$content = ob_get_clean();
render_activity_viewer($title, '📄', $content);
