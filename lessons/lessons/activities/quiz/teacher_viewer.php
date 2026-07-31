<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';

if (!isset($_SESSION['academic_logged']) || $_SESSION['academic_logged'] !== true) {
    header('Location: /lessons/lessons/academic/login.php');
    exit;
}

$assignmentId = trim((string) ($_GET['assignment'] ?? ''));
$unitId = trim((string) ($_GET['unit'] ?? ''));
$teacherId = trim((string) ($_SESSION['teacher_id'] ?? ''));

if ($assignmentId === '' || $unitId === '' || $teacherId === '') {
    http_response_code(400);
    exit('Missing teacher, assignment, or unit information.');
}

$stmt = $pdo->prepare(
    'SELECT id
       FROM teacher_assignments
      WHERE id = :assignment
        AND teacher_id = :teacher
      LIMIT 1'
);
$stmt->execute([
    'assignment' => $assignmentId,
    'teacher' => $teacherId,
]);

if (!$stmt->fetchColumn()) {
    http_response_code(403);
    exit('You do not have permission to open this quiz.');
}

// The dashboard uses the canonical unit quiz. These globals only select the
// teacher persistence/session adapter; all markup, activity views, scoring,
// navigation, feedback, and retry behavior come from viewer.php.
$GLOBALS['qzTeacherContext'] = true;
$GLOBALS['qzTeacherId'] = $teacherId;

require __DIR__ . '/viewer.php';
