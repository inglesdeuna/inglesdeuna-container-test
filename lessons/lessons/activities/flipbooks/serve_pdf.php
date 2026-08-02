<?php
declare(strict_types=1);

/**
 * serve_pdf.php
 * Serves Flipbook PDFs from database, local legacy storage, or remote storage.
 * Remote PDFs are proxied in the same request so browser downloads do not fail
 * after a redirect.
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/init_db.php';

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

function log_pdf_failure(string $activityId, string $pdfUrl, string $reason, ?string $resolvedPath = null): void
{
    error_log(sprintf(
        'flipbook: PDF serve failure (activity_id=%s, storage_url=%s, resolved_path=%s, reason=%s)',
        $activityId,
        $pdfUrl !== '' ? $pdfUrl : '(empty)',
        $resolvedPath !== null && $resolvedPath !== '' ? $resolvedPath : '(none)',
        $reason
    ));
}

function load_pdf_data(PDO $pdo, string $activityId)
{
    try {
        $stmt = $pdo->prepare('SELECT pdf_data FROM activities WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $activityId);
        $stmt->execute();
        $stmt->bindColumn('pdf_data', $lob, PDO::PARAM_LOB);
        if ($stmt->fetch(PDO::FETCH_BOUND)) {
            return $lob;
        }
    } catch (Throwable $e) {
        error_log(sprintf(
            'flipbook: database PDF read failed (activity_id=%s, reason=%s)',
            $activityId,
            $e->getMessage()
        ));
        return null;
    }

    return null;
}

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$forceDownload = isset($_GET['dl']) && $_GET['dl'] === '1';

if ($activityId === '') {
    http_response_code(400);
    exit('ID de actividad requerido.');
}

try {
    $stmt = $pdo->prepare('SELECT id, unit_id, data FROM activities WHERE id = :id LIMIT 1');
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

$resolvedActivityId = (string) ($row['id'] ?? $activityId);

$data = json_decode((string) ($row['data'] ?? ''), true);
if (!is_array($data)) {
    $data = [];
    log_pdf_failure($resolvedActivityId, '', 'invalid activity data JSON');
}

$pdfUrl = isset($data['pdf_url']) ? trim((string) $data['pdf_url']) : '';
$downloadName = safe_pdf_filename(
    isset($data['pdf_filename']) && trim((string) $data['pdf_filename']) !== ''
        ? (string) $data['pdf_filename']
        : $pdfUrl
);

if ($pdfUrl === '') {
    $storedPdf = load_pdf_data($pdo, $resolvedActivityId);
    if ($storedPdf !== null && $storedPdf !== false && $storedPdf !== '') {
        output_pdf_binary($storedPdf, $downloadName, $forceDownload);
    }

    log_pdf_failure($resolvedActivityId, $pdfUrl, 'missing pdf_url and no non-empty pdf_data');
    http_response_code(404);
    exit('No hay PDF guardado para esta actividad. Un administrador puede subir un PDF de reemplazo desde el editor.');
}

if (str_starts_with($pdfUrl, 'db-pdf://')) {
    $storedPdf = load_pdf_data($pdo, $resolvedActivityId);
    if ($storedPdf === null || $storedPdf === false || $storedPdf === '') {
        log_pdf_failure($resolvedActivityId, $pdfUrl, 'db-pdf reference has no non-empty pdf_data');
        http_response_code(404);
        exit('No se encontró el PDF almacenado. Un administrador puede subir un PDF de reemplazo desde el editor.');
    }
    output_pdf_binary($storedPdf, $downloadName, $forceDownload);
}

if (str_starts_with($pdfUrl, 'data:application/pdf;base64,')) {
    $base64 = substr($pdfUrl, strlen('data:application/pdf;base64,'));
    $binary = base64_decode($base64, true);

    if ($binary === false) {
        log_pdf_failure($resolvedActivityId, $pdfUrl, 'invalid base64 PDF data');
        http_response_code(500);
        exit('Error al decodificar el PDF.');
    }

    output_pdf_binary($binary, $downloadName, $forceDownload);
}

$localPdfPath = resolve_local_pdf_path($pdfUrl);
if ($localPdfPath !== null) {
    header('Content-Type: application/pdf');
    header('Content-Length: ' . filesize($localPdfPath));
    header('Content-Disposition: ' . ($forceDownload ? 'attachment' : 'inline') . '; filename="' . $downloadName . '"');
    header('Cache-Control: private, max-age=3600');
    readfile($localPdfPath);
    exit;
}

/*
 * Do not redirect remote downloads. Chrome can mark an <a download> request
 * as unavailable when serve_pdf.php responds with a 302. Run the proxy in the
 * same request and let it return the PDF bytes directly.
 */
if (preg_match('/^https?:\/\//i', $pdfUrl)) {
    $_GET['url'] = $pdfUrl;
    if ($forceDownload) {
        $_GET['dl'] = '1';
    } else {
        unset($_GET['dl']);
    }

    require __DIR__ . '/pdf_proxy.php';
    exit;
}

$storedPdf = load_pdf_data($pdo, $resolvedActivityId);
if ($storedPdf !== null && $storedPdf !== false && $storedPdf !== '') {
    output_pdf_binary($storedPdf, $downloadName, $forceDownload);
}

log_pdf_failure(
    $resolvedActivityId,
    $pdfUrl,
    'unsupported URL or local file missing (filesystem may be ephemeral)',
    $localPdfPath
);
http_response_code(404);
exit('Archivo PDF no encontrado. Un administrador puede subir un PDF de reemplazo desde el editor.');
