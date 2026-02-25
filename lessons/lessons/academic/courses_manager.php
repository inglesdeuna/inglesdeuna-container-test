<?php
session_start();

/**
 * COURSES MANAGER
 * Maneja estructura para:
 * - Cursos de Inglés
 * - Programa Técnico
 */

// 🔐 SOLO ADMIN
if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
    header("Location: ../admin/login.php");
    exit;
}

/* ===============================
   DB CONNECTION
   =============================== */
require __DIR__ . "/../config/db.php";

/* ===============================
   VALIDAR PROGRAMA
   =============================== */
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

$isTechnical = ($programId === "prog_technical");

/* ===============================
   CREAR CURSO (SOLO INGLÉS)
   =============================== */
if (!$isTechnical && $_SERVER["REQUEST_METHOD"] === "POST" && !empty($_POST["course_name"])) {

    $courseId = uniqid("course_");

    $stmt = $pdo->prepare("
        INSERT INTO courses (id, program_id, name)
        VALUES (:id, :program_id, :name)
    ");

    $stmt->execute([
        "id" => $courseId,
        "program_id" => $programId,
        "name" => trim($_POST["course_name"])
    ]);

    header("Location: courses_manager.php?program=" . urlencode($programId));
    exit;
}

/* ===============================
   LISTAR CURSOS
   =============================== */
$stmt = $pdo->prepare("
    SELECT id, name
    FROM courses
    WHERE program_id = :program
    ORDER BY name ASC
");

$stmt->execute(["program" => $programId]);

$programCourses = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($programName) ?></title>

<style>
body{
    font-family:Arial;
    background:#f4f8ff;
    padding:40px;
}
h1{
    color:#2563eb;
}
.card{
    background:#fff;
    padding:25px;
    border-radius:12px;
    margin-bottom:25px;
    max-width:600px;
}
.course{
    background:#ffffff;
    padding:15px;
    border-radius:10px;
    margin-bottom:10px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    box-shadow:0 4px 8px rgba(0,0,0,.08);
}
.course a{
    text-decoration:none;
    color:#2563eb;
    font-weight:bold;
}
input{
    width:100%;
    padding:12px;
    margin-top:10px;
}
button{
    margin-top:15px;
    padding:12px 18px;
    background:#2563eb;
    color:#fff;
    border:none;
    border-radius:8px;
    font-weight:700;
    cursor:pointer;
}
.back{
    display:inline-block;
    margin-bottom:30px;
    text-decoration:none;
    font-weight:bold;
    color:#555;
}
</style>
</head>

<body>

<a class="back" href="../admin/dashboard.php">← Volver al Dashboard</a>

<h1>📘 <?= htmlspecialchars($programName) ?></h1>

<?php if (!$isTechnical): ?>
<div class="card">
    <h2>➕ Crear curso</h2>
    <form method="post">
        <input type="text" name="course_name" required placeholder="Ej: Phase 1">
        <button>Crear curso</button>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <h2><?= $isTechnical ? "Semestres" : "Cursos creados" ?></h2>

    <?php if (empty($programCourses)): ?>
        <p>No hay registros.</p>
    <?php else: ?>

        <?php foreach ($programCourses as $course): ?>

            <div class="course">
                <strong><?= htmlspecialchars($course["name"]) ?></strong>

                <?php if ($isTechnical): ?>
                    <a href="units_manager.php?course=<?= urlencode($course["id"]) ?>">
                        Abrir →
                    </a>
                <?php else: ?>
                    <a href="course_view.php?course=<?= urlencode($course["id"]) ?>">
                        Abrir →
                    </a>
                <?php endif; ?>

            </div>

        <?php endforeach; ?>

    <?php endif; ?>

</div>

</body>
</html>
