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
$returnTo = $_POST["return_to"] ?? "courses_manager.php?program=prog_technical";

if ($courseId === "") {
    header("Location: " . $returnTo);
    exit;
}
try {
    $pdo->beginTransaction();

    $stmtUnits = $pdo->prepare("SELECT id FROM units WHERE course_id = :course_id");
    $stmtUnits->execute(["course_id" => $courseId]);
    $unitIds = $stmtUnits->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($unitIds)) {
        $placeholders = implode(",", array_fill(0, count($unitIds), "?"));

        $stmtDeleteActivities = $pdo->prepare("DELETE FROM activities WHERE unit_id IN ($placeholders)");
        $stmtDeleteActivities->execute($unitIds);

        $stmtDeleteUnits = $pdo->prepare("DELETE FROM units WHERE id IN ($placeholders)");
        $stmtDeleteUnits->execute($unitIds);
    }

    $stmtDeleteLevels = $pdo->prepare("DELETE FROM levels WHERE course_id = :course_id");
    $stmtDeleteLevels->execute(["course_id" => $courseId]);

    $stmtDeleteCourse = $pdo->prepare("DELETE FROM courses WHERE id = :course_id");
    $stmtDeleteCourse->execute(["course_id" => $courseId]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

header("Location: " . $returnTo);
exit;
