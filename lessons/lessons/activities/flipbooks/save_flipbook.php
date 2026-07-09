<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/cloudinary_upload.php';

set_time_limit(300);
try {
    $pdo->exec("SET statement_timeout = 0");
} catch (Throwable $e) {
    // Non-fatal. Some managed Postgres connections do not allow this per request.
}

const FLIPBOOK_MAX_PDF_BYTES = 30 * 1024 * 1024;
const FLIPBOOK_DB_PDF_PREFIX = 'db-pdf://';

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

function upload_pdf_to_cloudinary_raw(string $filePath): ?string
{
    $cloudName = cloudinary_env('CLOUDINARY_CLOUD_NAME');
    $apiKey = cloudinary_env('CLOUDINARY_API_KEY');
    $apiSecret = cloudinary_env('CLOUDINARY_API_SECRET');

    if ($cloudName === '' || $apiKey === '' || $apiSecret === '') {
        return null;
    }

    $timestamp = time();
    $signature = sha1("timestamp={$timestamp}{$apiSecret}");
    $url = "https://api.cloudinary.com/v1_1/{$cloudName}/raw/upload";

    $post = [
        'file' => new CURLFile($filePath, 'application/pdf', basename($filePath)),
        'api_key' => $apiKey,
        'timestamp' => $timestamp,
        'signature' => $signature,
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 180);

    $result = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($result === false || $httpCode >= 400) {
        error_log(sprintf(
            'flipbook: Cloudinary raw PDF upload failed (http=%d, curl_error=%s, response=%s)',
            $httpCode,
            $curlError !== '' ? $curlError : 'none',
            substr((string) $result, 0, 500)
        ));
        return null;
    }

    $decoded = json_decode((string) $result, true);
    if (!is_array($decoded)) {
        return null;
    }

    return isset($decoded['secure_url']) ? (string) $decoded['secure_url'] : null;
}

function clear_pdf_in_db(PDO $pdo, string $activityId): void
{
    try {
        $stmt = $pdo->prepare("UPDATE activities SET pdf_data = NULL WHERE id = :id");
        $stmt->execute(['id' => $activityId]);
    } catch (Throwable $e) {
        // Non-fatal cleanup. Some environments do not have the legacy column.
    }
}

function store_pdf_locally(string $sourcePath, string $originalName): ?string
{
    $uploadDir = __DIR__ . '/uploads/pdfs';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        return null;
    }

    $base = pathinfo($originalName, PATHINFO_FILENAME);
    $safeBase = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $base);
    if ($safeBase === '' || $safeBase === null) {
        $safeBase = 'flipbook_pdf';
    }

    $fileName = $safeBase . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.pdf';
    $targetPath = $uploadDir . '/' . $fileName;

    if (!@copy($sourcePath, $targetPath)) {
        return null;
    }

    @chmod($targetPath, 0664);

    return '/lessons/lessons/activities/flipbooks/uploads/pdfs/' . $fileName;
}

function persist_pdf(PDO $pdo, string $activityId, string $sourcePath, string $originalName): ?string
{
    $cloudinaryUrl = upload_pdf_to_cloudinary_raw($sourcePath);
    if ($cloudinaryUrl !== null && $cloudinaryUrl !== '') {
        clear_pdf_in_db($pdo, $activityId);
        return $cloudinaryUrl;
    }

    // Important: do not store new PDFs in Postgres. Sending 20-30 MB PDF blobs
    // through pdo_pgsql (BYTEA/LOB/hex) is what caused Render Postgres to drop
    // the connection and surface "SQLSTATE[HY000] General error: 7 no connection
    // to the server" during Save Activity. Use the filesystem fallback when
    // Cloudinary is unavailable, and keep only the small URL/filename JSON in DB.
    $localUrl = store_pdf_locally($sourcePath, $originalName);
    if ($localUrl !== null && $localUrl !== '') {
        return $localUrl;
    }

    return null;
}

function migrate_base64_pdf_if_needed(PDO $pdo, string $activityId, string $pdfUrl): ?string
{
    $prefix = 'data:application/pdf;base64,';
    if (!str_starts_with($pdfUrl, $prefix)) {
        return $pdfUrl;
    }

    $base64 = substr($pdfUrl, strlen($prefix));
    if ($base64 === false || $base64 === '') {
        return null;
    }

    $binary = base64_decode($base64, true);
    if ($binary === false || $binary === '') {
        return null;
    }

    $tmpFile = tempnam(sys_get_temp_dir(), 'flipbook_pdf_');
    if ($tmpFile === false) {
        return null;
    }

    $bytesWritten = file_put_contents($tmpFile, $binary);
    unset($binary);
    if ($bytesWritten === false || $bytesWritten <= 0) {
        @unlink($tmpFile);
        return null;
    }

    $storedUrl = persist_pdf($pdo, $activityId, $tmpFile, 'flipbook_migrated.pdf');
    @unlink($tmpFile);

    return $storedUrl;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('Método no permitido.', 405);
}

try {
    $activityId    = isset($_POST['id']) ? trim((string) $_POST['id']) : '';
    $unit          = isset($_POST['unit']) ? trim((string) $_POST['unit']) : 'general';
    $title         = 'Downloadable';
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
    $pdfFilename = isset($currentData['pdf_filename']) ? (string) $currentData['pdf_filename'] : '';

    if ($pdfUrl !== '') {
        $migratedPdfUrl = migrate_base64_pdf_if_needed($pdo, $activityId, $pdfUrl);
        if ($migratedPdfUrl === null || $migratedPdfUrl === '') {
            respond_error('No se pudo procesar el PDF existente. Vuelve a subir el archivo.');
        }
        $pdfUrl = $migratedPdfUrl;
    }

    $pageTextsRaw = $_POST['page_texts'] ?? '[]';
    $decodedPageTexts = json_decode((string) $pageTextsRaw, true);

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

            if ($fileSize > FLIPBOOK_MAX_PDF_BYTES) {
                respond_error('El archivo PDF excede el límite permitido de 30 MB.');
            }

            $extension = strtolower(pathinfo((string) $originalName, PATHINFO_EXTENSION));
            if ($extension !== 'pdf') {
                respond_error('El archivo debe tener extensión .pdf.');
            }

            if (function_exists('mime_content_type')) {
                $mime = mime_content_type($tmpPath);
                if ($mime !== 'application/pdf' && $mime !== 'application/octet-stream') {
                    respond_error('El archivo no es un PDF válido.');
                }
            }

            $storedPdfUrl = persist_pdf($pdo, $activityId, $tmpPath, (string) $originalName);
            if ($storedPdfUrl === null || $storedPdfUrl === '') {
                respond_error('No se pudo almacenar el PDF. Verifica Cloudinary o permisos de la carpeta uploads/pdfs.');
            }

            $pdfUrl = $storedPdfUrl;
            $pdfFilename = (string) $originalName;
        }
    }

    if ($pdfUrl === '') {
        respond_error('Debes cargar un archivo PDF.');
    }

    $payload = is_array($currentData) ? $currentData : [];
    $payload['type'] = 'flipbook';
    $payload['title'] = $title;
    $payload['pdf_url'] = $pdfUrl;
    $payload['pdf_filename'] = $pdfFilename;
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
