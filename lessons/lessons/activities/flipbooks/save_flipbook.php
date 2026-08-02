<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/init_db.php';
require_once __DIR__ . '/../../core/cloudinary_upload.php';

set_time_limit(300);
try {
    $pdo->exec("SET statement_timeout = 0");
} catch (Throwable $e) {
    // Non-fatal. Some managed Postgres connections do not allow this per request.
}

const FLIPBOOK_MAX_PDF_BYTES = 30 * 1024 * 1024;
const FLIPBOOK_DB_PDF_PREFIX = 'db-pdf://';
// Blobs at or below this size are safe to stream into the pdf_data BYTEA
// column. The earlier "SQLSTATE[HY000] ... no connection to the server"
// crash was seen when a whole 20-30 MB blob was bound as a single string
// parameter. store_pdf_in_db() streams the file via PDO::PARAM_LOB instead,
// which avoids that crash regardless of size, so this threshold is set to
// the same value as FLIPBOOK_MAX_PDF_BYTES: every PDF accepted by the upload
// form should be persisted to the database rather than silently falling
// back to Render's ephemeral local disk (uploads/pdfs), which is wiped on
// every redeploy/restart and was the root cause of previously-working PDFs
// turning into "Archivo PDF no encontrado" after some time.
const FLIPBOOK_DB_SAFE_MAX_BYTES = FLIPBOOK_MAX_PDF_BYTES;

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

