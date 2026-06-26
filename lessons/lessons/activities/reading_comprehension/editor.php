<?php
/**
 * reading_comprehension/editor.php
 * Ruta: lessons/lessons/activities/reading_comprehension/editor.php
 *
 * Este archivo es SOLO un redirect con auth gate.
 * Todo el editor visual vive en viewer.php (source=creator).
 *
 * Important: do not force mode=edit here. The shared viewer template treats
 * mode=edit as the teacher-assignment flow and sends Back to teacher_unit.php.
 * Reading Comprehension is opened from the unit hub, so source=creator keeps
 * the visual editor active while allowing the Back button to return to the hub.
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

// Reenviar todos los GET params al viewer como editor del hub/creator.
$params = $_GET;
unset($params['mode']);
$params['source'] = 'creator';

if (!isset($params['return_to']) && !empty($params['unit'])) {
    $params['return_to'] = '../../academic/unit_view.php?unit=' . (string) $params['unit'];
}

$query = http_build_query($params);

header('Location: viewer.php?' . $query);
exit;
