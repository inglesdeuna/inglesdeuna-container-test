<?php
require_once __DIR__ . '/../../config/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Auth gate — students cannot access editor
if (!isset($_SESSION['academic_id']) && !isset($_SESSION['admin_id'])) {
    http_response_code(403);
    exit('Access denied');
}

// Build redirect to viewer in edit mode, preserving all GET params
$params = $_GET;
$params['mode'] = 'edit';
$query = http_build_query($params);
header('Location: viewer.php?' . $query);
exit;
