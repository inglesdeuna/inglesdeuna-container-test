<?php
if (session_status() === PHP_SESSION_NONE) session_start();

// Block student access
if (!empty($_SESSION['student_logged']) && $_SESSION['student_logged']) {
    header('Location: /lessons/lessons/academic/student_dashboard.php?error=access_denied');
    exit;
}

$isLoggedIn = !empty($_SESSION['academic_logged']) || !empty($_SESSION['admin_logged']);
if (!$isLoggedIn) {
    header('Location: /lessons/lessons/academic/login.php');
    exit;
}

// The Roleplay component manages its own editor view internally.
// Forward to viewer.php preserving all URL params.
$params = $_SERVER['QUERY_STRING'] ?? '';
// Always force mode=edit so viewer opens EditorView
if ($params === '') {
    $params = 'mode=edit';
} elseif (strpos($params, 'mode=') === false) {
    $params .= '&mode=edit';
} else {
    $params = preg_replace('/mode=[^&]*/', 'mode=edit', $params);
}
header('Location: viewer.php?' . $params);
exit;