function upload_pdf_to_cloudinary_raw(string $filePath, string $activityId): ?string
{
    $GLOBALS['flipbook_cloudinary_error'] = '';

    $cloudName = cloudinary_env('CLOUDINARY_CLOUD_NAME');
    $apiKey = cloudinary_env('CLOUDINARY_API_KEY');
    $apiSecret = cloudinary_env('CLOUDINARY_API_SECRET');

    if ($cloudName === '' || $apiKey === '' || $apiSecret === '') {
        $GLOBALS['flipbook_cloudinary_error'] = 'faltan las credenciales de Cloudinary';
        return null;
    }

    if (!function_exists('curl_init') || !class_exists('CURLFile')) {
        $GLOBALS['flipbook_cloudinary_error'] = 'la extensión cURL de PHP no está disponible';
        return null;
    }

    $timestamp = time();
    $safeActivityId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $activityId);
    $publicId = 'flipbook_' . ($safeActivityId !== '' ? $safeActivityId : 'activity')
        . '_' . $timestamp . '_' . bin2hex(random_bytes(8)) . '.pdf';

    // Cloudinary signatures must contain every signed upload parameter in
    // alphabetical order. The previous code sent public_id but signed only
    // timestamp, so Cloudinary correctly rejected every request as invalid.
    // Raw assets must also keep their extension in public_id.
    $signaturePayload = 'public_id=' . $publicId . '&timestamp=' . $timestamp;
    $signature = sha1($signaturePayload . $apiSecret);
    $url = "https://api.cloudinary.com/v1_1/{$cloudName}/raw/upload";

    $post = [
        'file' => new CURLFile($filePath, 'application/pdf', basename($filePath)),
        'api_key' => $apiKey,
        'public_id' => $publicId,
        'timestamp' => $timestamp,
        'signature' => $signature,
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
    curl_setopt($ch, CURLOPT_TIMEOUT, 240);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Expect:']);

    $result = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode((string) $result, true);
    $cloudinaryMessage = is_array($decoded)
        ? trim((string) ($decoded['error']['message'] ?? ''))
        : '';

    if ($result === false || $httpCode < 200 || $httpCode >= 300) {
        $GLOBALS['flipbook_cloudinary_error'] = $cloudinaryMessage !== ''
            ? $cloudinaryMessage
            : ($curlError !== '' ? $curlError : 'respuesta HTTP ' . $httpCode);

        error_log(sprintf(
            'flipbook: Cloudinary raw PDF upload failed (http=%d, curl_error=%s, response=%s)',
            $httpCode,
            $curlError !== '' ? $curlError : 'none',
            substr((string) $result, 0, 500)
        ));
        return null;
    }

    $secureUrl = is_array($decoded) ? trim((string) ($decoded['secure_url'] ?? '')) : '';
    $resourceType = is_array($decoded) ? trim((string) ($decoded['resource_type'] ?? '')) : '';
    $uploadedBytes = is_array($decoded) ? (int) ($decoded['bytes'] ?? 0) : 0;

    if ($secureUrl === '' || $resourceType !== 'raw' || $uploadedBytes <= 0) {
        $GLOBALS['flipbook_cloudinary_error'] = 'Cloudinary devolvió una respuesta incompleta para el PDF';
        error_log('flipbook: invalid Cloudinary raw upload response: ' . substr((string) $result, 0, 500));
        return null;
    }

    return $secureUrl;
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

function store_pdf_in_db(PDO $pdo, string $activityId, string $sourcePath): ?string
{
    $stream = @fopen($sourcePath, 'rb');
    if ($stream === false) {
        return null;
    }

    $ok = false;
    try {
        $stmt = $pdo->prepare("UPDATE activities SET pdf_data = :data WHERE id = :id");
        $stmt->bindParam(':data', $stream, PDO::PARAM_LOB);
        $stmt->bindValue(':id', $activityId);
        $ok = $stmt->execute();
    } catch (Throwable $e) {
        error_log('flipbook: failed to store PDF in pdf_data column: ' . $e->getMessage());
    } finally {
        if (is_resource($stream)) {
            fclose($stream);
        }
    }

    // PDO_PGSQL does not handle stream-bound BYTEA parameters consistently
    // across driver versions. Retry with the binary contents when that path
    // fails; the upload limit keeps this bounded to a reasonable size.
    if (!$ok) {
        $contents = @file_get_contents($sourcePath);
        if ($contents !== false && $contents !== '') {
            try {
                $stmt = $pdo->prepare("UPDATE activities SET pdf_data = :data WHERE id = :id");
                $stmt->bindValue(':data', $contents, PDO::PARAM_LOB);
                $stmt->bindValue(':id', $activityId);
                $ok = $stmt->execute();
            } catch (Throwable $e) {
                error_log('flipbook: binary PDF fallback failed: ' . $e->getMessage());
            }
        }
    }

    return $ok ? (FLIPBOOK_DB_PDF_PREFIX . $activityId) : null;
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
    $cloudinaryUrl = upload_pdf_to_cloudinary_raw($sourcePath, $activityId);
    if ($cloudinaryUrl !== null && $cloudinaryUrl !== '') {
        clear_pdf_in_db($pdo, $activityId);
        return $cloudinaryUrl;
    }

    // Cloudinary is unavailable: prefer the persistent pdf_data BYTEA column
    // over the local filesystem. Render's disk is ephemeral, so any PDF saved
    // only to uploads/pdfs is lost the next time the container restarts or
    // redeploys, which is what made recently uploaded worksheets stop working
    // ("El archivo no estaba disponible en el sitio") while older ones (already
    // synced to Cloudinary) kept working. Streaming via PDO::PARAM_LOB avoids
    // the earlier "SQLSTATE[HY000] ... no connection to the server" crash that
    // came from binding whole 20-30 MB blobs as a single string parameter, but
    // stay conservative and only use it for files at/under a safe size.
    $fileSize = @filesize($sourcePath);
    if ($fileSize !== false && $fileSize <= FLIPBOOK_DB_SAFE_MAX_BYTES) {
        $dbUrl = store_pdf_in_db($pdo, $activityId, $sourcePath);
        if ($dbUrl !== null && $dbUrl !== '') {
            return $dbUrl;
        }
    }

    // Never report success for a file that only exists on Render's ephemeral
    // filesystem. Legacy local URLs remain readable for recovery, but new
    // uploads must be durable or the administrator must retry the upload.
    error_log(sprintf(
        'flipbook: durable PDF storage failed (activity_id=%s, bytes=%s)',
        $activityId,
        (string) (@filesize($sourcePath) ?: 0)
    ));
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

/**
 * Legacy rows can point at a PDF that was saved to Render's ephemeral local
 * disk (uploads/pdfs), either because Cloudinary was unavailable at the time
 * or the file was too large for the old, lower DB-safe threshold. Every time
 * the activity is saved (even without a new upload) we opportunistically
 * migrate that file into persistent storage (Cloudinary or the pdf_data DB
 * column) while it still exists on disk, so it survives future redeploys.
 * If the file is already gone (disk wiped by a previous restart), the URL is
 * left untouched and serve_pdf.php will report it as missing.
 */
function migrate_local_pdf_if_needed(PDO $pdo, string $activityId, string $pdfUrl): string
{
    $uploadDir = realpath(__DIR__ . '/uploads/pdfs');
    if ($uploadDir === false) {
        return $pdfUrl;
    }

    $path = (string) parse_url($pdfUrl, PHP_URL_PATH);
    if ($path === '') {
        return $pdfUrl;
    }

    $baseName = basename($path);
    if ($baseName === '' || $baseName === '.' || $baseName === '..') {
        return $pdfUrl;
    }

    $candidate = realpath($uploadDir . '/' . $baseName);
    if ($candidate === false || !is_file($candidate)) {
        return $pdfUrl;
    }

    if (!str_starts_with($candidate, $uploadDir . DIRECTORY_SEPARATOR)) {
        return $pdfUrl;
    }

    $migratedUrl = persist_pdf($pdo, $activityId, $candidate, $baseName);
    if ($migratedUrl === null || $migratedUrl === '') {
        return $pdfUrl;
    }

    @unlink($candidate);

    return $migratedUrl;
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

    $hasReplacementUpload = isset($_FILES['pdf'])
        && is_array($_FILES['pdf'])
        && (int) ($_FILES['pdf']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;

    // A replacement upload is independent of the old storage. Do not let a
    // stale local/base64 PDF migration prevent the new file from being saved.
    if ($pdfUrl !== '' && !$hasReplacementUpload) {
        $migratedPdfUrl = migrate_base64_pdf_if_needed($pdo, $activityId, $pdfUrl);
        if ($migratedPdfUrl === null || $migratedPdfUrl === '') {
            respond_error('No se pudo procesar el PDF existente. Vuelve a subir el archivo.');
        }
        $pdfUrl = migrate_local_pdf_if_needed($pdo, $activityId, $migratedPdfUrl);
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
                $cloudinaryReason = trim((string) ($GLOBALS['flipbook_cloudinary_error'] ?? ''));
                $detail = $cloudinaryReason !== ''
                    ? ' Cloudinary respondió: ' . $cloudinaryReason . '.'
                    : '';
                respond_error(
                    'No se pudo almacenar el PDF de forma permanente.' . $detail
                    . ' El archivo anterior no fue modificado.',
                    502
                );
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
