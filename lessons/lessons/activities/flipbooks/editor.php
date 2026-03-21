<?php
require_once __DIR__ . '/../../config/db.php';

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
    'page_texts'     => isset($rawData['page_texts']) && is_array($rawData['page_texts']) ? $rawData['page_texts'] : [],
    'language'       => isset($rawData['language']) ? (string) $rawData['language'] : 'en-US',
];

$pageTitle = 'Editor: ' . htmlspecialchars($payload['title'], ENT_QUOTES, 'UTF-8');
include __DIR__ . '/../../core/_activity_editor_template.php';
?>

<div class="activity-editor-content flipbook-editor">
    <div class="editor-section mb-4">
        <div class="editor-section-header mb-3">
            <h3 class="mb-1">Configuración del Flipbook</h3>
            <p class="text-muted mb-0">Cargue el PDF, configure la lectura y escriba el texto que se leerá por página.</p>
        </div>

        <div class="card shadow-sm border-0 mb-4">
            <div class="card-body">
                <label for="flipbook-title" class="form-label fw-bold">Título de la actividad</label>
                <input
                    type="text"
                    id="flipbook-title"
                    class="form-control form-control-lg"
                    value="<?php echo htmlspecialchars($payload['title'], ENT_QUOTES, 'UTF-8'); ?>"
                    placeholder="Ej: Reading Practice - Unit 1"
                >
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-5">
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-body">
                        <h5 class="card-title mb-3">
                            <i class="fas fa-file-pdf me-2 text-danger"></i>Archivo PDF
                        </h5>

                        <div id="drop-zone" class="flipbook-dropzone">
                            <div class="flipbook-dropzone-inner">
                                <i class="fas fa-cloud-upload-alt flipbook-dropzone-icon"></i>
                                <div class="fw-semibold mb-1">Seleccione el archivo del flipbook</div>
                                <div class="text-muted small">Formato permitido: PDF</div>
                            </div>
                            <input type="file" id="pdf-file" accept="application/pdf" class="d-none">
                        </div>

                        <div id="file-status" class="flipbook-file-status mt-3 <?php echo $payload['pdf_url'] !== '' ? '' : 'd-none'; ?>">
                            <div class="small">
                                <i class="fas fa-check-circle text-success me-1"></i>
                                <strong>Archivo actual:</strong>
                                <span id="file-name-display"><?php echo htmlspecialchars(basename(parse_url($payload['pdf_url'], PHP_URL_PATH) ?? $payload['pdf_url']), ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>

                            <?php if ($payload['pdf_url'] !== ''): ?>
                                <div class="mt-2">
                                    <a href="<?php echo htmlspecialchars($payload['pdf_url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" class="btn btn-outline-secondary btn-sm">
                                        <i class="fas fa-eye me-1"></i>Ver PDF actual
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm border-0">
                    <div class="card-body">
                        <h5 class="card-title mb-3">
                            <i class="fas fa-volume-up me-2 text-primary"></i>Opciones de lectura
                        </h5>

                        <div class="form-check form-switch mb-3">
                            <input
                                class="form-check-input"
                                type="checkbox"
                                id="listen-enabled"
                                <?php echo $payload['listen_enabled'] ? 'checked' : ''; ?>
                            >
                            <label class="form-check-label" for="listen-enabled">
                                Habilitar lectura de voz
                            </label>
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
                            <i class="fas fa-align-left me-2 text-info"></i>Texto por página
                        </h5>
                        <p class="text-muted small mb-3">
                            Escriba una línea por cada página del PDF. Cada línea se guardará como un elemento del arreglo <code>page_texts</code>.
                        </p>

                        <textarea
                            id="page-texts"
                            class="form-control flipbook-page-texts"
                            rows="16"
                            placeholder="Texto página 1&#10;Texto página 2&#10;Texto página 3..."
                        ><?php echo htmlspecialchars(implode("\n", $payload['page_texts']), ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="editor-actions d-flex justify-content-between align-items-center flex-wrap gap-3 mt-4">
            <div>
                <span id="unsaved-msg" class="text-warning small d-none">
                    <i class="fas fa-exclamation-triangle me-1"></i>Tienes cambios sin guardar.
                </span>
            </div>

            <div class="d-flex gap-2">
                <button type="button" id="btn-save-flipbook" class="btn btn-primary btn-lg px-4">
                    <i class="fas fa-save me-2"></i>Guardar actividad
                </button>
            </div>
        </div>
    </div>
</div>

<script>
$(function () {
    let hasChanges = false;
    const existingPdfUrl = <?php echo json_encode($payload['pdf_url']); ?>;

    function markAsChanged() {
        hasChanges = true;
        $('#unsaved-msg').removeClass('d-none');
    }

    function clearChangedState() {
        hasChanges = false;
        $('#unsaved-msg').addClass('d-none');
        window.onbeforeunload = null;
    }

    function normalizePageTexts(text) {
        return text
            .split(/\r?\n/)
            .map(line => line.trim())
            .filter(line => line !== '');
    }

    $('#flipbook-title, #page-texts, #voice-lang, #listen-enabled').on('input change', function () {
        markAsChanged();
    });

    $('#drop-zone').on('click', function () {
        $('#pdf-file').trigger('click');
    });

    $('#pdf-file').on('change', function () {
        const file = this.files && this.files[0] ? this.files[0] : null;

        if (!file) {
            return;
        }

        $('#file-name-display').text(file.name);
        $('#file-status').removeClass('d-none');
        markAsChanged();
    });

    $('#btn-save-flipbook').on('click', function () {
        const btn = $(this);
        const title = $('#flipbook-title').val().trim();
        const pageTextsRaw = $('#page-texts').val();
        const pageTextsNormalized = normalizePageTexts(pageTextsRaw);
        const selectedFile = $('#pdf-file')[0].files[0] || null;

        if (title === '') {
            alert('Debes escribir un título para la actividad.');
            $('#flipbook-title').focus();
            return;
        }

        if (!existingPdfUrl && !selectedFile) {
            alert('Debes cargar un archivo PDF.');
            return;
        }

        const formData = new FormData();
        formData.append('id', <?php echo json_encode($activityId); ?>);
        formData.append('unit', <?php echo json_encode($unit); ?>);
        formData.append('source', <?php echo json_encode($source); ?>);
        formData.append('assignment', <?php echo json_encode($assignment); ?>);
        formData.append('title', title);
        formData.append('listen_enabled', $('#listen-enabled').is(':checked') ? '1' : '0');
        formData.append('language', $('#voice-lang').val());
        formData.append('page_texts', JSON.stringify(pageTextsNormalized));

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
            success: function (response) {
                let res = null;

                try {
                    res = typeof response === 'object' ? response : JSON.parse(response);
                } catch (e) {
                    alert('Error de respuesta del servidor.');
                    return;
                }

                if (res && res.status === 'success') {
                    clearChangedState();
                    alert(res.message || 'Flipbook guardado correctamente.');
                    window.location.reload();
                } else {
                    alert((res && res.message) ? res.message : 'No fue posible guardar el flipbook.');
                }
            },
            error: function () {
                alert('Ocurrió un error al guardar la actividad.');
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

<style>
.flipbook-editor .card {
    border-radius: 14px;
}

.flipbook-editor .editor-section-header h3 {
    font-weight: 700;
}

.flipbook-dropzone {
    border: 2px dashed #d0d7de;
    border-radius: 14px;
    padding: 28px 20px;
    background: #f8f9fb;
    cursor: pointer;
    transition: all 0.2s ease;
}

.flipbook-dropzone:hover {
    border-color: #86b7fe;
    background: #f3f7ff;
}

.flipbook-dropzone-inner {
    text-align: center;
}

.flipbook-dropzone-icon {
    font-size: 2.25rem;
    margin-bottom: 10px;
    color: #6c757d;
}

.flipbook-file-status {
    background: #f8fff9;
    border: 1px solid #d7f0dc;
    border-radius: 10px;
    padding: 12px 14px;
}

.flipbook-page-texts {
    min-height: 360px;
    resize: vertical;
    font-family: inherit;
}

.editor-actions {
    border-top: 1px solid #eef1f4;
    padding-top: 18px;
}
</style>

<?php
if (file_exists(__DIR__ . '/../../core/_activity_editor_footer.php')) {
    include __DIR__ . '/../../core/_activity_editor_footer.php';
}
?>
