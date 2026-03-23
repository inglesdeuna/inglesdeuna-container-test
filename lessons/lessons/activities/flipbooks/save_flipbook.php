<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json; charset=utf-8');

function respond_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function respond_error(string $message, int $statusCode = 400): void
{
    respond_json([
        'status' => 'error',
        'message' => $message
    ], $statusCode);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('Método no permitido.', 405);
}

try {
    $activityId    = isset($_POST['id']) ? trim((string) $_POST['id']) : '';
    $unit          = isset($_POST['unit']) ? trim((string) $_POST['unit']) : 'general';
    $title         = isset($_POST['title']) ? trim((string) $_POST['title']) : 'Downloadable';
    $language      = isset($_POST['language']) ? trim((string) $_POST['language']) : 'en-US';
    $listenEnabled = isset($_POST['listen_enabled']) && $_POST['listen_enabled'] === '1';
    $pageCount     = isset($_POST['page_count']) ? (int) $_POST['page_count'] : 1;

    if ($activityId === '') {
        respond_error('ID de actividad faltante.');
    }

    if ($pageCount < 1) {
        $pageCount = 1;
    }

    $stmt = $pdo->prepare("SELECT id, data FROM activities WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $activityId]);
    $activity = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$activity) {
        respond_error('Actividad no encontrada.', 404);
    }

    $currentData = json_decode($activity['data'] ?? '', true);
    if (!is_array($currentData)) {
        $currentData = [];
    }

    $pdfUrl = isset($currentData['pdf_url']) ? (string) $currentData['pdf_url'] : '';

    $pageTextsRaw = $_POST['page_texts'] ?? '[]';
    $decodedPageTexts = json_decode($pageTextsRaw, true);

    if (!is_array($decodedPageTexts)) {
        $decodedPageTexts = [];
    }

    $pageTexts = [];
    for ($i = 0; $i < $pageCount; $i++) {
        $pageTexts[] = isset($decodedPageTexts[$i]) ? trim((string) $decodedPageTexts[$i]) : '';
    }

    if (isset($_FILES['pdf']) && is_array($_FILES['pdf'])) {
        $uploadError = $_FILES['pdf']['error'] ?? UPLOAD_ERR_NO_FILE;

        if ($uploadError !== UPLOAD_ERR_NO_FILE) {
            if ($uploadError !== UPLOAD_ERR_OK) {
                $uploadErrors = [
                    UPLOAD_ERR_INI_SIZE   => 'El PDF excede el tamaño máximo permitido por el servidor.',
                    UPLOAD_ERR_FORM_SIZE  => 'El PDF excede el tamaño máximo permitido por el formulario.',
                    UPLOAD_ERR_PARTIAL    => 'El PDF se cargó parcialmente.',
                    UPLOAD_ERR_NO_TMP_DIR => 'No existe carpeta temporal en el servidor.',
                    UPLOAD_ERR_CANT_WRITE => 'El servidor no pudo escribir el archivo.',
                    UPLOAD_ERR_EXTENSION  => 'La subida del PDF fue detenida por una extensión del servidor.'
                ];

                respond_error($uploadErrors[$uploadError] ?? 'Error desconocido al subir el PDF.');
            }

            $tmpPath = $_FILES['pdf']['tmp_name'] ?? '';
            $originalName = $_FILES['pdf']['name'] ?? 'archivo.pdf';
            $fileSize = (int) ($_FILES['pdf']['size'] ?? 0);

            if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
                respond_error('No se recibió un archivo válido.');
            }

            if ($fileSize <= 0) {
                respond_error('El archivo PDF está vacío.');
            }

            if ($fileSize > 25 * 1024 * 1024) {
                respond_error('El archivo PDF excede el límite permitido de 25 MB.');
            }

            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            if ($extension !== 'pdf') {
                respond_error('El archivo debe tener extensión .pdf.');
            }

            if (function_exists('mime_content_type')) {
                $mime = mime_content_type($tmpPath);
                if ($mime !== 'application/pdf' && $mime !== 'application/octet-stream') {
                    respond_error('El archivo no es un PDF válido.');
                }
            }

            $safeUnit = preg_replace('/[^a-zA-Z0-9_-]/', '_', $unit);
            if ($safeUnit === '') {
                $safeUnit = 'general';
            }

            $uploadDir = __DIR__ . '/uploads/pdfs/';

            if (!is_dir($uploadDir)) {
                if (!mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                    respond_error('No se pudo crear la carpeta de uploads.');
                }
            }

            if (!is_writable($uploadDir)) {
                respond_error('La carpeta de uploads no tiene permisos de escritura.');
            }

            $newFileName = 'unit_' . $safeUnit . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.pdf';
            $destination = $uploadDir . $newFileName;

            if (!move_uploaded_file($tmpPath, $destination)) {
                respond_error('No se pudo guardar el archivo PDF.');
            }

            $pdfUrl = '/lessons/lessons/activities/flipbooks/uploads/pdfs/' . $newFileName;
        }
    }

    if ($pdfUrl === '') {
        respond_error('Debes cargar un archivo PDF.');
    }

    $payload = is_array($currentData) ? $currentData : [];
    $payload['type'] = 'flipbook';
    $payload['title'] = $title;
    $payload['pdf_url'] = $pdfUrl;
    $payload['listen_enabled'] = $listenEnabled;
    $payload['language'] = $language;
    $payload['page_count'] = $pageCount;
    $payload['page_texts'] = $pageTexts;
    $payload['updated_at'] = date('Y-m-d H:i:s');

    $jsonData = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($jsonData === false) {
        respond_error('No se pudo serializar la actividad.');
    }

    $updateStmt = $pdo->prepare("UPDATE activities SET data = :data WHERE id = :id");
    $ok = $updateStmt->execute([
        'data' => $jsonData,
        'id'   => $activityId
    ]);

    if (!$ok) {
        respond_error('No se pudo actualizar la actividad.');
    }

    respond_json([
        'status'  => 'success',
        'message' => 'Actividad guardada correctamente.',
        'data'    => $payload
    ]);
} catch (Throwable $e) {
    respond_error('Error interno del servidor: ' . $e->getMessage(), 500);
}
