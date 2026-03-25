<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_viewer_template.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';

if ($activityId === '') {
    die('Activity ID not specified.');
}

$stmt = $pdo->prepare("SELECT id, data FROM activities WHERE id = :id LIMIT 1");
$stmt->execute(['id' => $activityId]);
$activity = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$activity) {
    die('Activity not found.');
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
    // Always serve via serve_pdf.php — works for both base64 (DB) and legacy file paths.
    // This is resilient to Render's ephemeral filesystem.
    $serveUrl = '/lessons/lessons/activities/flipbooks/serve_pdf.php?id=' . rawurlencode($activityId);
    $pdfDisplayUrl  = $serveUrl;
    $pdfDownloadUrl = $serveUrl;
}

ob_start();
?>

<link rel="stylesheet" href="/lessons/lessons/activities/flipbooks/flipbook.css">

<?php if ($pdfDisplayUrl === ''): ?>
    <div class="flipbook-intro">
        <h2>Downloadable Material</h2>
        <p>Open the document in the browser, download it, or use fullscreen mode for easier reading on any screen size.</p>
    </div>

    <div class="flipbook-empty-state">
        <h3>No PDF has been uploaded yet</h3>
        <p>Open the editor and upload a file to preview it here.</p>
    </div>
<?php else: ?>
    <div
        class="flipbook-viewer"
        id="flipbook-viewer"
        data-pdf-url="<?php echo htmlspecialchars($pdfDisplayUrl, ENT_QUOTES, 'UTF-8'); ?>"
        data-pdf-download-url="<?php echo htmlspecialchars($pdfDownloadUrl, ENT_QUOTES, 'UTF-8'); ?>"
    >
        <div class="flipbook-viewer__header">
            <div class="flipbook-intro">
                <h2>Downloadable Material</h2>
                <p>Open the document in the browser, download it, or use fullscreen mode for easier reading on any screen size.</p>
            </div>

            <p class="flipbook-viewer__subtitle">
                Preview the document here or download it for later review.
            </p>
        </div>

        <div class="flipbook-viewer__card" id="flipbook-fullscreen-target">
            <div class="flipbook-toolbar">
                <div class="flipbook-toolbar__right">
                    <button type="button" id="open-pdf-btn" class="flipbook-btn flipbook-btn--secondary">
                        Abrir PDF
                    </button>

                    <button type="button" id="download-pdf-btn" class="flipbook-btn flipbook-btn--primary">
                        Download PDF
                    </button>

                    <button type="button" id="full-screen-btn" class="flipbook-btn flipbook-btn--dark">
                        Full Screen
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
        const fullScreenTarget = document.getElementById('flipbook-fullscreen-target');
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

        function updateFullscreenButtonState() {
            if (!fullScreenBtn || !fullScreenTarget) {
                return;
            }

            const isFullscreen = document.fullscreenElement === fullScreenTarget;
            fullScreenBtn.textContent = isFullscreen ? 'Exit Full Screen' : 'Full Screen';
        }

        if (fullScreenBtn && fullScreenTarget && pdfFrame) {
            fullScreenBtn.addEventListener('click', function () {
                if (document.fullscreenElement === fullScreenTarget) {
                    document.exitFullscreen().catch(function () {});
                } else {
                    fullScreenTarget.requestFullscreen().catch(function () {});
                }
            });

            document.addEventListener('fullscreenchange', updateFullscreenButtonState);
            updateFullscreenButtonState();
        }
    });
    </script>
<?php endif; ?>

<?php
$content = ob_get_clean();
render_activity_viewer($title, '📄', $content);
