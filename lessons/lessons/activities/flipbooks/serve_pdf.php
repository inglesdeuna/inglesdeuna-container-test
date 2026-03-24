<?php
declare(strict_types=1);

/**
 * serve_pdf.php
 * Serves a PDF stored as base64 in the activities.data column.
 * This is necessary because Render's filesystem is ephemeral —
 * files uploaded at runtime are lost on container restart.
 */

require_once __DIR__ . '/../../config/db.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';

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
    header('Content-Disposition: inline; filename="document.pdf"');
    header('Cache-Control: private, max-age=3600');
    echo $binary;
    exit;
}

// Handle legacy local file path (fallback for any previously uploaded files)
if (str_starts_with($pdfUrl, '/')) {
    $localPath = __DIR__ . '/../../../../..' . $pdfUrl;
    $realLocal = realpath($localPath);

    // Security: ensure the resolved path is inside the uploads directory
    $uploadBase = realpath(__DIR__ . '/uploads/pdfs') ?: '';
    if (
        $realLocal !== false &&
        $uploadBase !== '' &&
        str_starts_with($realLocal, $uploadBase) &&
        is_file($realLocal)
    ) {
        header('Content-Type: application/pdf');
        header('Content-Length: ' . filesize($realLocal));
        header('Content-Disposition: inline; filename="document.pdf"');
        header('Cache-Control: private, max-age=3600');
        readfile($realLocal);
        exit;
    }
}

http_response_code(404);
exit('Archivo PDF no encontrado. Vuelve a subir el PDF desde el editor.');
