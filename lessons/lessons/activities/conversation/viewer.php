<?php
/**
 * Compatibility wrapper for older Conversation activity records.
 *
 * Some student-course records may store the activity type as `conversation`,
 * while the real implementation lives in `free_conversation/`.
 * student_course.php resolves viewers by folder name, so this wrapper makes
 * those older records open the current Free Conversation viewer from the
 * Student Dashboard without duplicating the React/Claude code.
 */

$query = $_SERVER['QUERY_STRING'] ?? '';
$target = '../free_conversation/viewer.php';
if ($query !== '') {
    $target .= '?' . $query;
}

header('Location: ' . $target, true, 302);
exit;
