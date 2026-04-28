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
 * Do not rename folders, routes, type, or module files.
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

<style>
.flipbook-completed-screen{display:none;text-align:center;max-width:600px;margin:40px auto;padding:40px 20px}
.flipbook-completed-screen.active{display:block}
.flipbook-completed-icon{font-size:80px;margin-bottom:20px}
.flipbook-completed-title{font-family:'Fredoka','Trebuchet MS',sans-serif;font-size:36px;font-weight:700;color:#be185d;margin:0 0 14px;line-height:1.2}
.flipbook-completed-text{font-size:16px;color:#6b4b5f;line-height:1.6;margin:0 0 28px}
.flipbook-completed-actions{display:flex;gap:10px;justify-content:center;flex-wrap:wrap}
.flipbook-completed-btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 14px;border:none;border-radius:10px;background:linear-gradient(180deg,#3d73ee 0%,#2563eb 100%);color:#fff;font-weight:700;font-size:13px;font-family:'Nunito','Segoe UI',sans-serif;line-height:1;cursor:pointer;box-shadow:0 10px 22px rgba(37,99,235,.28);transition:transform .18s ease,filter .18s ease}
.flipbook-completed-btn:hover{filter:brightness(1.07);transform:translateY(-1px)}
</style>

<?php if ($pdfDisplayUrl === ''): ?>
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
        <div class="flipbook-viewer__card" id="flipbook-fullscreen-target">
            <div class="flipbook-toolbar">
                <div class="flipbook-toolbar__right">
                    <button type="button" id="download-pdf-btn" class="flipbook-btn flipbook-btn--primary">
                        Download PDF
                    </button>

                    <button type="button" id="full-screen-btn" class="flipbook-btn flipbook-btn--dark">
                        Full Screen
                    </button>

                    <button type="button" id="flipbook-mark-done-btn" class="flipbook-btn flipbook-btn--primary" style="background:linear-gradient(180deg,#16a34a,#15803d);">
                        ✓ Mark as Completed
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
        const downloadBtn = document.getElementById('download-pdf-btn');
        const fullScreenBtn = document.getElementById('full-screen-btn');

        if (!viewer) {
            return;
        }

        const pdfUrl = viewer.getAttribute('data-pdf-url') || '';
        const pdfDownloadUrl = viewer.getAttribute('data-pdf-download-url') || pdfUrl;

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

        // Mark as Completed button
        const markDoneBtn = document.getElementById('flipbook-mark-done-btn');
        const completedScreen = document.getElementById('flipbook-completed-screen');
        const viewerWrap = document.getElementById('flipbook-viewer');

        if (markDoneBtn && completedScreen) {
            markDoneBtn.addEventListener('click', function () {
                if (viewerWrap) viewerWrap.style.display = 'none';
                completedScreen.classList.add('active');
            });
        }

        const restartBtn = document.getElementById('flipbook-restart-btn');
        if (restartBtn && completedScreen) {
            restartBtn.addEventListener('click', function () {
                completedScreen.classList.remove('active');
                if (viewerWrap) viewerWrap.style.display = '';
            });
        }
    });
    </script>
<?php endif; ?>

<div id="flipbook-completed-screen" class="flipbook-completed-screen">
    <div class="flipbook-completed-icon">✅</div>
    <h2 class="flipbook-completed-title"><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h2>
    <p class="flipbook-completed-text">You've reviewed the material. Great job studying!</p>
    <div class="flipbook-completed-actions">
        <button type="button" class="flipbook-completed-btn" id="flipbook-restart-btn">Back</button>
    </div>
</div>

<?php
$content = ob_get_clean();
render_activity_viewer($title, '📄', $content);
