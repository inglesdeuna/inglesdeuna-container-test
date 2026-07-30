<?php
declare(strict_types=1);

/**
 * serve_pdf.php
 * Serves Flipbook PDFs from persistent storage and recovers legacy DB copies.
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

function output_pdf_binary($binary, string $downloadName, bool $forceDownload): void
{
    if ($binary === null || $binary === false) {
        return;
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . ($forceDownload ? 'attachment' : 'inline') . '; filename="' . $downloadName . '"');
    header('Cache-Control: private, max-age=3600');

    if (is_resource($binary)) {
        fpassthru($binary);
        fclose($binary);
    } else {
        $content = (string) $binary;
        header('Content-Length: ' . strlen($content));
        echo $content;
    }
    exit;
}

function load_legacy_pdf_data(PDO $pdo, string $activityId)
{
    try {
        $stmt = $pdo->prepare("SELECT pdf_data FROM activities WHERE id = :id LIMIT 1");
        $stmt->bindValue(':id', $activityId);
        $stmt->execute();
        $stmt->bindColumn('pdf_data', $lob, PDO::PARAM_LOB);
        if ($stmt->fetch(PDO::FETCH_BOUND)) {
            return $lob;
        }
    } catch (Throwable $e) {
        return null;
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
if (!is_array($data)) {
    $data = [];
}

$pdfUrl = isset($data['pdf_url']) ? trim((string) $data['pdf_url']) : '';
$downloadName = safe_pdf_filename(
    (isset($data['pdf_filename']) && $data['pdf_filename'] !== '') ? (string) $data['pdf_filename'] : $pdfUrl
);

// Current persistent database format.
if (str_starts_with($pdfUrl, 'db-pdf://')) {
    $legacyPdf = load_legacy_pdf_data($pdo, $activityId);
    if ($legacyPdf === null || $legacyPdf === false) {
        http_response_code(404);
        exit('No se encontró el PDF almacenado.');
    }
    output_pdf_binary($legacyPdf, $downloadName, $forceDownload);
}

// Legacy base64 format.
if (str_starts_with($pdfUrl, 'data:application/pdf;base64,')) {
    $base64 = substr($pdfUrl, strlen('data:application/pdf;base64,'));
    $binary = base64_decode($base64, true);

    if ($binary === false) {
        http_response_code(500);
        exit('Error al decodificar el PDF.');
    }

    output_pdf_binary($binary, $downloadName, $forceDownload);
}

// Local files only survive until Render redeploys.
$localPdfPath = $pdfUrl !== '' ? resolve_local_pdf_path($pdfUrl) : null;
if ($localPdfPath !== null) {
    header('Content-Type: application/pdf');
    header('Content-Length: ' . filesize($localPdfPath));
    header('Content-Disposition: ' . ($forceDownload ? 'attachment' : 'inline') . '; filename="' . $downloadName . '"');
    header('Cache-Control: private, max-age=3600');
    readfile($localPdfPath);
    exit;
}

// Remote persistent storage.
if (preg_match('/^https?:\/\//i', $pdfUrl)) {
    $proxyUrl = '/lessons/lessons/activities/flipbooks/pdf_proxy.php?url=' . rawurlencode($pdfUrl)
        . ($forceDownload ? '&dl=1' : '');
    header('Location: ' . $proxyUrl, true, 302);
    exit;
}

// Critical recovery path: older records may still contain pdf_data even though
// pdf_url points to a Render-local file that disappeared during deployment.
$legacyPdf = load_legacy_pdf_data($pdo, $activityId);
if ($legacyPdf !== null && $legacyPdf !== false) {
    output_pdf_binary($legacyPdf, $downloadName, $forceDownload);
}

http_response_code(404);
exit('El PDF fue guardado en el almacenamiento temporal de Render y se perdió durante un despliegue. Vuelve a subirlo una sola vez desde Edit activity.');
