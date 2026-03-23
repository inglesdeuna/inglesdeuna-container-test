<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_editor_template.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit       = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
$source     = isset($_GET['source']) ? trim((string) $_GET['source']) : '';
$assignment = isset($_GET['assignment']) ? trim((string) $_GET['assignment']) : '';

if ($activityId === '') {
    die('ID de actividad no especificado.');
}

$stmt = $pdo->prepare("SELECT * FROM activities WHERE id = :id LIMIT 1");
$stmt->execute(['id' => $activityId]);
$activity = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$activity) {
    die('Actividad no encontrada.');
}

$rawData = json_decode($activity['data'] ?? '', true);
if (!is_array($rawData)) {
    $rawData = [];
}

$pageTexts = isset($rawData['page_texts']) && is_array($rawData['page_texts'])
    ? array_values($rawData['page_texts'])
    : [];

$pageCount = isset($rawData['page_count']) ? (int) $rawData['page_count'] : max(count($pageTexts), 1);

$payload = [
    'title'          => isset($rawData['title']) ? (string) $rawData['title'] : 'downloadable',
    'pdf_url'        => isset($rawData['pdf_url']) ? (string) $rawData['pdf_url'] : '',
    'listen_enabled' => array_key_exists('listen_enabled', $rawData) ? (bool) $rawData['listen_enabled'] : true,
    'page_texts'     => $pageTexts,
    'page_count'     => $pageCount,
    'language'       => isset($rawData['language']) ? (string) $rawData['language'] : 'en-US',
];

$currentFileName = '';
if ($payload['pdf_url'] !== '') {
    $path = parse_url($payload['pdf_url'], PHP_URL_PATH);
    $currentFileName = basename($path ?: $payload['pdf_url']);
}

ob_start();
?>

<div class="mb-4">
    <label for="downloadable-title" class="form-label fw-bold">Título de la actividad</label>
    <input
        type="text"
        id="downloadable-title"
        class="form-control form-control-lg"
        value="<?php echo htmlspecialchars($payload['title'], ENT_QUOTES, 'UTF-8'); ?>"
        placeholder="Ej: Reading Practice - Unit 1"
    >
