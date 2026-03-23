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

$title         = isset($data['title']) ? (string) $data['title'] : 'Downloadable';
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

ob_start();
?>

<link rel="stylesheet" href="/lessons/lessons/activities/flipbooks/flipbook.css">

<?php if ($pdfUrl === ''): ?>
    <div class="flipbook-empty-state">
        <h3>No hay un PDF cargado todavía</h3>
        <p>Abre el editor del flipbook y sube un archivo para poder visualizarlo.</p>
    </div>
<?php else: ?>
    <div
        class="flipbook-viewer"
        id="flipbook-viewer"
        data-pdf-url="<?php echo htmlspecialchars($pdfUrl, ENT_QUOTES, 'UTF-8'); ?>"
        data-language="<?php echo htmlspecialchars($language, ENT_QUOTES, 'UTF-8'); ?>"
        data-listen-enabled="<?php echo $listenEnabled ? '1' : '0'; ?>"
        data-page-count="<?php echo (int) $pageCount; ?>"
        data-page-texts="<?php echo htmlspecialchars(json_encode($pageTexts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8'); ?>"
    >
        <div class="flipbook-viewer__header">
            <p class="flipbook-viewer__subtitle">
                Visualiza el libro y usa la función Listen para reproducir el texto configurado por página.
            </p>
        </div>

        <div class="flipbook-viewer__card">
            <div class="flipbook-toolbar">
                <div class="flipbook-toolbar__left">
                    <button type="button" id="prev-btn" class="flipbook-btn flipbook-btn--secondary">
                        Anterior
                    </button>

                    <button type="button" id="next-btn" class="flipbook-btn flipbook-btn--secondary">
                        Siguiente
                    </button>
                </div>

                <div class="flipbook-toolbar__center">
                    <span class="flipbook-page-badge">
                        Página <span id="current-page">1</span> / <span id="total-pages"><?php echo (int) $pageCount; ?></span>
                    </span>
                </div>

                <div class="flipbook-toolbar__right">
                    <?php if ($listenEnabled): ?>
                        <button type="button" id="listen-btn" class="flipbook-btn flipbook-btn--primary">
                            Listen
                        </button>

                        <button type="button" id="stop-listen-btn" class="flipbook-btn flipbook-btn--secondary">
                            Detener
                        </button>
                    <?php endif; ?>

                    <button type="button" id="open-pdf-btn" class="flipbook-btn flipbook-btn--secondary">
                        Abrir PDF
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
                    src="<?php echo htmlspecialchars($pdfUrl, ENT_QUOTES, 'UTF-8'); ?>#page=1&toolbar=1&navpanes=0&scrollbar=1"
                    title="Flipbook PDF"
                ></iframe>
            </div>

            <div class="flipbook-listen-panel">
                <div class="flipbook-listen-panel__label">Texto configurado para la página actual</div>
                <div id="current-page-text" class="flipbook-page-text-box">
                    <?php
                    $initialText = trim((string) ($pageTexts[0] ?? ''));
                    echo htmlspecialchars($initialText !== '' ? $initialText : 'No hay texto definido para esta página.', ENT_QUOTES, 'UTF-8');
                    ?>
                </div>
            </div>
        </div>
    </div>

    <script src="/lessons/lessons/activities/flipbooks/flipbook.js"></script>
<?php endif; ?>

<?php
$content = ob_get_clean();
render_activity_viewer($title, '📘', $content);
