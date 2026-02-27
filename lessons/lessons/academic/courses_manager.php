<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

require __DIR__ . "/../config/db.php";

$programId = $_GET["program"] ?? null;

if (!$programId) {
    die("Programa no especificado");
}

/* ===============================
   CREAR SEMESTRE (SIN REPETIR)
=============================== */
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST" && !empty($_POST["course_name"])) {

    $name = strtoupper(trim($_POST["course_name"]));

    $validSemesters = ["SEMESTRE 1", "SEMESTRE 2", "SEMESTRE 3", "SEMESTRE 4"];

    if (!in_array($name, $validSemesters)) {
        $error = "Solo se permiten SEMESTRE 1, 2, 3 o 4.";
    } else {

        $check = $pdo->prepare("
            SELECT id FROM courses
            WHERE program_id = :program_id
            AND name = :name
            LIMIT 1
        ");

        $check->execute([
            "program_id" => $programId,
            "name" => $name
        ]);

        if ($check->fetch()) {
            $error = "Ese semestre ya existe.";
        } else {
            // Insertar sin ID, la BD lo genera automáticamente
            $stmt = $pdo->prepare("
                INSERT INTO courses (program_id, name)
                VALUES (:program_id, :name)
            ");

            $stmt->execute([
                "program_id" => $programId,
                "name" => $name
            ]);

            header("Location: courses_manager.php?program=" . urlencode($programId));
            exit;
        }
    }
}

/* ===============================
   LISTAR SEMESTRES
=============================== */
$stmt = $pdo->prepare("
    SELECT id, name
    FROM courses
    WHERE program_id = :program
    ORDER BY id ASC
");

$stmt->execute([
    "program" => $programId
]);

$courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