</div>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-body">
                <h5 class="card-title mb-3">
                    <i class="fas fa-file-pdf text-danger me-2"></i>Archivo PDF
                </h5>

                <div id="drop-zone" class="downloadable-dropzone">
                    <div class="downloadable-dropzone__inner">
                        <i class="fas fa-cloud-upload-alt fa-2x text-secondary mb-2"></i>
                        <div class="fw-semibold">Seleccione o arrastre el archivo PDF</div>
                        <div class="text-muted small">Formato permitido: PDF</div>
                    </div>
                </div>

                <input
                    type="file"
                    id="pdf-file"
                    name="pdf"
                    accept="application/pdf,.pdf"
                    style="position:absolute; left:-9999px; width:1px; height:1px; opacity:0;"
                >

                <div id="file-status" class="alert alert-success mt-3 <?php echo $payload['pdf_url'] !== '' ? '' : 'd-none'; ?>">
                    <strong>Archivo actual:</strong>
                    <span id="file-name-display"><?php echo htmlspecialchars($currentFileName, ENT_QUOTES, 'UTF-8'); ?></span>

                    <?php if ($payload['pdf_url'] !== ''): ?>
                        <div class="mt-2">
                            <a
                                href="<?php echo htmlspecialchars($payload['pdf_url'], ENT_QUOTES, 'UTF-8'); ?>"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="btn btn-outline-secondary btn-sm"
                            >
                                Ver PDF actual
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="mt-3">
                    <label for="page-count" class="form-label fw-bold small">Cantidad de páginas</label>
                    <input
                        type="number"
                        id="page-count"
                        class="form-control"
                        min="1"
                        step="1"
                        value="<?php echo (int) $payload['page_count']; ?>"
                    >
                    <div class="form-text">Usada para la navegación del visor y para sincronizar el texto por página.</div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm border-0">
            <div class="card-body">
                <h5 class="card-title mb-3">
                    <i class="fas fa-volume-up text-primary me-2"></i>Opciones de lectura
                </h5>

                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" id="listen-enabled" <?php echo $payload['listen_enabled'] ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="listen-enabled">Habilitar lectura de voz</label>
                </div>

                <label for="voice-lang" class="form-label fw-bold small">Idioma de lectura</label>
                <select id="voice-lang" class="form-select">
                    <option value="en-US" <?php echo $payload['language'] === 'en-US' ? 'selected' : ''; ?>>Inglés (US)</option>
                    <option value="en-GB" <?php echo $payload['language'] === 'en-GB' ? 'selected' : ''; ?>>Inglés (UK)</option>
                    <option value="es-ES" <?php echo $payload['language'] === 'es-ES' ? 'selected' : ''; ?>>Español</option>
                </select>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <h5 class="card-title mb-2">
                    <i class="fas fa-align-left text-info me-2"></i>Texto por página
                </h5>
                <p class="text-muted small">
                    Escriba una línea por cada página del PDF.
                </p>

                <textarea
                    id="page-texts"
                    class="form-control"
                    rows="16"
                    placeholder="Texto página 1&#10;Texto página 2&#10;Texto página 3..."
                ><?php echo htmlspecialchars(implode("\n", $payload['page_texts']), ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>
        </div>
    </div>
</div>

<div class="d-flex justify-content-between align-items-center mt-4 flex-wrap gap-3">
    <span id="unsaved-msg" class="text-warning small d-none">
        <i class="fas fa-exclamation-triangle me-1"></i>Tienes cambios sin guardar.
    </span>

    <button type="button" id="btn-save-downloadable" class="btn btn-primary btn-lg">
        <i class="fas fa-save me-2"></i>Guardar actividad
    </button>
</div>

<style>
.downloadable-dropzone {
    position: relative;
    border: 2px dashed #cbd5e1;
    border-radius: 12px;
    padding: 24px;
    text-align: center;
    background: #f8fafc;
    cursor: pointer;
    transition: all 0.2s ease;
}

.downloadable-dropzone:hover {
    border-color: #60a5fa;
    background: #eff6ff;
}

.downloadable-dropzone.is-dragover {
    border-color: #2563eb;
    background: #dbeafe;
}

.downloadable-dropzone__inner {
    pointer-events: none;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const dropZone = document.getElementById('drop-zone');
    const fileInput = document.getElementById('pdf-file');
    const fileStatus = document.getElementById('file-status');
    const fileNameDisplay = document.getElementById('file-name-display');
    const saveBtn = document.getElementById('btn-save-downloadable');
    const unsavedMsg = document.getElementById('unsaved-msg');

    const titleInput = document.getElementById('downloadable-title');
    const pageTextsInput = document.getElementById('page-texts');
    const pageCountInput = document.getElementById('page-count');
    const listenEnabledInput = document.getElementById('listen-enabled');
    const voiceLangInput = document.getElementById('voice-lang');

    const existingPdfUrl = <?php echo json_encode($payload['pdf_url'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    let hasChanges = false;

    function markAsChanged() {
        hasChanges = true;
        unsavedMsg.classList.remove('d-none');
    }

    function showSelectedFile(file) {
        if (!file) return;
        fileNameDisplay.textContent = file.name;
        fileStatus.classList.remove('d-none');
        markAsChanged();
    }

    function validatePdfFile(file) {
        if (!file) {
            return { ok: false, message: 'No se seleccionó ningún archivo.' };
        }

        const fileName = file.name.toLowerCase();
        const mimeType = (file.type || '').toLowerCase();
        const isPdfByName = fileName.endsWith('.pdf');
        const isPdfByMime = mimeType === 'application/pdf' || mimeType === '';

        if (!isPdfByName && !isPdfByMime) {
            return { ok: false, message: 'Solo se permiten archivos PDF.' };
        }

        return { ok: true };
    }

    dropZone.addEventListener('click', function () {
        fileInput.click();
    });

    fileInput.addEventListener('change', function () {
        const file = this.files && this.files[0] ? this.files[0] : null;
        if (!file) return;

        const validation = validatePdfFile(file);
        if (!validation.ok) {
            alert(validation.message);
            fileInput.value = '';
            return;
        }

        showSelectedFile(file);
    });

    dropZone.addEventListener('dragover', function (e) {
        e.preventDefault();
        dropZone.classList.add('is-dragover');
    });

    dropZone.addEventListener('dragleave', function () {
        dropZone.classList.remove('is-dragover');
    });

    dropZone.addEventListener('drop', function (e) {
        e.preventDefault();
        dropZone.classList.remove('is-dragover');

        const files = e.dataTransfer.files;
        if (!files || !files.length) return;

        const file = files[0];
        const validation = validatePdfFile(file);

        if (!validation.ok) {
            alert(validation.message);
            return;
        }

        fileInput.files = files;
        showSelectedFile(file);
    });

    [titleInput, pageTextsInput, pageCountInput, listenEnabledInput, voiceLangInput].forEach(function (el) {
        el.addEventListener('input', markAsChanged);
        el.addEventListener('change', markAsChanged);
    });

    saveBtn.addEventListener('click', function () {
        const title = titleInput.value.trim();
        const pageCount = parseInt(pageCountInput.value, 10);
        const selectedFile = fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;

        if (!title) {
            alert('Debes escribir un título.');
            return;
        }

        if (!existingPdfUrl && !selectedFile) {
            alert('Debes cargar un PDF.');
            return;
        }

        if (selectedFile) {
            const validation = validatePdfFile(selectedFile);
            if (!validation.ok) {
                alert(validation.message);
                return;
            }
        }

        if (!Number.isInteger(pageCount) || pageCount < 1) {
            alert('Debes indicar una cantidad de páginas válida.');
            return;
        }

        const pageTexts = pageTextsInput.value
            .split(/\r?\n/)
            .map(function (line) { return line.trim(); });

        const formData = new FormData();
        formData.append('id', <?php echo json_encode($activityId); ?>);
        formData.append('unit', <?php echo json_encode($unit); ?>);
        formData.append('source', <?php echo json_encode($source); ?>);
        formData.append('assignment', <?php echo json_encode($assignment); ?>);
        formData.append('title', title);
        formData.append('listen_enabled', listenEnabledInput.checked ? '1' : '0');
        formData.append('language', voiceLangInput.value);
        formData.append('page_count', String(pageCount));
        formData.append('page_texts', JSON.stringify(pageTexts));

        if (selectedFile) {
            formData.append('pdf', selectedFile);
        }

        saveBtn.disabled = true;
        saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Guardando...';

        fetch('save_downloadable.php', {
            method: 'POST',
            body: formData
        })
        .then(function (response) {
            return response.json().catch(function () {
                throw new Error('Respuesta inválida del servidor.');
            });
        })
        .then(function (res) {
            if (res.status === 'success') {
                hasChanges = false;
                unsavedMsg.classList.add('d-none');
                alert(res.message || 'Actividad guardada correctamente.');
                window.location.reload();
            } else {
                alert(res.message || 'No fue posible guardar.');
            }
        })
        .catch(function (error) {
            alert(error.message || 'Error al guardar la actividad.');
        })
        .finally(function () {
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i class="fas fa-save me-2"></i>Guardar actividad';
        });
    });

    window.addEventListener('beforeunload', function (e) {
        if (!hasChanges) return;
        e.preventDefault();
        e.returnValue = '';
    });
});
</script>

<?php
$content = ob_get_clean();
render_activity_editor($payload['title'], 'fas fa-book-open', $content);
