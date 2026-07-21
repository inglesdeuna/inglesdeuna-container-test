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

// Block student access to the editor.
if (!empty($_SESSION['student_logged'])) {
    header('Location: /lessons/lessons/academic/student_dashboard.php?error=access_denied');
    exit;
}

// Use the same session flags as the rest of the activity editors.
// The previous check used academic_id/admin_id, but the platform login stores
// academic_logged/admin_logged, causing valid teacher/admin sessions to receive
// a plain 403 "Access denied" response.
$isLoggedIn = !empty($_SESSION['academic_logged']) || !empty($_SESSION['admin_logged']);
if (!$isLoggedIn) {
    header('Location: /lessons/lessons/academic/login.php');
    exit;
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
