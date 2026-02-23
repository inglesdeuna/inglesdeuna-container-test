<?php
session_start();

/**
 * LEVELS MANAGER
 * Gestiona niveles dentro de una Phase (Course)
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
   VALIDAR COURSE (PHASE)
=============================== */
$courseId = $_GET["course"] ?? null;
if (!$courseId) {
  die("Phase no especificada");
}

/* ===============================
   OBTENER COURSE
=============================== */
$stmtCourse = $pdo->prepare("
  SELECT * FROM courses
  WHERE id = :id
");
$stmtCourse->execute(["id" => $courseId]);
$course = $stmtCourse->fetch(PDO::FETCH_ASSOC);

if (!$course) {
  die("Phase no encontrada");
}

/* ===============================
   CREAR NIVEL
=============================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && !empty($_POST["level_name"])) {

  $levelId = uniqid("level_");

  $stmtInsert = $pdo->prepare("
      INSERT INTO levels (id, name, course_id)
      VALUES (:id, :name, :course_id)
  ");

  $stmtInsert->execute([
      "id" => $levelId,
      "name" => trim($_POST["level_name"]),
      "course_id" => $courseId
  ]);

  header("Location: levels_manager.php?course=" . urlencode($courseId));
  exit;
}

/* ===============================
   LISTAR NIVELES
=============================== */
$stmt = $pdo->prepare("
  SELECT * FROM levels
  WHERE course_id = :course
  ORDER BY name ASC
");

$stmt->execute(["course" => $courseId]);
$levels = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Niveles</title>
<style>
body{font-family:Arial;background:#f4f8ff;padding:40px}
h1{color:#2563eb}
.card{background:#fff;padding:25px;border-radius:12px;margin-bottom:25px;max-width:600px}
.level{
  background:#fff;
  padding:15px;
  border-radius:10px;
  margin-bottom:10px;
  display:flex;
  justify-content:space-between;
  align-items:center;
  box-shadow:0 4px 8px rgba(0,0,0,.08)
}
a{text-decoration:none;color:#2563eb;font-weight:bold}
input{width:100%;padding:12px;margin-top:10px}
button{
  margin-top:15px;
  padding:12px 18px;
  background:#16a34a;
  color:#fff;
  border:none;
  border-radius:8px;
  font-weight:700;
  cursor:pointer;
}
button:hover{background:#15803d}
</style>
</head>
<body>

<h1>📗 Niveles — <?= htmlspecialchars($course["name"]) ?></h1>

<div class="card">
  <h2>➕ Crear Nivel</h2>
  <form method="post">
    <input type="text" name="level_name" required placeholder="Ej: Intermediate 3">
    <button>Crear Nivel</button>
  </form>
</div>

<div class="card">
  <h2>📋 Niveles creados</h2>

  <?php if (empty($levels)): ?>
    <p>No hay niveles creados.</p>
  <?php else: ?>
    <?php foreach ($levels as $level): ?>
      <div class="level">
        <strong><?= htmlspecialchars($level["name"]) ?></strong>
        <a href="units_manager.php?level=<?= urlencode($level["id"]) ?>">
          Abrir →
        </a>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

</div>

</body>
</html>
