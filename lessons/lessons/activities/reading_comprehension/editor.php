<?php
require_once __DIR__ . '/../../config/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['academic_logged']) && empty($_SESSION['admin_logged'])) {
    http_response_code(403);
    exit('Access denied');
}

$params = $_GET;
$params['mode'] = 'edit';
header('Location: viewer.php?' . http_build_query($params));
exit;
