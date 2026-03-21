<?php
require_once __DIR__ . '/../../config/db.php';

// 1. Captura de parámetros y contextos
$activityId = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
$unit = isset($_GET['unit']) ? trim((string)$_GET['unit']) : '';
$source = isset($_GET['source']) ? trim((string)$_GET['source']) : '';
$assignment = isset($_GET['assignment']) ? trim((string)$_GET['assignment']) : '';

if ($activityId === '') {
    die('ID de actividad no especificado');
}

// 2. Cargar datos de la actividad (Estandarizado a columna 'data')
$stmt = $pdo->prepare("SELECT * FROM activities WHERE id = :id LIMIT 1");
$stmt->execute(['id' => $activityId]);
$activity = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$activity) {
    die('Actividad no encontrada');
}

// Decodificación segura del JSON siguiendo tu lógica de normalización
$rawData = json_decode($activity['data'] ?? '', true) ?: [];
$payload = [
    'title'          => $rawData['title'] ?? 'Flipbook',
    'pdf_url'        => $rawData['pdf_url'] ?? '',
    'listen_enabled' => $rawData['listen_enabled'] ?? true,
    'page_texts'     => $rawData['page_texts'] ?? [],
    'language'       => $rawData['language'] ?? 'en-US'
];

// 3. Configuración del Template Base
// El template _activity_editor_template.php maneja el header, los botones de volver y el layout tipo Pronunciation
$pageTitle = "Editor: " . htmlspecialchars($payload['title']);
include __DIR__ . '/../../core/_activity_editor_template.php';
?>

<!-- Contenido específico del Editor de Flipbook -->
<div class="activity-editor-content">
    
    <!-- Título de la Actividad -->
    <div class="form-group mb-4">
        <label class="form-label fw-bold">Título del Flipbook</label>
        <input type="text" id="flipbook-title" class="form-control form-control-lg" 
               value="<?php echo htmlspecialchars($payload['title']); ?>" placeholder="Ej: Reading Practice - Unit 1">
    </div>

    <div class="row">
        <!-- Columna Izquierda: Configuración y PDF -->
        <div class="col-md-6">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-body">
                    <h5 class="card-title"><i class="fas fa-file-pdf text-danger"></i> Archivo del Libro</h5>
                    <hr>
                    
                    <div class="upload-zone p-4 text-center border rounded-3 bg-light" id="drop-zone" style="cursor:pointer; border: 2px dashed #00d4ff !important;">
                        <i class="fas fa-cloud-upload-alt fa-3x mb-2" style="color: #00d4ff;"></i>
                        <p class="mb-0">Haga clic o arrastre el PDF aquí</p>
                        <input type="file" id="pdf-file" accept="application/pdf" class="d-none">
                    </div>

                    <div id="file-status" class="mt-3 p-2 rounded <?php echo $payload['pdf_url'] ? 'bg-success-light' : 'd-none'; ?>">
                        <small>
                            <i class="fas fa-check-circle text-success"></i> 
                            Archivo actual: <span id="file-name-display"><?php echo basename($payload['pdf_url']); ?></span>
                        </small>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <h5 class="card-title"><i class="fas fa-volume-up text-primary"></i> Configuración de Audio (Listen)</h5>
                    <hr>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="listen-enabled" <?php echo $payload['listen_enabled'] ? 'checked' : ''; ?>>
                        <label class="form-check-label">Habilitar lectura de voz automática</label>
                    </div>
                    <label class="small fw-bold">Idioma de lectura:</label>
                    <select id="voice-lang" class="form-select form-select-sm">
                        <option value="en-US" <?php echo $payload['language'] == 'en-US' ? 'selected' : ''; ?>>Inglés (US)</option>
                        <option value="en-GB" <?php echo $payload['language'] == 'en-GB' ? 'selected' : ''; ?>>Inglés (UK)</option>
                        <option value="es-ES" <?php echo $payload['language'] == 'es-ES' ? 'selected' : ''; ?>>Español</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Columna Derecha: Textos por Página -->
        <div class="col-md-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body">
                    <h5 class="card-title"><i class="fas fa-align-left text-info"></i> Contenido para "Listen"</h5>
                    <p class="text-muted small">Escriba una línea de texto por cada página del PDF para que el sistema pueda leerla.</p>
                    <textarea id="page-texts" class="form-control" rows="12" style="font-family: monospace; font-size: 0.9em;" 
                              placeholder="Texto página 1&#10;Texto página 2&#10;Texto página 3..."><?php echo implode("\n", $payload['page_texts']); ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <!-- Botón de Guardado Flotante o en el Footer del Template -->
    <div class="text-end mt-4">
        <p class="text-danger small d-none" id="unsaved-msg"><i class="fas fa-exclamation-triangle"></i> Tienes cambios sin guardar.</p>
        <button id="btn-save-flipbook" class="btn btn-primary btn-lg px-5 shadow">
            <i class="fas fa-save"></i> GUARDAR ACTIVIDAD
        </button>
    </div>
</div>

<script>
$(document).ready(function() {
    let hasChanges = false;

    // Trigger de cambios
    $('input, textarea, select').on('change input', () => {
        hasChanges = true;
        $('#unsaved-msg').removeClass('d-none');
    });

    // Zona de carga
    $('#drop-zone').click(() => $('#pdf-file').click());

    $('#pdf-file').change(function() {
        if(this.files.length > 0) {
            $('#file-name-display').text(this.files[0].name);
            $('#file-status').removeClass('d-none');
            hasChanges = true;
        }
    });

    // Acción de Guardado
    $('#btn-save-flipbook').click(function() {
        const btn = $(this);
        const formData = new FormData();
        
        formData.append('id', '<?php echo $activityId; ?>');
        formData.append('unit', '<?php echo $unit; ?>');
        formData.append('title', $('#flipbook-title').val());
        formData.append('listen_enabled', $('#listen-enabled').is(':checked') ? 1 : 0);
        formData.append('language', $('#voice-lang').val());
        formData.append('page_texts', $('#page-texts').val());
        
        if($('#pdf-file')[0].files[0]) {
            formData.append('pdf', $('#pdf-file')[0].files[0]);
        }

        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Guardando...');

        $.ajax({
            url: 'save_flipbook.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                try {
                    const res = JSON.parse(response);
                    if(res.status === 'success') {
                        hasChanges = false;
                        alert("¡Flipbook guardado correctamente!");
                        location.reload();
                    } else {
                        alert("Error: " + res.message);
                    }
                } catch(e) { alert("Error de respuesta del servidor."); }
            },
            complete: () => btn.prop('disabled', false).html('<i class="fas fa-save"></i> GUARDAR ACTIVIDAD')
        });
    });

    // Warning al salir
    window.onbeforeunload = function() {
        if (hasChanges) return "Hay cambios sin guardar. ¿Deseas salir?";
    };
});
</script>

<style>
    .bg-success-light { background-color: #e8f5e9; border: 1px solid #c8e6c9; }
    .activity-editor-content { padding: 20px; }
    .card { border-radius: 12px; }
</style>

<?php
// Cerramos con el footer del template (si existe en tu arquitectura)
if (file_exists(__DIR__ . '/../../core/_activity_editor_footer.php')) {
    include __DIR__ . '/../../core/_activity_editor_footer.php';
}
?>
