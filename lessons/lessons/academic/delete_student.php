<?php
session_start();

if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header('Location: ../admin/login.php');
    exit;
}

require '../config/db.php';

$id = trim((string) ($_GET['id'] ?? ''));

if ($id !== '') {
    $pdo->prepare("DELETE FROM student_activity_results WHERE student_id = :id")->execute(['id' => $id]);
    $pdo->prepare("DELETE FROM student_unit_results WHERE student_id = :id")->execute(['id' => $id]);
    $pdo->prepare("DELETE FROM teacher_quiz_unlocks WHERE student_id = :id")->execute(['id' => $id]);
    $pdo->prepare("DELETE FROM student_assignments WHERE student_id = :id")->execute(['id' => $id]);
    $pdo->prepare("DELETE FROM student_accounts WHERE student_id = :id")->execute(['id' => $id]);
    $pdo->prepare("DELETE FROM students WHERE id = :id")->execute(['id' => $id]);
}

header("Location: student_enrollments.php");
