<?php
/**
 * reading_comprehension/editor.php
 * Ruta: lessons/lessons/activities/reading_comprehension/editor.php
 *
 * Este archivo es SOLO un redirect con auth gate.
 * Todo el editor visual vive en viewer.php (modo edit).
 * Patron identico a roleplay_kids, drag_drop, question_answer, etc.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';

// Solo docentes y admins pueden editar
if (!isset($_SESSION['academic_id']) && !isset($_SESSION['admin_id'])) {
    http_response_code(403);
    exit('Access denied');
}

// Reenviar todos los GET params al viewer en modo edit
$params        = $_GET;
$params['mode'] = 'edit';
$query         = http_build_query($params);

header('Location: viewer.php?' . $query);
exit;
