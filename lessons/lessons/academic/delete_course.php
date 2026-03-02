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

$courseId = filter_input(INPUT_POST, "id", FILTER_VALIDATE_INT);
$returnTo = $_POST["return_to"] ?? "courses_manager.php?program=prog_technical";

/* Evitar redirecciones externas */
if (!$returnTo || preg_match('/^https?:\/\//i', $returnTo)) {
    $returnTo = "courses_manager.php?program=prog_technical";
}

if (!$courseId) {
    header("Location: " . $returnTo);
    exit;
}

try {

    $pdo->beginTransaction();

    /* 1️⃣ Borrar actividades relacionadas */
    $stmt = $pdo->prepare("
        DELETE FROM activities
        WHERE unit_id IN (
            SELECT id FROM units WHERE course_id = :course_id
        )
    ");
    $stmt->execute(["course_id" => $courseId]);

    /* 2️⃣ Borrar unidades */
    $stmt = $pdo->prepare("DELETE FROM units WHERE course_id = :course_id");
    $stmt->execute(["course_id" => $courseId]);

    /* 3️⃣ Borrar niveles (si aplica) */
    $stmt = $pdo->prepare("DELETE FROM levels WHERE course_id = :course_id");
    $stmt->execute(["course_id" => $courseId]);

    /* 4️⃣ Borrar curso / semestre */
    $stmt = $pdo->prepare("DELETE FROM courses WHERE id = :course_id");
    $stmt->execute(["course_id" => $courseId]);

    $pdo->commit();

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log("Error deleting course: " . $e->getMessage());
}

header("Location: " . $returnTo);
exit;
