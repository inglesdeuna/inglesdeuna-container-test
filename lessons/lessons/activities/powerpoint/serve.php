<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$isLoggedIn = !empty($_SESSION['academic_logged'])
           || !empty($_SESSION['admin_logged'])
           || !empty($_SESSION['student_logged']);

if (!$isLoggedIn) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/../../config/db.php';

$activityId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
if ($activityId === '') {
    http_response_code(400);
    exit('Bad request');
}

$stmt = $pdo->prepare('SELECT data FROM activities WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $activityId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    http_response_code(404);
    exit('Not found');
}

$data = json_decode((string) $row['data'], true);
$presentationFile = (string) ($data['presentation_file'] ?? '');
$presentationName = (string) ($data['presentation_name'] ?? 'presentation');

if ($presentationFile === '') {
    http_response_code(404);
    exit('No presentation file');
}

// Parse data URL: data:<mime>;base64,<data>
if (!preg_match('/^data:([^;,]+);base64,(.+)$/s', $presentationFile, $matches)) {
    http_response_code(400);
    exit('Invalid data format');
}

$mimeType = $matches[1];
$binary   = base64_decode($matches[2], true);
if ($binary === false) {
    http_response_code(500);
    exit('Decode error');
}

$safeName   = preg_replace('/[^a-zA-Z0-9_\-. ]+/', '_', $presentationName) ?: 'presentation';
$isPdf      = stripos($mimeType, 'pdf') !== false;
$disposition = $isPdf ? 'inline' : 'attachment';

header('Content-Type: ' . $mimeType);
header('Content-Disposition: ' . $disposition . '; filename="' . $safeName . '"');
header('Content-Length: ' . strlen($binary));
header('Cache-Control: private, max-age=3600');
echo $binary;
