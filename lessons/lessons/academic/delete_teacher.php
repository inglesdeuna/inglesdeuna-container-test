<?php
session_start();

if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header('Location: ../admin/login.php');
    exit;
}

require '../config/db.php';

$id = $_GET['id'] ?? '';

if ($id !== '') {

    $pdo->prepare("DELETE FROM teachers WHERE id = :id")
        ->execute(['id'=>$id]);

    $pdo->prepare("DELETE FROM teacher_accounts WHERE teacher_id = :id")
        ->execute(['id'=>$id]);

    $pdo->prepare("DELETE FROM student_assignments WHERE teacher_id = :id")
        ->execute(['id'=>$id]);
}

header("Location: teacher_groups.php");
