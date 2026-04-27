<?php
// lessons/lessons/core/bootstrap.php
// Shared PHP bootstrap for all activities and editors

// Start output buffering immediately
if (ob_get_level() === 0) {
    ob_start();
}

// Start session safely
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// (Optional) Load global config here if needed, e.g.:
// require_once __DIR__ . '/config.php';
