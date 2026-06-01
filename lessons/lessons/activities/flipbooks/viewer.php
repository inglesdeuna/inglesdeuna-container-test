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
    $serveUrl       = '/lessons/lessons/activities/flipbooks/serve_pdf.php?id=' . rawurlencode($activityId);
    $pdfDisplayUrl  = $serveUrl;                  // inline display
    $pdfDownloadUrl = $serveUrl . '&dl=1';        // forced download
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
                    <a
                        id="view-pdf-btn"
                        class="flipbook-btn flipbook-btn--dark"
                        href="<?php echo htmlspecialchars($pdfDisplayUrl, ENT_QUOTES, 'UTF-8'); ?>"
                        target="_blank"
                        rel="noopener"
                    >&#128065; Open PDF</a>

                    <a
                        id="download-pdf-btn"
                        class="flipbook-btn flipbook-btn--primary"
                        href="<?php echo htmlspecialchars($pdfDownloadUrl, ENT_QUOTES, 'UTF-8'); ?>"
                        download="downloadable.pdf"
                    >&#8659; Download PDF</a>

                    <button type="button" id="full-screen-btn" class="flipbook-btn flipbook-btn--dark">
                        Full Screen
                    </button>

                    <button type="button" id="flipbook-mark-done-btn" class="flipbook-btn flipbook-btn--primary" style="background:linear-gradient(180deg,#16a34a,#15803d);">
                        &#10003; Mark as Completed
                    </button>
                </div>
            </div>

            <div class="flipbook-stage" id="flipbook-stage">
                <object
                    id="pdf-frame"
                    class="flipbook-pdf-frame"
                    data="<?php echo htmlspecialchars($pdfDisplayUrl, ENT_QUOTES, 'UTF-8'); ?>"
                    type="application/pdf"
                >
                    <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;gap:16px;padding:32px;text-align:center;color:#64748b;">
                        <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="#db2777" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="12" x2="12" y2="18"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
                        <p style="margin:0;font-size:15px;font-weight:600;color:#0f172a;">PDF preview is not available in your browser</p>
                        <p style="margin:0;font-size:13px;">Use the buttons above to open or download the PDF.</p>
                    </div>
                </object>
            </div>
        </div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const viewer = document.getElementById('flipbook-viewer');
        const pdfFrame = document.getElementById('pdf-frame');
        const fullScreenTarget = document.getElementById('flipbook-fullscreen-target');
        const fullScreenBtn = document.getElementById('full-screen-btn');

        if (!viewer) {
            return;
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
