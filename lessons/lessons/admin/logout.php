<?php
session_start();

// Load security utilities
require_once __DIR__ . "/../config/security.php";

// Log the logout event
$userId = $_SESSION['admin_id'] ?? 'unknown';
Security::logSecurityEvent('admin_logout', 'User logged out', $userId);

// Destroy session securely
Security::destroySession();

header("Location: login.php");
exit;

