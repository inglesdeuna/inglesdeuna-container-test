<?php
session_start();

if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header('Location: ../admin/login.php');
    exit;
}

require '../config/db.php';

$id = $_GET['id'] ?? '';

if ($id !== '') {
    // Primero desvincular estudiantes de este docente
    $pdo->prepare("DELETE FROM student_assignments WHERE teacher_id = :id")
        ->execute(['id' => $id]);

    // Eliminar asignaciones de cursos/unidades del docente
    $pdo->prepare("DELETE FROM teacher_assignments WHERE teacher_id = :id")
        ->execute(['id' => $id]);

    // Eliminar cuenta de acceso
    $pdo->prepare("DELETE FROM teacher_accounts WHERE teacher_id = :id")
        ->execute(['id' => $id]);

    // Eliminar registro principal
    $pdo->prepare("DELETE FROM teachers WHERE id = :id")
        ->execute(['id' => $id]);
}

header("Location: teacher_groups.php");
