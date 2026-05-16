<?php
if (session_status() === PHP_SESSION_NONE) session_start();

$isLoggedIn = !empty($_SESSION['academic_logged']) || !empty($_SESSION['admin_logged']);
if (!$isLoggedIn) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../core/cloudinary_upload.php';

header('Content-Type: application/json');

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'No file received']);
    exit;
}

$allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
$mime    = mime_content_type($_FILES['file']['tmp_name']);
if (!in_array($mime, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'File type not allowed']);
    exit;
}

$url = upload_to_cloudinary($_FILES['file']['tmp_name']);
if (!$url) {
    http_response_code(500);
    echo json_encode(['error' => 'Upload failed']);
    exit;
}

echo json_encode(['url' => $url]);
