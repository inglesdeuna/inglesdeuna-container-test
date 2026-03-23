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

$payload = [
    'title'          => isset($rawData['title']) ? (string) $rawData['title'] : 'Flipbook',
    'pdf_url'        => isset($rawData['pdf_url']) ? (string) $rawData['pdf_url'] : '',
    'listen_enabled' => array_key_exists('listen_enabled', $rawData) ? (bool) $rawData['listen_enabled'] : true,
    'page_texts'     => isset($rawData['page_texts']) && is_array($rawData['page_texts']) ? array_values($rawData['page_texts']) : [],
    'page_count'     => isset($rawData['page_count']) ? max(1, (int) $rawData['page_count']) : max(1, count($rawData['page_texts'] ?? [])),
    'language'       => isset($rawData['language']) ? (string) $rawData['language'] : 'en-US',
];

ob_start();
?>

<div class="mb-4">
    <label for="flipbook-title" class="form-label fw-bold">Título de la actividad</label>
    <input
        type="text"
        id="flipbook-title"
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

                <div id="drop-zone" class="border rounded p-4 text-center bg-light" style="cursor:pointer;">
                    <i class="fas fa-cloud-upload-alt fa-2x text-secondary mb-2"></i>
                    <div class="fw-semibold">Seleccione o arrastre el archivo PDF</div>
                    <div class="text-muted small">Formato permitido: PDF</div>
                    <input type="file" id="pdf-file" accept="application/pdf,.pdf" class="d-none">
                </div>

                <div id="file-status" class="alert alert-success mt-3 <?php echo $payload['pdf_url'] !== '' ? '' : 'd-none'; ?>">
                    <strong>Archivo actual:</strong>
                    <span id="file-name-display"><?php echo htmlspecialchars(basename(parse_url($payload['pdf_url'], PHP_URL_PATH) ?? $payload['pdf_url']), ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php if ($payload['pdf_url'] !== ''): ?>
                        <div class="mt-2">
                            <a href="<?php echo htmlspecialchars($payload['pdf_url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="btn btn-outline-secondary btn-sm">
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

    <button type="button" id="btn-save-flipbook" class="btn btn-primary btn-lg">
        <i class="fas fa-save me-2"></i>Guardar actividad
    </button>
</div>

<script>
$(function () {
    let hasChanges = false;
    const existingPdfUrl = <?php echo json_encode($payload['pdf_url'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

    function markAsChanged() {
        hasChanges = true;
        $('#unsaved-msg').removeClass('d-none');
    }

    function setSelectedFile(file) {
        if (!file) return;

        const fileName = (file.name || '').toLowerCase();
        const isPdf = file.type === 'application/pdf' || fileName.endsWith('.pdf');

        if (!isPdf) {
            alert('Solo se permiten archivos PDF.');
            return;
        }

        const input = document.getElementById('pdf-file');
        const dt = new DataTransfer();
        dt.items.add(file);
        input.files = dt.files;

        $('#file-name-display').text(file.name);
        $('#file-status').removeClass('d-none');
        markAsChanged();
    }

    $('#flipbook-title, #page-texts, #voice-lang, #listen-enabled, #page-count').on('input change', markAsChanged);

    $('#drop-zone').on('click', function () {
        $('#pdf-file').trigger('click');
    });

    $('#drop-zone').on('dragover', function (e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).addClass('border-primary bg-white');
    });

    $('#drop-zone').on('dragleave', function (e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).removeClass('border-primary bg-white');
    });

    $('#drop-zone').on('drop', function (e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).removeClass('border-primary bg-white');

        const files = e.originalEvent.dataTransfer.files;
        if (files && files[0]) {
            setSelectedFile(files[0]);
        }
    });

    $('#pdf-file').on('change', function () {
        const file = this.files && this.files[0] ? this.files[0] : null;
        if (!file) return;
        setSelectedFile(file);
    });

    $('#btn-save-flipbook').on('click', function () {
        const btn = $(this);
        const title = $('#flipbook-title').val().trim();
        const selectedFile = $('#pdf-file')[0].files[0] || null;
        const pageCount = Math.max(1, parseInt($('#page-count').val(), 10) || 1);

        if (!title) {
            alert('Debes escribir un título.');
            return;
        }

        if (!existingPdfUrl && !selectedFile) {
            alert('Debes cargar un PDF.');
            return;
        }

        const pageTexts = $('#page-texts').val()
            .split(/\r?\n/)
            .map(line => line.trim());

        const formData = new FormData();
        formData.append('id', <?php echo json_encode($activityId); ?>);
        formData.append('unit', <?php echo json_encode($unit); ?>);
        formData.append('source', <?php echo json_encode($source); ?>);
        formData.append('assignment', <?php echo json_encode($assignment); ?>);
        formData.append('title', title);
        formData.append('listen_enabled', $('#listen-enabled').is(':checked') ? '1' : '0');
        formData.append('language', $('#voice-lang').val());
        formData.append('page_count', String(pageCount));
        formData.append('page_texts', JSON.stringify(pageTexts));

        if (selectedFile) {
            formData.append('pdf', selectedFile);
        }

        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-2"></i>Guardando...');

        $.ajax({
            url: 'save_flipbook.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function (res) {
                if (res && res.status === 'success') {
                    hasChanges = false;
                    $('#unsaved-msg').addClass('d-none');
                    alert(res.message || 'Actividad guardada correctamente.');
                    window.location.reload();
                } else {
                    alert((res && res.message) ? res.message : 'No fue posible guardar.');
                }
            },
            error: function (xhr) {
                let msg = 'Error al guardar la actividad.';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    msg = xhr.responseJSON.message;
                }
                alert(msg);
            },
            complete: function () {
                btn.prop('disabled', false).html('<i class="fas fa-save me-2"></i>Guardar actividad');
            }
        });
    });

    window.onbeforeunload = function () {
        if (hasChanges) {
            return 'Hay cambios sin guardar. ¿Deseas salir?';
        }
    };
});
</script>

<?php
$content = ob_get_clean();
render_activity_editor($payload['title'], 'fas fa-book-open', $content);
