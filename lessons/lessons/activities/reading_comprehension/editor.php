<?php
require_once __DIR__ . '/../../config/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['academic_logged']) && empty($_SESSION['admin_logged'])) {
    http_response_code(403);
    exit('Access denied');
}

$_GET['mode'] = 'edit';
require __DIR__ . '/viewer.php';
