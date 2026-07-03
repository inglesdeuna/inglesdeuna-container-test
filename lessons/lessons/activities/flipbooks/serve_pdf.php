<?php
declare(strict_types=1);

/**
 * serve_pdf.php
 * Serves a PDF stored as base64 in the activities.data column.
 * This is necessary because Render's filesystem is ephemeral —
 * files uploaded at runtime are lost on container restart.
 */

require_once __DIR__ . '/../../config/db.php';

function safe_pdf_filename(string $pdfUrl): string
{
    $path = (string) parse_url($pdfUrl, PHP_URL_PATH);
    $name = basename($path !== '' ? $path : $pdfUrl);
    $name = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $name);

    if (!is_string($name) || $name === '' || $name === '.' || $name === '..') {
        return 'document.pdf';
    }

    if (!str_ends_with(strtolower($name), '.pdf')) {
        $name .= '.pdf';
    }

    return $name;
}

function resolve_local_pdf_path(string $pdfUrl): ?string
{
    $parsedPath = (string) parse_url($pdfUrl, PHP_URL_PATH);
    $rawPath = $parsedPath !== '' ? $parsedPath : $pdfUrl;
    $trimmedPath = ltrim($rawPath, '/');
    $baseName = basename($trimmedPath);

    $allowedBases = array_filter([
        realpath(__DIR__ . '/uploads/pdfs') ?: null,
        realpath(__DIR__ . '/../../admin/uploads') ?: null,
    ]);

    $candidatePaths = [];

    if ($trimmedPath !== '') {
        $candidatePaths[] = __DIR__ . '/../../../../' . $trimmedPath;
        $candidatePaths[] = __DIR__ . '/' . $trimmedPath;
        $candidatePaths[] = __DIR__ . '/../../admin/' . $trimmedPath;
    }

    if ($baseName !== '' && $baseName !== '.' && $baseName !== '..') {
        $candidatePaths[] = __DIR__ . '/uploads/pdfs/' . $baseName;
        $candidatePaths[] = __DIR__ . '/../../admin/uploads/' . $baseName;
    }

    foreach (array_unique($candidatePaths) as $candidatePath) {
        $realPath = realpath($candidatePath);
        if ($realPath === false || !is_file($realPath)) {
            continue;
        }

        foreach ($allowedBases as $allowedBase) {
            if ($realPath === $allowedBase || str_starts_with($realPath, $allowedBase . DIRECTORY_SEPARATOR)) {
                return $realPath;
            }
        }
    }

    return null;
}

$activityId    = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$forceDownload = isset($_GET['dl']) && $_GET['dl'] === '1';

if ($activityId === '') {
    http_response_code(400);
    exit('ID de actividad requerido.');
}

try {
    $stmt = $pdo->prepare("SELECT data FROM activities WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $activityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    http_response_code(500);
    exit('Error al consultar la base de datos.');
}

if (!$row) {
    http_response_code(404);
    exit('Actividad no encontrada.');
}

$data = json_decode($row['data'] ?? '', true);
$pdfUrl = isset($data['pdf_url']) ? (string) $data['pdf_url'] : '';
$downloadName = safe_pdf_filename($pdfUrl);

if ($pdfUrl === '') {
    http_response_code(404);
    exit('No hay PDF guardado para esta actividad.');
}

// Handle base64 data URI (new storage method)
if (str_starts_with($pdfUrl, 'data:application/pdf;base64,')) {
    $base64 = substr($pdfUrl, strlen('data:application/pdf;base64,'));
    $binary = base64_decode($base64, true);

    if ($binary === false) {
        http_response_code(500);
        exit('Error al decodificar el PDF.');
    }

    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($binary));
    header('Content-Disposition: ' . ($forceDownload ? 'attachment' : 'inline') . '; filename="' . $downloadName . '"');
    header('Cache-Control: private, max-age=3600');
    echo $binary;
    exit;
}

// Handle local/legacy uploads before proxying remote URLs.
$localPdfPath = resolve_local_pdf_path($pdfUrl);
if ($localPdfPath !== null) {
    header('Content-Type: application/pdf');
    header('Content-Length: ' . filesize($localPdfPath));
    header('Content-Disposition: ' . ($forceDownload ? 'attachment' : 'inline') . '; filename="' . $downloadName . '"');
    header('Cache-Control: private, max-age=3600');
    readfile($localPdfPath);
    exit;
}

// Handle remote URL storage (Cloudinary/raw) through the proxy.
if (preg_match('/^https?:\/\//i', $pdfUrl)) {
    $proxyUrl = '/lessons/lessons/activities/flipbooks/pdf_proxy.php?url=' . rawurlencode($pdfUrl)
        . ($forceDownload ? '&dl=1' : '');
    header('Location: ' . $proxyUrl, true, 302);
    exit;
}

http_response_code(404);
exit('Archivo PDF no encontrado. Vuelve a subir el PDF desde el editor.');
