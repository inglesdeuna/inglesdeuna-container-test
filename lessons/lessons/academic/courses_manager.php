<?php
session_start();

/**
 * COURSES MANAGER
 * Maneja Técnico e Inglés
 */

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
  header("Location: ../admin/login.php");
  exit;
}

require __DIR__ . "/../config/db.php";

$programId = $_GET["program"] ?? null;

if (!$programId) {
  die("Programa no especificado.");
}

/* ==========================
   PROGRAMA TÉCNICO
   ========================== */
if ($programId === "prog_technical") {

  $programName = "Programa Técnico";

  $semesters = [
    ["id" => "semester_1", "name" => "SEMESTRE 1"],
    ["id" => "semester_2", "name" => "SEMESTRE 2"],
    ["id" => "semester_3", "name" => "SEMESTRE 3"],
    ["id" => "semester_4", "name" => "SEMESTRE 4"],
  ];

  ?>
  <!DOCTYPE html>
  <html lang="es">
  <head>
  <meta charset="UTF-8">
  <title>Programa Técnico</title>
  <style>
  body{font-family:Arial;background:#f4f8ff;padding:40px}
  h1{color:#2563eb}
  .card{background:#fff;padding:25px;border-radius:12px;margin-bottom:25px;max-width:600px}
  .course{background:#fff;padding:15px;border-radius:10px;margin-bottom:10px;display:flex;justify-content:space-between;box-shadow:0 4px 8px rgba(0,0,0,.08)}
  a{text-decoration:none;color:#2563eb;font-weight:bold}
  </style>
  </head>
  <body>

  <h1>📘 Cursos — <?= $programName ?></h1>

  <div class="card">
    <h2>📋 Semestres</h2>

    <?php foreach ($semesters as $s): ?>
      <div class="course">
        <strong><?= $s["name"] ?></strong>
        <a class="course-item"
   href="units_manager.php?course=<?= urlencode($course['id']); ?>">
      </div>
    <?php endforeach; ?>

  </div>

  </body>
  </html>
  <?php
  exit;
}

/* ==========================
   CURSOS DE INGLÉS (NORMAL)
   ========================== */

$programName = "Cursos de Inglés";

/* CREAR CURSO */
if ($_SERVER["REQUEST_METHOD"] === "POST" && !empty($_POST["course_name"])) {

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

/* LISTAR CURSOS */
$stmt = $pdo->prepare("
  SELECT * FROM courses
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
<title>Cursos</title>
<style>
body{font-family:Arial;background:#f4f8ff;padding:40px}
h1{color:#2563eb}
.card{background:#fff;padding:25px;border-radius:12px;margin-bottom:25px;max-width:600px}
.course{background:#fff;padding:15px;border-radius:10px;margin-bottom:10px;display:flex;justify-content:space-between;box-shadow:0 4px 8px rgba(0,0,0,.08)}
a{text-decoration:none;color:#2563eb;font-weight:bold}
input{width:100%;padding:12px;margin-top:10px}
button{margin-top:15px;padding:12px 18px;background:#2563eb;color:#fff;border:none;border-radius:8px;font-weight:700}
</style>
</head>
<body>

<h1>📘 Cursos — <?= $programName ?></h1>

<div class="card">
  <?php if ($programId !== 'prog_technical'): ?>
<div class="card">
  <h2>➕ Crear curso</h2>

  <form method="post">
    <input type="text" 
           name="course_name" 
           placeholder="Ej: Phase 1" 
           required>

    <button type="submit" name="create_course">
      Crear curso
    </button>
  </form>
</div>
<?php endif; ?>
  <form method="post">
    <input type="text" name="course_name" required placeholder="Ej: Phase 1">
    <button>Crear curso</button>
  </form>
</div>

<div class="card">
  <h2>📋 Cursos creados</h2>

  <?php if (empty($programCourses)): ?>
    <p>No hay cursos creados.</p>
  <?php else: ?>
    <?php foreach ($programCourses as $c): ?>
      <div class="course">
        <strong><?= htmlspecialchars($c["name"]) ?></strong>
        <a href="course_view.php?course=<?= urlencode($c["id"]) ?>">Abrir →</a>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

</div>

</body>
</html>
