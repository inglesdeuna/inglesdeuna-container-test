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
}
elseif ($programSlug === "prog_english_courses") {
    $tituloCrear = "Crear Curso";
    $tituloLista = "Cursos creados";
    $placeholder = "";
    $allowCreate = false; // Inglés NO crea aquí
} 
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

@@ -141,81 +141,113 @@ button {
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

.actions {
    display: flex;
    align-items: center;
    gap: 8px;
}

.inline-form {
    margin: 0;
}

.btn-delete-x {
    margin-top: 0;
    width: 32px;
    height: 32px;
    padding: 0;
    border-radius: 8px;
    background: #dc2626;
    line-height: 1;
    font-size: 20px;
    font-weight: 700;
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
        <form class="inline-form" method="POST" action="delete_course.php" onsubmit="return confirm('¿Eliminar este semestre?');">
            <input type="hidden" name="id" value="<?= htmlspecialchars($course["id"]) ?>">
            <input type="hidden" name="return_to" value="courses_manager.php?program=<?= urlencode($programSlug) ?>">
            <button type="submit" class="btn-delete-x" aria-label="Eliminar">×</button>
        </form>
    <?php endif; ?>
</div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

</body>
</html>
lessons/lessons/academic/delete_course.php
lessons/lessons/academic/delete_course.php
Nuevo
+75
-0

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
lessons/lessons/academic/technical_courses_created.php
lessons/lessons/academic/technical_courses_created.php
+18
-9

@@ -80,85 +80,94 @@ body {
/* TÍTULO */
.card h2 {
    margin-bottom: 25px;
}

/* ITEM FILA */
.row {
    background: #f4f6fb;
    padding: 15px 20px;
    border-radius: 12px;
    margin-bottom: 15px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

/* NOMBRE */
.row strong {
    font-size: 15px;
}

/* BOTONES */
.actions {
    display: flex;
    gap: 10px;
    align-items: center;
}

.btn-view {
    background: #2563eb;
    color: white;
    padding: 8px 14px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 600;
}

.btn-delete {
    background: #dc2626;
    color: white;
    padding: 8px 14px;
.inline-form {
    margin: 0;
}

.btn-delete-x {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    border: none;
    font-weight: 600;
    background: #dc2626;
    color: white;
    font-size: 20px;
    font-weight: 700;
    cursor: pointer;
    line-height: 1;
}
</style>
</head>

<body>

<div class="container">

<a class="back" href="../admin/dashboard.php">← Volver</a>

<div class="card">
    <h2>📘 Semestres creados</h2>

    <?php if (empty($semestres)): ?>
        <p>No hay semestres creados.</p>
    <?php else: ?>
        <?php foreach ($semestres as $sem): ?>
            <div class="row">
                <strong><?= htmlspecialchars($sem["name"]) ?></strong>

                <div class="actions">
                    <a class="btn-view"
                       href="technical_units_view.php?course=<?= $sem["id"] ?>">
                       href="technical_units_view.php?course=<?= urlencode($sem["id"]) ?>">
                        Ver →
                    </a>

                    <form method="POST" action="delete_course.php"
                    <form class="inline-form" method="POST" action="delete_course.php"
                          onsubmit="return confirm('¿Eliminar semestre?');">
                        <input type="hidden" name="id" value="<?= $sem["id"] ?>">
                        <button class="btn-delete">Eliminar</button>
                        <input type="hidden" name="id" value="<?= htmlspecialchars($sem["id"]) ?>">
                        <input type="hidden" name="return_to" value="technical_courses_created.php">
                        <button type="submit" class="btn-delete-x" aria-label="Eliminar">×</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

</div>

</body>
</html>
