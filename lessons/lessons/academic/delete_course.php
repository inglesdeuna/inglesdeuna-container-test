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

// Evita redirecciones externas.
if ($returnTo === "" || preg_match('/^https?:\/\//i', $returnTo)) {
    $returnTo = "courses_manager.php?program=prog_technical";
}

if ($courseId === "") {
    header("Location: " . $returnTo);
    exit;
}

/**
 * Verifica si una tabla existe en la BD actual.
 */
function tableExists(PDO $pdo, string $tableName): bool
{
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table LIMIT 1");
        $stmt->execute(["table" => $tableName]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

try {
    $pdo->beginTransaction();

    $unitIds = [];

    if (tableExists($pdo, "units")) {
        $stmtUnits = $pdo->prepare("SELECT id FROM units WHERE course_id = :course_id");
        $stmtUnits->execute(["course_id" => $courseId]);
        $unitIds = $stmtUnits->fetchAll(PDO::FETCH_COLUMN);
    }

    if (!empty($unitIds) && tableExists($pdo, "activities")) {
        $placeholders = implode(",", array_fill(0, count($unitIds), "?"));
        $stmtDeleteActivities = $pdo->prepare("DELETE FROM activities WHERE unit_id IN ($placeholders)");
        $stmtDeleteActivities->execute($unitIds);
    }

    if (tableExists($pdo, "units")) {
        $stmtDeleteUnits = $pdo->prepare("DELETE FROM units WHERE course_id = :course_id");
        $stmtDeleteUnits->execute(["course_id" => $courseId]);
    }

    if (tableExists($pdo, "levels")) {
        $stmtDeleteLevels = $pdo->prepare("DELETE FROM levels WHERE course_id = :course_id");
        $stmtDeleteLevels->execute(["course_id" => $courseId]);
    }

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
