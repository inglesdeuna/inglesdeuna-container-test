<?php
/**
 * quiz_creator.php
 * Legacy entrypoint. The exam-from-zero flow now uses quiz_from_scratch.php,
 * which creates/edit exams through scoreable activity editors instead of the
 * old manual question modal.
 */
session_start();

$examId = isset($_GET['exam_id']) ? (int) $_GET['exam_id'] : 0;
$unitId = isset($_GET['unit']) ? trim((string) $_GET['unit']) : '';

$params = [];
if ($examId > 0) {
    $params['mode'] = 'edit';
    $params['exam_id'] = (string) $examId;
}
if ($unitId !== '') {
    $params['unit'] = $unitId;
}
if (isset($_GET['msg']) && $_GET['msg'] !== '') {
    $params['msg'] = (string) $_GET['msg'];
}

$target = 'quiz_from_scratch.php' . ($params ? '?' . http_build_query($params) : '');
header('Location: ' . $target);
exit;
