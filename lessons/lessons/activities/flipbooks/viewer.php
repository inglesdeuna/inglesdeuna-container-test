<?php
require_once __DIR__ . '/../../config/db.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit       = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';

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

$title         = isset($data['title']) ? (string) $data['title'] : 'Flipbook';
$pdfUrl        = isset($data['pdf_url']) ? (string) $data['pdf_url'] : '';
$pageTexts     = isset($data['page_texts']) && is_array($data['page_texts']) ? array_values($data['page_texts']) : [];
$pageCount     = isset($data['page_count']) ? (int) $data['page_count'] : max(count($pageTexts), 1);
$listenEnabled = array_key_exists('listen_enabled', $data) ? (bool) $data['listen_enabled'] : true;
$language      = isset($data['language']) ? (string) $data['language'] : 'en-US';

if ($pageCount < 1) {
    $pageCount = 1;
}

if (count($pageTexts) < $pageCount) {
    $pageTexts = array_pad($pageTexts, $pageCount, '');
} elseif (count($pageTexts) > $pageCount) {
    $pageTexts = array_slice($pageTexts, 0, $pageCount);
}

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

<link rel="stylesheet" href="flipbook.css">

<div
    class="flipbook-viewer"
    id="flipbook-viewer"
    data-pdf-url="<?php echo htmlspecialchars($pdfUrl, ENT_QUOTES, 'UTF-8'); ?>"
    data-language="<?php echo htmlspecialchars($language, ENT_QUOTES, 'UTF-8'); ?>"
    data-listen-enabled="<?php echo $listenEnabled ? '1' : '0'; ?>"
    data-page-count="<?php echo (int) $pageCount; ?>"
    data-page-texts="<?php echo htmlspecialchars(json_encode($pageTexts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8'); ?>"
>
    <div class="flipbook-viewer__header mb-4">
        <h2 class="mb-1"><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h2>
        <p class="text-muted mb-0">
            Visualiza el libro y usa la función Listen para reproducir el texto configurado por página.
        </p>
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
                        Página <span id="current-page">1</span> / <span id="total-pages"><?php echo (int) $pageCount; ?></span>
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
                    src=""
                    title="Flipbook PDF"
                ></iframe>
            </div>

            <div class="flipbook-listen-panel mt-3">
                <div class="small text-muted mb-2">Texto configurado para la página actual</div>
                <div id="current-page-text" class="flipbook-page-text-box">
                    <?php
                    $initialText = trim((string) ($pageTexts[0] ?? ''));
                    echo htmlspecialchars($initialText !== '' ? $initialText : 'No hay texto definido para esta página.', ENT_QUOTES, 'UTF-8');
                    ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="flipbook.js"></script>

<?php
if (file_exists(__DIR__ . '/../../core/_activity_viewer_footer.php')) {
    include __DIR__ . '/../../core/_activity_viewer_footer.php';
}
?>
