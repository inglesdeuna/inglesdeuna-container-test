<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

require __DIR__ . "/../config/db.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: ../admin/dashboard.php");
    exit;
}

$courseId = trim($_POST["id"] ?? "");
$returnTo = trim($_POST["return_to"] ?? "courses_manager.php?program=prog_technical");

if ($returnTo === "" || preg_match('/^https?:\/\//i', $returnTo)) {
    $returnTo = "courses_manager.php?program=prog_technical";
}

if ($courseId === "") {
    header("Location: " . $returnTo);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1) Borrar actividades asociadas a unidades del curso (si la tabla existe)
    try {
        $stmtUnits = $pdo->prepare("SELECT id FROM units WHERE course_id = :course_id");
        $stmtUnits->execute(["course_id" => $courseId]);
        $unitIds = $stmtUnits->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($unitIds)) {
            $placeholders = implode(",", array_fill(0, count($unitIds), "?"));
            $stmtDeleteActivities = $pdo->prepare("DELETE FROM activities WHERE unit_id IN ($placeholders)");
            $stmtDeleteActivities->execute($unitIds);
        }
    } catch (Throwable $e) {
        // noop
    }

    // 2) Borrar unidades del curso
    try {
        $stmtDeleteUnits = $pdo->prepare("DELETE FROM units WHERE course_id = :course_id");
        $stmtDeleteUnits->execute(["course_id" => $courseId]);
    } catch (Throwable $e) {
        // noop
    }

    // 3) Borrar niveles del curso (si aplica en este entorno)
    try {
        $stmtDeleteLevels = $pdo->prepare("DELETE FROM levels WHERE course_id = :course_id");
        $stmtDeleteLevels->execute(["course_id" => $courseId]);
    } catch (Throwable $e) {
        // noop
    }

    // 4) Borrar curso/semestre
    $stmtDeleteCourse = $pdo->prepare("DELETE FROM courses WHERE id = :course_id");
    $stmtDeleteCourse->execute(["course_id" => $courseId]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("delete_course.php error: " . $e->getMessage());
}

header("Location: " . $returnTo);
exit;
