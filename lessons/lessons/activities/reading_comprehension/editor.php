<?php
require_once __DIR__ . '/../../config/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['academic_id']) && !isset($_SESSION['admin_id'])) {
    http_response_code(403);
    exit('Access denied');
}

$params = $_GET;
$params['mode'] = 'edit';
header('Location: viewer.php?' . http_build_query($params));
exit;
