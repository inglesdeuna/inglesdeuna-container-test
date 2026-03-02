<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

require __DIR__ . "/../config/db.php";

/* ===============================
   OBTENER PROGRAMA INGLÉS
=============================== */
$stmtProgram = $pdo->prepare("
    SELECT * FROM programs
    WHERE slug = :slug
    LIMIT 1
");

$stmtProgram->execute([
    "slug" => "prog_english"
]);

$program = $stmtProgram->fetch(PDO::FETCH_ASSOC);

if (!$program) {
    die("Programa de inglés no encontrado. Verifique slug en tabla programs.");
}

/* ===============================
   OBTENER CURSOS DEL PROGRAMA
=============================== */
$stmt = $pdo->prepare("
    SELECT *
    FROM courses
    WHERE program_id = :program_id
    ORDER BY id ASC
");

$stmt->execute([
    "program_id" => $program["id"]
]);

$courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
