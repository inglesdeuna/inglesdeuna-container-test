<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/_activity_editor_template.php';

// Block student access to editor
if (isset($_SESSION['student_logged']) && $_SESSION['student_logged']) {
    header('Location: /lessons/lessons/academic/student_dashboard.php?error=access_denied');
    exit;
}

// Accept admin OR teacher session
$isLoggedIn = !empty($_SESSION['academic_logged']) || !empty($_SESSION['admin_logged']);
if (!$isLoggedIn) {
    header('Location: /lessons/lessons/academic/login.php');
    exit;
}

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$unit       = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';
$source     = isset($_GET['source']) ? trim((string) $_GET['source']) : '';
$assignment = isset($_GET['assignment']) ? trim((string) $_GET['assignment']) : '';

if ($activityId === '') {
    die('Activity ID not specified.');
}

$stmt = $pdo->prepare("SELECT * FROM activities WHERE id = :id LIMIT 1");
$stmt->execute(['id' => $activityId]);
$activity = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$activity) {
    die('Activity not found.');
}

$rawData = json_decode($activity['data'] ?? '', true);
if (!is_array($rawData)) {
    $rawData = [];
}

$payload = [
    'title'          => isset($rawData['title']) ? (string) $rawData['title'] : 'Downloadable',
    'pdf_url'        => isset($rawData['pdf_url']) ? (string) $rawData['pdf_url'] : '',
];

$currentFileName = '';
if ($payload['pdf_url'] !== '') {
    $path = parse_url($payload['pdf_url'], PHP_URL_PATH);
    $currentFileName = basename($path ?: $payload['pdf_url']);
}

ob_start();
?>

<div class="alert alert-info mb-4">
    <strong>Tipo de actividad:</strong> Downloadable
</div>

<div class="row g-4">
    <div class="col-lg-12">
        <div class="card shadow-sm border-0">
            <div class="card-body">
                <h5 class="card-title mb-3">
                    <i class="fas fa-file-pdf text-danger me-2"></i>Archivo PDF
                </h5>

                <div id="drop-zone" class="flipbook-dropzone">
                    <div class="flipbook-dropzone__inner">
                        <i class="fas fa-cloud-upload-alt fa-2x text-secondary mb-2"></i>
                        <div class="fw-semibold">Select or drag the PDF file here</div>
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
                    <strong>Current file:</strong>
                    <span id="file-name-display"><?php echo htmlspecialchars($currentFileName, ENT_QUOTES, 'UTF-8'); ?></span>

                    <?php if ($payload['pdf_url'] !== ''): ?>
                        <div class="mt-2">
                            <a
                                href="<?php echo htmlspecialchars($payload['pdf_url'], ENT_QUOTES, 'UTF-8'); ?>"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="btn btn-outline-secondary btn-sm"
                            >
                                View Current PDF
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="d-flex justify-content-between align-items-center mt-4 flex-wrap gap-3">
    <span id="unsaved-msg" class="text-warning small d-none">
        <i class="fas fa-exclamation-triangle me-1"></i>You have unsaved changes.
    </span>

    <button type="button" id="btn-save-flipbook" class="btn btn-primary btn-lg">
        <i class="fas fa-save me-2"></i>Save Activity
    </button>
</div>

<style>
.flipbook-dropzone {
    position: relative;
    border: 2px dashed #cbd5e1;
    border-radius: 12px;
    padding: 24px;
    text-align: center;
    background: #f8fafc;
    cursor: pointer;
    transition: all 0.2s ease;
}

.flipbook-dropzone:hover {
    border-color: #60a5fa;
    background: #eff6ff;
}

.flipbook-dropzone.is-dragover {
    border-color: #2563eb;
    background: #dbeafe;
}

.flipbook-dropzone__inner {
    pointer-events: none;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const dropZone = document.getElementById('drop-zone');
    const fileInput = document.getElementById('pdf-file');
    const fileStatus = document.getElementById('file-status');
    const fileNameDisplay = document.getElementById('file-name-display');
    const saveBtn = document.getElementById('btn-save-flipbook');
    const unsavedMsg = document.getElementById('unsaved-msg');

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
            return { ok: false, message: 'No file was selected.' };
        }

        const fileName = file.name.toLowerCase();
        const mimeType = (file.type || '').toLowerCase();
        const isPdfByName = fileName.endsWith('.pdf');
        const isPdfByMime = mimeType === 'application/pdf' || mimeType === '';

        if (!isPdfByName && !isPdfByMime) {
            return { ok: false, message: 'Only PDF files are allowed.' };
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

    saveBtn.addEventListener('click', function () {
        const selectedFile = fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;

        if (!existingPdfUrl && !selectedFile) {
            alert('You must upload a PDF.');
            return;
        }

        if (selectedFile) {
            const validation = validatePdfFile(selectedFile);
            if (!validation.ok) {
                alert(validation.message);
                return;
            }
        }

        const formData = new FormData();
        formData.append('id', <?php echo json_encode($activityId); ?>);
        formData.append('unit', <?php echo json_encode($unit); ?>);
        formData.append('source', <?php echo json_encode($source); ?>);
        formData.append('assignment', <?php echo json_encode($assignment); ?>);
        formData.append('title', 'Downloadable');
        formData.append('listen_enabled', '0');
        formData.append('language', 'en-US');
        formData.append('page_count', '1');
        formData.append('page_texts', JSON.stringify([]));

        if (selectedFile) {
            formData.append('pdf', selectedFile);
        }

        saveBtn.disabled = true;
        saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Saving...';

        fetch('save_flipbook.php', {
            method: 'POST',
            body: formData
        })
        .then(async function (response) {
            const raw = await response.text();

            let res;
            try {
                res = JSON.parse(raw);
            } catch (e) {
                console.error('Respuesta cruda del servidor:', raw);
                throw new Error('Invalid server response. Check save_flipbook.php');
            }

            return res;
        })
        .then(function (res) {
            if (res.status === 'success') {
                hasChanges = false;
                unsavedMsg.classList.add('d-none');
                alert(res.message || 'Activity saved successfully.');
                window.location.reload();
            } else {
                alert(res.message || 'The activity could not be saved.');
            }
        })
        .catch(function (error) {
            console.error(error);
            alert(error.message || 'Error while saving the activity.');
        })
        .finally(function () {
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i class="fas fa-save me-2"></i>Save Activity';
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
render_activity_editor('Downloadable', 'fas fa-file-pdf', $content);
