<?php
require_once __DIR__ . '/../../config/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Auth gate — students cannot access editor
if (empty($_SESSION['academic_logged']) && empty($_SESSION['admin_logged'])) {
    http_response_code(403);
    exit('Access denied');
}

// Build redirect to viewer in edit mode, preserving all GET params
$params        = $_GET;
$params['mode'] = 'edit';
$query         = http_build_query($params);
header('Location: viewer.php?' . $query);
exit;
