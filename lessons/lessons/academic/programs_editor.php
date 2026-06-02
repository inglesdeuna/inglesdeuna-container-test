<?php
session_start();

/**
 * PROGRAMS EDITOR
 * Redirige directamente según el programa recibido por GET
 */

// 🔐 SOLO ADMIN
if (!isset($_SESSION['admin_logged']) || $_SESSION['admin_logged'] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

/* ==========================
   VALIDAR PROGRAMA
   ========================== */
$programId = $_GET["program"] ?? null;

if (!$programId) {
    die("Programa no especificado.");
}

if ($programId === "prog_english_courses") {
    $programName = "Cursos de Inglés";
} elseif ($programId === "prog_technical") {
    $programName = "Programa Técnico";
} else {
    die("Programa inválido.");
}

/* ==========================
   REDIRECCIONAR
   ========================== */
if ($programId === "prog_english_courses") {
    header("Location: english_structure_levels.php");
    exit;
}

header("Location: courses_manager.php?program=" . urlencode($programId));
exit;
?>
