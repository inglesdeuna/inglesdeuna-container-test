<?php
require_once __DIR__ . '/../../config/db.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit       = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';

if ($activityId === '') {
    die('ID de actividad no especificado.');
}

$stmt = $pdo->prepare("SELECT * FROM activities WHERE id = :id LIMIT 1");
$stmt->execute(['id' => $activityId]);
$activity = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$activity) {
    die('Actividad no encontrada.');
}

$data = json_decode($activity['data'] ?? '', true);
if (!is_array($data)) {
    $data = [];
}

$title          = isset($data['title']) ? (string) $data['title'] : 'Flipbook';
$pdfUrl         = isset($data['pdf_url']) ? (string) $data['pdf_url'] : '';
$pageTexts      = isset($data['page_texts']) && is_array($data['page_texts']) ? $data['page_texts'] : [];
$listenEnabled  = array_key_exists('listen_enabled', $data) ? (bool) $data['listen_enabled'] : true;
$language       = isset($data['language']) ? (string) $data['language'] : 'en-US';

if ($pdfUrl === '') {
    die(
        '<div style="max-width:700px;margin:40px auto;padding:32px;background:#fff;border:1px solid #e5e7eb;border-radius:16px;text-align:center;color:#475569;">' .
        '<h3 style="margin-bottom:10px;">No hay un PDF cargado todavía</h3>' .
        '<p style="margin:0;">Abre el editor del flipbook y sube un archivo para poder visualizarlo.</p>' .
        '</div>'
    );
}

include __DIR__ . '/../../core/_activity_viewer_template.php';
?>

