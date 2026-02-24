<?php
session_start();

if (!isset($_SESSION["admin_logged"]) || $_SESSION["admin_logged"] !== true) {
  header("Location: ../admin/login.php");
  exit;
}

require __DIR__ . "/../config/db.php";

/* ===============================
   VALIDAR FASE
=============================== */
$phaseId = $_GET["phase"] ?? null;

if (!$phaseId) {
  die("Fase no especificada");
}

/* ===============================
   OBTENER DATOS DE LA FASE
=============================== */
$stmtPhase = $pdo->prepare("
  SELECT * FROM courses
  WHERE id = :id
  AND program_id = 'prog_english_courses'
");
$stmtPhase->execute(["id" => $phaseId]);
$phase = $stmtPhase->fetch(PDO::FETCH_ASSOC);

if (!$phase) {
  die("Fase no válida");
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
    "course_id" => $phaseId
  ]);

  header("Location: english_levels.php?phase=" . urlencode($phaseId));
  exit;
}

/* ===============================
   LISTAR NIVELES
=============================== */
$stmtLevels = $pdo->prepare("
  SELECT * FROM levels
  WHERE course_id = :course
  ORDER BY created_at ASC
");

$stmtLevels->execute([
  "course" => $phaseId
]);

$levels = $stmtLevels->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Niveles — <?= htmlspecialchars($phase["name"]) ?></title>
<style>
body{font-family:Arial;background:#f4f8ff;padding:40px}
h1{color:#2563eb;margin-bottom:25px}
.card{background:#fff;padding:25px;border-radius:12px;margin-bottom:25px;max-width:700px}
.item{background:#fff;padding:15px;border-radius:10px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;box-shadow:0 4px 8px rgba(0,0,0,.08)}
a{text-decoration:none;color:#2563eb;font-weight:bold}
input{width:100%;padding:12px;margin-top:10px}
button{margin-top:15px;padding:12px 18px;background:#2563eb;color:#fff;border:none;border-radius:8px;font-weight:700}
.back{display:inline-block;margin-bottom:20px;background:#6b7280;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none}
</style>
</head>
<body>

<a class="back" href="english_phases.php">← Volver a fases</a>

<h1>📘 <?= htmlspecialchars($phase["name"]) ?> — Niveles</h1>

<div class="card">
  <h2>➕ Crear nivel</h2>
  <form method="post">
    <input type="text" name="level_name" required placeholder="Ej: Basic 1">
    <button>Crear nivel</button>
  </form>
</div>

<div class="card">
  <h2>📋 Niveles creados</h2>

  <?php if (empty($levels)): ?>
    <p>No hay niveles creados.</p>
  <?php else: ?>
    <?php foreach ($levels as $lvl): ?>
      <div class="item">
        <strong><?= htmlspecialchars($lvl["name"]) ?></strong>
        <a href="english_units.php?level=<?= urlencode($lvl["id"]) ?>">
          Entrar →
        </a>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

</div>

</body>
</html>
