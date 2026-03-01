<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

require __DIR__ . "/../config/db.php";

$programSlug = $_GET["program"] ?? null;

if (!$programSlug) {
    die("Programa no especificado.");
}

/* ===============================
   OBTENER PROGRAMA
=============================== */
$stmtProgram = $pdo->prepare("
    SELECT * FROM programs
    WHERE slug = :slug
    LIMIT 1
");

$stmtProgram->execute(["slug" => $programSlug]);
$program = $stmtProgram->fetch(PDO::FETCH_ASSOC);

if (!$program) {
    die("Programa no encontrado.");
}

$programId = $program["id"];

/* ===============================
   DEFINIR CONFIGURACIÓN SEGÚN PROGRAMA
=============================== */

$allowCreate = false;

if ($programSlug === "prog_technical") {
    $tituloCrear = "Crear Semestre";
    $tituloLista = "Semestres creados";
    $placeholder = "Ej: SEMESTRE 1";
    $allowCreate = true; // SOLO técnico puede crear
} 
elseif ($programSlug === "prog_english_courses") {
    $tituloCrear = "Crear Curso";
    $tituloLista = "Cursos creados";
    $placeholder = "";
    $allowCreate = false; // Inglés NO crea aquí
} 
else {
    die("Programa inválido.");
}

/* ===============================
   CREAR (SOLO SI ESTÁ PERMITIDO)
=============================== */
if ($allowCreate && $_SERVER["REQUEST_METHOD"] === "POST" && !empty($_POST["name"])) {

    $name = strtoupper(trim($_POST["name"]));

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

    if (!$check->fetch()) {

        $insert = $pdo->prepare("
            INSERT INTO courses (program_id, name)
            VALUES (:program_id, :name)
        ");

        $insert->execute([
            "program_id" => $programId,
            "name" => $name
        ]);
    }

    header("Location: courses_manager.php?program=" . urlencode($programSlug));
    exit;
}

/* ===============================
   ELIMINAR CURSO / SEMESTRE
=============================== */
if ($allowCreate && $_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["delete_course_id"])) {

    $courseIdToDelete = (int) $_POST["delete_course_id"];

    $pdo->beginTransaction();

    try {
        $stmtUnitIds = $pdo->prepare("\n            SELECT id FROM units\n            WHERE course_id = :course_id\n        ");

        $stmtUnitIds->execute(["course_id" => $courseIdToDelete]);
        $unitIds = $stmtUnitIds->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($unitIds)) {
            $placeholders = implode(",", array_fill(0, count($unitIds), "?"));
            $stmtDeleteActivities = $pdo->prepare("DELETE FROM activities WHERE unit_id IN ($placeholders)");
            $stmtDeleteActivities->execute($unitIds);

            $stmtDeleteUnits = $pdo->prepare("DELETE FROM units WHERE id IN ($placeholders)");
            $stmtDeleteUnits->execute($unitIds);
        }

        $stmtDeleteLevels = $pdo->prepare("DELETE FROM levels WHERE course_id = :course_id");
        $stmtDeleteLevels->execute(["course_id" => $courseIdToDelete]);

        $stmtDeleteCourse = $pdo->prepare("\n            DELETE FROM courses\n            WHERE id = :course_id AND program_id = :program_id\n        ");

        $stmtDeleteCourse->execute([
            "course_id" => $courseIdToDelete,
            "program_id" => $programId,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    header("Location: courses_manager.php?program=" . urlencode($programSlug));
    exit;
}

/* ===============================
   LISTAR
=============================== */
$stmtCourses = $pdo->prepare("
    SELECT * FROM courses
    WHERE program_id = :program_id
    ORDER BY id ASC
");

$stmtCourses->execute(["program_id" => $programId]);
$courses = $stmtCourses->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($program["name"]) ?></title>

<style>
body {
    font-family: Arial;
    background: #f4f8ff;
    padding: 40px;
}
@@ -141,81 +186,108 @@ button {
    border: none;
    border-radius: 8px;
    font-weight: bold;
    cursor: pointer;
}

.item {
    background: #eef2ff;
    padding: 15px;
    border-radius: 10px;
    margin-bottom: 10px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.btn {
    background: #2563eb;
    color: #fff;
    padding: 8px 14px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: bold;
}

.btn-danger {
    background: #dc2626;
}

.actions {
    display: flex;
    gap: 10px;
    align-items: center;
}

.inline-form {
    margin: 0;
}

.inline-form button {
    margin-top: 0;
}

.back {
    display: inline-block;
    margin-bottom: 25px;
    background: #6b7280;
    color: #fff;
    padding: 10px 16px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: bold;
}
</style>
</head>

<body>

<a class="back" href="../admin/dashboard.php">Volver</a>

<?php if ($allowCreate): ?>
<div class="card">
    <h2>➕ <?= $tituloCrear ?></h2>

    <form method="POST">
        <input type="text" name="name" required placeholder="<?= $placeholder ?>">
        <button type="submit">Crear</button>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <h2>📋 <?= $tituloLista ?></h2>

    <?php if (empty($courses)): ?>
        <p>No hay registros creados.</p>
    <?php else: ?>
        <?php foreach ($courses as $course): ?>
            <div class="item">
                <strong><?= htmlspecialchars($course["name"]) ?></strong>

                <?php
if ($programSlug === "prog_technical") {
    $adminLink = "technical_units.php?course=" . urlencode($course["id"]);
} else {
    $adminLink = "english_units.php?course=" . urlencode($course["id"]);
}
?>

<a class="btn" href="<?= $adminLink ?>">
    Administrar →
</a>
<div class="actions">
    <a class="btn" href="<?= $adminLink ?>">
        Administrar →
    </a>

    <?php if ($allowCreate): ?>
        <form class="inline-form" method="POST" onsubmit="return confirm('¿Eliminar este semestre?');">
            <input type="hidden" name="delete_course_id" value="<?= (int) $course["id"] ?>">
            <button type="submit" class="btn btn-danger">Eliminar</button>
        </form>
    <?php endif; ?>
</div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

</body>
</html>