<div class="flipbook-viewer">
    <div class="flipbook-viewer__header mb-4">
        <h2 class="mb-1"><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h2>
        <p class="text-muted mb-0">Visualiza el libro y usa la función Listen para reproducir el texto configurado por página.</p>
    </div>

    <div class="card shadow-sm border-0 flipbook-viewer__card">
        <div class="card-body">
            <div class="flipbook-toolbar">
                <div class="flipbook-toolbar__left">
                    <button type="button" id="prev-btn" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-chevron-left me-1"></i>Anterior
                    </button>

                    <button type="button" id="next-btn" class="btn btn-outline-secondary btn-sm">
                        Siguiente<i class="fas fa-chevron-right ms-1"></i>
                    </button>
                </div>

                <div class="flipbook-toolbar__center">
                    <span class="flipbook-page-badge">
                        Página <span id="current-page">1</span>
                        <?php if (!empty($pageTexts)): ?>
                            / <span id="total-pages"><?php echo count($pageTexts); ?></span>
                        <?php else: ?>
                            / <span id="total-pages">1</span>
                        <?php endif; ?>
                    </span>
                </div>

                <div class="flipbook-toolbar__right">
                    <?php if ($listenEnabled): ?>
                        <button type="button" id="listen-btn" class="btn btn-primary btn-sm">
                            <i class="fas fa-volume-up me-1"></i>Listen
                        </button>

                        <button type="button" id="stop-listen-btn" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-stop me-1"></i>Detener
                        </button>
                    <?php endif; ?>

                    <button type="button" id="open-pdf-btn" class="btn btn-outline-primary btn-sm">
                        <i class="fas fa-external-link-alt me-1"></i>Abrir PDF
                    </button>

                    <button type="button" id="full-screen-btn" class="btn btn-outline-dark btn-sm">
                        <i class="fas fa-expand me-1"></i>Pantalla completa
                    </button>
                </div>
            </div>

            <div class="flipbook-stage mt-3" id="flipbook-stage">
                <iframe
                    id="pdf-frame"
                    class="flipbook-pdf-frame"
                    src="<?php echo htmlspecialchars($pdfUrl, ENT_QUOTES, 'UTF-8'); ?>#toolbar=1&navpanes=0&scrollbar=1"
                    title="Flipbook PDF"
                ></iframe>
            </div>

            <div class="flipbook-listen-panel mt-3">
                <div class="small text-muted mb-2">Texto configurado para la página actual</div>
                <div id="current-page-text" class="flipbook-page-text-box">
                    <?php
                    $initialText = isset($pageTexts[0]) ? (string) $pageTexts[0] : 'No hay texto definido para esta página.';
                    echo nl2br(htmlspecialchars($initialText, ENT_QUOTES, 'UTF-8'));
                    ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(function () {
    const pdfUrl = <?php echo json_encode($pdfUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const pageTexts = <?php echo json_encode(array_values($pageTexts), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const voiceLang = <?php echo json_encode($language, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

    let currentPage = 1;
    let totalPages = pageTexts.length > 0 ? pageTexts.length : 1;

    function updatePageInfo() {
        $('#current-page').text(currentPage);
        $('#total-pages').text(totalPages);

        const pageText = pageTexts[currentPage - 1] || 'No hay texto definido para esta página.';
        $('#current-page-text').text(pageText);
    }

    function stopSpeaking() {
        if ('speechSynthesis' in window) {
            window.speechSynthesis.cancel();
        }
    }

    $('#prev-btn').on('click', function () {
        if (currentPage > 1) {
            currentPage--;
            stopSpeaking();
            updatePageInfo();
        }
    });

    $('#next-btn').on('click', function () {
        if (currentPage < totalPages) {
            currentPage++;
            stopSpeaking();
            updatePageInfo();
        }
    });

    $('#listen-btn').on('click', function () {
        stopSpeaking();

        const text = pageTexts[currentPage - 1] || '';
        if (!text) {
            alert('No hay texto configurado para esta página.');
            return;
        }

        if (!('speechSynthesis' in window)) {
            alert('Este navegador no soporta lectura de voz.');
            return;
        }

        const utterance = new SpeechSynthesisUtterance(text);
        utterance.lang = voiceLang;
        window.speechSynthesis.speak(utterance);
    });

    $('#stop-listen-btn').on('click', function () {
        stopSpeaking();
    });

    $('#open-pdf-btn').on('click', function () {
        window.open(pdfUrl, '_blank');
    });

    $('#full-screen-btn').on('click', function () {
        const container = document.getElementById('flipbook-stage');

        if (!document.fullscreenElement) {
            if (container.requestFullscreen) {
                container.requestFullscreen();
            }
        } else {
            if (document.exitFullscreen) {
                document.exitFullscreen();
            }
        }
    });

    updatePageInfo();
});
</script>

<style>
.flipbook-viewer__card {
    border-radius: 16px;
}

.flipbook-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
}

.flipbook-toolbar__left,
.flipbook-toolbar__center,
.flipbook-toolbar__right {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.flipbook-page-badge {
    display: inline-flex;
    align-items: center;
    min-height: 38px;
    padding: 0 14px;
    border-radius: 999px;
    background: #f3f4f6;
    color: #374151;
    font-weight: 600;
}

.flipbook-stage {
    width: 100%;
    min-height: 720px;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    overflow: hidden;
    background: #f8fafc;
}

.flipbook-pdf-frame {
    width: 100%;
    height: 720px;
    border: 0;
    display: block;
    background: #fff;
}

.flipbook-listen-panel {
    border-top: 1px solid #eef2f7;
    padding-top: 16px;
}

.flipbook-page-text-box {
    min-height: 70px;
    padding: 14px 16px;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    background: #fafafa;
    color: #334155;
    white-space: pre-wrap;
}

@media (max-width: 768px) {
    .flipbook-stage,
    .flipbook-pdf-frame {
        min-height: 520px;
        height: 520px;
    }
}
</style>

<?php
if (file_exists(__DIR__ . '/../../core/_activity_viewer_footer.php')) {
    include __DIR__ . '/../../core/_activity_viewer_footer.php';
}
?>
